<?php
declare(strict_types=1);
namespace WPLite;

/** Static configuration extraction. Never include/eval a migrating site's PHP. */
final class CopyConfiguration
{
    public static function render(string $code, string $prefix, string $storage): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix) || !preg_match('/^wplite-data-[a-f0-9]{16}$/D', $storage)) {
            throw new \RuntimeException('invalid_restore_configuration');
        }
        $tokens = array_values(array_filter(token_get_all($code, TOKEN_PARSE), static fn ($token): bool => !is_array($token)
            || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)));
        $text = static fn ($token): string => is_array($token) ? $token[1] : ($token ?? '');
        $i = 0; $constants = []; $tablePrefix = null; $settings = false;
        while ($i < count($tokens)) {
            $token = $tokens[$i]; $name = $text($token);
            if (strtolower($name) === 'define') {
                ++$i; self::expect($tokens, $i, '(');
                $constant = self::literal($tokens, $i);
                self::expect($tokens, $i, ',');
                $value = self::literal($tokens, $i);
                self::expect($tokens, $i, ')'); self::expect($tokens, $i, ';');
                if (!is_string($constant) || !preg_match('/^[A-Z][A-Z0-9_]*$/D', $constant)
                    || array_key_exists($constant, $constants)) { throw new \RuntimeException('unsupported_constant'); }
                $constants[$constant] = $value;
                continue;
            }
            if ($name === '$table_prefix') {
                if ($tablePrefix !== null) { throw new \RuntimeException('duplicate_prefix'); }
                ++$i; self::expect($tokens, $i, '='); $tablePrefix = self::literal($tokens, $i); self::expect($tokens, $i, ';'); continue;
            }
            if (strtolower($name) === 'if') {
                // Only the standard ABSPATH declaration is portable. No custom
                // conditions, function calls, includes, or code are executed.
                $statement = '';
                do { $part = $text($tokens[$i++] ?? null); $statement .= $part; } while ($part !== '}' && $i < count($tokens));
                if (!preg_match('/^if\(!defined\(([\x27\x22])ABSPATH\1\)\)\{define\(([\x27\x22])ABSPATH\2,(?:__DIR__|dirname\(__FILE__\))\.([\x27\x22])\/\3\);\}$/D', $statement)) {
                    throw new \RuntimeException('dynamic_configuration_unsupported');
                }
                continue;
            }
            if (in_array(strtolower($name), ['require', 'require_once'], true)) {
                if ($settings) { throw new \RuntimeException('duplicate_settings_loader'); }
                ++$i; $parentheses = $text($tokens[$i] ?? null) === '(';
                if ($parentheses) { ++$i; }
                self::expect($tokens, $i, 'ABSPATH'); self::expect($tokens, $i, '.');
                if (self::literal($tokens, $i) !== 'wp-settings.php') { throw new \RuntimeException('custom_loader_unsupported'); }
                if ($parentheses) { self::expect($tokens, $i, ')'); }
                self::expect($tokens, $i, ';'); $settings = true; continue;
            }
            throw new \RuntimeException('dynamic_configuration_unsupported');
        }
        if (!$settings || $tablePrefix !== $prefix) { throw new \RuntimeException('configuration_prefix_or_loader_mismatch'); }
        foreach (['WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR', 'CUSTOM_USER_TABLE', 'CUSTOM_USER_META_TABLE'] as $unsupported) {
            if (array_key_exists($unsupported, $constants)) { throw new \RuntimeException('custom_layout_unsupported'); }
        }
        if (!empty($constants['MULTISITE'])) { throw new \RuntimeException('multisite_unsupported'); }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $salt) {
            if (!isset($constants[$salt]) || !is_string($constants[$salt])) { throw new \RuntimeException('missing_authentication_salt'); }
        }
        foreach (array_keys($constants) as $constant) {
            if (str_starts_with($constant, 'WPLITE_')) { throw new \RuntimeException('existing_wplite_configuration'); }
            if ($constant === 'ABSPATH' || str_starts_with($constant, 'DB_')) { unset($constants[$constant]); }
        }
        require_once __DIR__ . '/setup.php';
        // Setup::configSource generates a portable path expression from this
        // directory name; no actual directory is created or inspected here.
        $constants = ['DB_ENGINE' => 'sqlite', 'DB_PATH' => ABSPATH . 'wp-content/' . $storage . '/site.sqlite',
            'DB_NAME' => 'wplite', 'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '',
            'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WPLITE_PUBLIC_ROOT' => ABSPATH,
            'WPLITE_STORAGE_DENY_HASH' => hash('sha256', Setup::DENY)] + $constants;
        return Setup::configSource($constants, $prefix);
    }

    private static function expect(array $tokens, int &$i, string $expected): void
    {
        $token = $tokens[$i++] ?? null;
        if ((is_array($token) ? $token[1] : $token) !== $expected) { throw new \RuntimeException('unsupported_configuration_syntax'); }
    }

    private static function literal(array $tokens, int &$i): mixed
    {
        $token = $tokens[$i++] ?? null;
        if ($token === '-') { $value = self::literal($tokens, $i); if (!is_int($value)) { throw new \RuntimeException('unsupported_number'); } return -$value; }
        if (!is_array($token)) { throw new \RuntimeException('dynamic_value_unsupported'); }
        if ($token[0] === T_LNUMBER && preg_match('/^(?:0|[1-9][0-9]*)$/D', $token[1])) {
            $value = filter_var($token[1], FILTER_VALIDATE_INT);
            if ($value === false) { throw new \RuntimeException('integer_overflow'); }
            return $value;
        }
        if ($token[0] === T_STRING) {
            return match (strtolower($token[1])) { 'true' => true, 'false' => false, 'null' => null, default => throw new \RuntimeException('dynamic_value_unsupported') };
        }
        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) { throw new \RuntimeException('dynamic_value_unsupported'); }
        $quote = $token[1][0]; $value = substr($token[1], 1, -1); $result = '';
        for ($j = 0; $j < strlen($value); ++$j) {
            if ($value[$j] !== '\\' || $j + 1 >= strlen($value)) { $result .= $value[$j]; continue; }
            $next = $value[++$j];
            if ($quote === "'") { $result .= in_array($next, ["'", '\\'], true) ? $next : '\\' . $next; continue; }
            // Reject exotic escapes rather than corrupting salts or settings.
            $result .= match ($next) { 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '"' => '"', '$' => '$', default => throw new \RuntimeException('unsupported_string_escape') };
        }
        return $result;
    }
}

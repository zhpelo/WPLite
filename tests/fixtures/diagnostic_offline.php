<?php
// Test-only: no mail, payments or outside HTTP. Permit exactly one local cron URL.
$wpliteTestMode = trim((string) @file_get_contents(getenv('WPLITE_TEST_MODE_FILE')));
define('DISABLE_WP_CRON', $wpliteTestMode !== 'traffic-blocked');
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static function ($preempt, $arguments, $url) use ($wpliteTestMode) {
    $allowed = getenv('WPLITE_TEST_CRON_URL');
    $parts = parse_url($allowed);
    if ($url === $allowed && ($parts['host'] ?? '') === '127.0.0.1') {
        if ($wpliteTestMode === 'real') {
            return false;
        }
        if ($wpliteTestMode === 'fake') {
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }
    }
    return new WP_Error('offline_test', 'External HTTP disabled by diagnostics tests.');
}, PHP_INT_MAX, 3);

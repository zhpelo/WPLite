<?php
// WPLite; WordPress core is unmodified.
define('DB_ENGINE', 'sqlite');
define('DB_PATH', '/Users/zhuangpenglong/Dev/WPLite/wp-content/wplite-data-4a38259d7368b723/site.sqlite');
define('DB_NAME', 'wplite');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WPLITE_PUBLIC_ROOT', '/Users/zhuangpenglong/Dev/WPLite');
define('WPLITE_STORAGE_DENY_HASH', 'fa28d421a28ed9b6f4b997d5119c2b8adbacff6e2dd119a179b71230f9664934');
define('AUTH_KEY', '86469826af190d27f0e2587113a06ccfc564407684a21d44cf105cfb2feb4a67');
define('SECURE_AUTH_KEY', 'd30917613b43b14c9ae30c56d365188713dc309fc340d38c6dbe3bc9995514a7');
define('LOGGED_IN_KEY', 'f05d58fb5ea340c471a17eca5d5ff83155e728265ff1a3fe0bc7ec97651e4fc8');
define('NONCE_KEY', 'c37fe13d7317f52c0158e4c3982d86eaa831aa06f61da72b8dadd30ed25104d4');
define('AUTH_SALT', 'ac437768f430dc6b0c134b69fc8a01cd0ba6b860b8aca4041001c6d4efd1acd3');
define('SECURE_AUTH_SALT', '0ed0a1efa5cc5f73e3c90dc1de97666bb3bc1dd6f694b76c9d31b0ca55d813d6');
define('LOGGED_IN_SALT', 'ad79ce77bce108e3d611a641bc7fee908e42e155b2994757bffaa5a1f16f3d94');
define('NONCE_SALT', '4d779276080844897b44dcd14e11e3eb2ec933a0d5bdf9337d1692c96478657c');
$table_prefix = 'wp_';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-content/mu-plugins/wplite/bootstrap.php';
require_once ABSPATH . 'wp-settings.php';

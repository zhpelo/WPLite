<?php
declare(strict_types=1);
define('WP_INSTALLING', true);
require getenv('WPLITE_TEST_SITE') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (is_blog_installed()) { throw new RuntimeException('Expected a new empty site'); }
$result = wp_install('WPLite Smoke', 'smokeadmin', 'nobody@example.invalid', true, '', getenv('WPLITE_ADMIN_PASSWORD'));
if (empty($result['user_id'])) { throw new RuntimeException('WordPress installation failed'); }
update_option('siteurl', getenv('WPLITE_TEST_URL'));
update_option('home', getenv('WPLITE_TEST_URL'));
echo "PASS: native WordPress installation on SQLite\n";

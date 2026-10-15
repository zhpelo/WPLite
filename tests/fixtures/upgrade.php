<?php
declare(strict_types=1);
// Real native core updater; its package was independently downloaded and checked
// by the Python test. External HTTP/mail stay blocked by the test-only MU fixture.
define('FS_METHOD', 'direct');
require getenv('WPLITE_TEST_SITE') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
wp_set_current_user(1);
$packages = (object) array_fill_keys(['rollback', 'partial', 'new_bundled', 'no_content', 'full'], false);
$packages->full = getenv('WPLITE_TEST_UPGRADE_ARCHIVE');
$offer = (object) ['response' => 'upgrade', 'version' => getenv('WPLITE_TEST_TARGET'),
    'current' => getenv('WPLITE_TEST_TARGET'), 'locale' => 'en_US', 'packages' => $packages];
$upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin());
$result = $upgrader->upgrade($offer, ['pre_check_md5' => false]);
if (is_wp_error($result)) { throw new RuntimeException($result->get_error_code() . ': ' . $result->get_error_message()); }
if ($result !== getenv('WPLITE_TEST_TARGET')) { throw new RuntimeException('Native updater did not return the target version'); }
echo "PASS: native Core_Upgrader replaced official WordPress core files\n";

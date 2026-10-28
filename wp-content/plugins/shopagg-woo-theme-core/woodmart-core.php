<?php
/**
 * Plugin Name: SHOPAGG 主题核心
 * Description: SHOPAGG Woo 主题所需的核心功能插件
 * Version: 1.1.9
 * Text Domain: woodmart-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

define( 'WOODMART_CORE_PLUGIN_VERSION', '1.1.9' );

require_once __DIR__ . '/functions.php';

require_once __DIR__ . '/class-post-types.php';

require_once __DIR__ . '/inc/shortcodes.php';
require_once __DIR__ . '/inc/widgets.php';
require_once __DIR__ . '/inc/meta-boxes.php';

require_once __DIR__ . '/vendor/opauth/opauth/lib/Opauth/Opauth.php';
require_once __DIR__ . '/vendor/opauth/twitteroauth/twitteroauth.php';
require_once __DIR__ . '/inc/class-auth.php';

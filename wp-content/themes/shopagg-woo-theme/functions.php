<?php
/**
 *
 * The framework's functions and definitions
 */

define( 'WOODMART_THEME_DIR', get_template_directory_uri() );
define( 'WOODMART_THEMEROOT', get_template_directory() );
define( 'WOODMART_IMAGES', WOODMART_THEME_DIR . '/images' );
define( 'WOODMART_SCRIPTS', WOODMART_THEME_DIR . '/js' );
define( 'WOODMART_STYLES', WOODMART_THEME_DIR . '/css' );
define( 'WOODMART_FRAMEWORK', '/inc' );
define( 'WOODMART_DUMMY', WOODMART_THEME_DIR . '/inc/dummy-content' );
define( 'WOODMART_CLASSES', WOODMART_THEMEROOT . '/inc/classes' );
define( 'WOODMART_CONFIGS', WOODMART_THEMEROOT . '/inc/configs' );
define( 'WOODMART_HEADER_BUILDER', WOODMART_THEME_DIR . '/inc/modules/header-builder' );
define( 'WOODMART_ASSETS', WOODMART_THEME_DIR . '/inc/admin/assets' );
define( 'WOODMART_ASSETS_IMAGES', WOODMART_ASSETS . '/images' );
define( 'WOODMART_API_URL', 'https://xtemos.com/wp-json/xts/v1/' );
define( 'WOODMART_DEMO_URL', 'https://woodmart.xtemos.com/' );
define( 'WOODMART_PLUGINS_URL', WOODMART_DEMO_URL . 'plugins/' );
define( 'WOODMART_DUMMY_URL', WOODMART_DEMO_URL . 'dummy-content-new/' );
define( 'WOODMART_TOOLTIP_URL', WOODMART_DEMO_URL . 'theme-settings-tooltips/' );
define( 'WOODMART_SLUG', 'woodmart' );
define( 'SHOPAGG_WOO_THEME_SLUG', 'shopagg-woo-theme' );
define( 'SHOPAGG_WOO_THEME_CORE_SLUG', 'shopagg-woo-theme-core' );
define( 'SHOPAGG_WOO_THEME_CORE_FILE', SHOPAGG_WOO_THEME_CORE_SLUG . '/woodmart-core.php' );
define( 'WOODMART_CORE_VERSION', '1.1.9' );

if ( ! function_exists( 'shopagg_migrate_theme_core_plugin_directory' ) ) {
	/**
	 * Migrate the legacy Core plugin directory and activation records.
	 *
	 * Existing sites may still have the companion plugin installed as
	 * `woodmart-core`. Rename it once when possible, then update both regular
	 * and network activation records so WordPress loads the new basename on
	 * the next request. If the filesystem is not writable, the active legacy
	 * plugin remains untouched and continues to work for the current request.
	 *
	 * @return void
	 */
	function shopagg_migrate_theme_core_plugin_directory() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return;
		}

		$legacy_directory = WP_PLUGIN_DIR . '/woodmart-core';
		$core_directory   = WP_PLUGIN_DIR . '/' . SHOPAGG_WOO_THEME_CORE_SLUG;
		$core_file        = $core_directory . '/woodmart-core.php';

		if ( is_dir( $legacy_directory ) && ! file_exists( $core_directory ) ) {
			if ( ! is_writable( WP_PLUGIN_DIR ) || ! @rename( $legacy_directory, $core_directory ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return;
			}
		}

		// Do not switch activation records when both directories exist. This
		// avoids choosing between two independently modified plugin copies.
		if ( is_dir( $legacy_directory ) || ! file_exists( $core_file ) ) {
			return;
		}

		$legacy_plugin = 'woodmart-core/woodmart-core.php';
		$active_plugins = get_option( 'active_plugins', array() );

		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		$legacy_index   = array_search( $legacy_plugin, $active_plugins, true );

		if ( false !== $legacy_index ) {
			$active_plugins[ $legacy_index ] = SHOPAGG_WOO_THEME_CORE_FILE;
			update_option( 'active_plugins', array_values( array_unique( $active_plugins ) ) );
		}

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );

			if ( ! is_array( $network_plugins ) ) {
				$network_plugins = array();
			}

			if ( isset( $network_plugins[ $legacy_plugin ] ) ) {
				$activated_at = $network_plugins[ $legacy_plugin ];
				unset( $network_plugins[ $legacy_plugin ] );
				$network_plugins[ SHOPAGG_WOO_THEME_CORE_FILE ] = $activated_at;
				update_site_option( 'active_sitewide_plugins', $network_plugins );
			}
		}
	}
}

shopagg_migrate_theme_core_plugin_directory();

if ( ! function_exists( 'woodmart_load_classes' ) ) {
	/**
	 * Load theme PHP classes.
	 *
	 * @return void
	 */
	function woodmart_load_classes() {
		$classes = array(
			'class-singleton.php',
			'class-api.php',
			'class-config.php',
			'class-layout.php',
			'class-autoupdates.php',
			'class-activation.php',
			'class-notices.php',
			'class-theme.php',
			'class-registry.php',
		);

		foreach ( $classes as $class ) {
			require WOODMART_CLASSES . DIRECTORY_SEPARATOR . $class;
		}
	}
}

woodmart_load_classes();

new XTS\Theme();

define( 'WOODMART_VERSION', woodmart_get_theme_info( 'Version' ) );

update_option('woodmart_token','activated');
update_option('woodmart_is_activated',true);
update_option('woodmart_dev_domain',0);

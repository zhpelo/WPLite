<?php
/**
 * Enable auto updates.
 *
 * @package woodmart
 */

namespace XTS; // phpcs:ignore WordPress.NamingConventions

if ( ! defined( 'WOODMART_THEME_DIR' ) ) {
	exit( 'No direct script access allowed' );
}

use stdClass;

/**
 * Enable auto updates
 */
class Autoupdates {
	/**
	 * API instance.
	 *
	 * @var object
	 */
	private $api;
	/**
	 * Notices instance.
	 *
	 * @var object
	 */
	private $notices;
	/**
	 * Current version.
	 *
	 * @var string
	 */
	private $current_version;
	/**
	 * Theme name.
	 *
	 * @var string
	 */
	private $theme_name;
	/**
	 * Installed parent theme directory (stylesheet).
	 *
	 * Kept separately from the SHOPAGG resource slug so WordPress updates always
	 * target the actual installed directory.
	 *
	 * @var string
	 */
	private $stylesheet;
	/**
	 * Old API URL.
	 *
	 * @var string
	 */
	private $old_api_url = 'https://xtemos.com/licenses/api/';
	/**
	 * Info object.
	 *
	 * @var object
	 */
	private $info;

	/**
	 * Available languages for auto update.
	 *
	 * @var array
	 */
	private $available_languages = array(
		'ar',
		'bg_BG',
		'cs_CZ',
		'da_DK',
		'de_DE',
		'el',
		'es_ES',
		'et',
		'fi',
		'ga',
		'fr_FR',
		'he_IL',
		'hu_HU',
		'id_ID',
		'it_IT',
		'ja',
		'ko_KR',
		'lt_LT',
		'lv',
		'nb_NO',
		'nl_NL',
		'pl_PL',
		'pt_BR',
		'pt_PT',
		'ro_RO',
		'ru_RU',
		'sk_SK',
		'sl_SI',
		'sv_SE',
		'th',
		'tl',
		'tr_TR',
		'uk',
		'vi',
		'zh_CN',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->current_version = woodmart_get_theme_info( 'Version' );
		$this->theme_name      = SHOPAGG_WOO_THEME_SLUG;
		$this->stylesheet      = get_template();

		$this->api     = Registry::get_instance()->api;
		$this->notices = Registry::get_instance()->notices;

		add_action( 'admin_post_xts_reset_translations', array( $this, 'reset_translations' ) );

		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'update_plugins_version' ), 30 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_plugins_version' ), 30 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'register_core_plugin_update' ), 30 );
		add_filter( 'woodmart_setup_wizard', array( $this, 'update_plugins_version' ), 30 );

		// ShopAGG App Store owns the Woodmart theme update lifecycle. Registering
		// the installed theme is enough for its native WordPress update hooks to
		// check the remote API and provide the authenticated download package.
		$this->register_shopagg_theme();
	}

	/**
	 * Register Woodmart as a ShopAGG-managed theme.
	 *
	 * The resource may not have been published yet, so it is initially tracked
	 * by slug with an empty resource ID. The App Store updater fills the ID from
	 * the first successful update response.
	 *
	 * @return void
	 */
	private function register_shopagg_theme() {
		if ( ! class_exists( '\\ShopAGG_App_Store_Updater' ) ) {
			return;
		}

		if ( '1' !== get_option( 'woodmart_shopagg_update_provider_migrated' ) ) {
			// Clear both Woodmart's private cache and WordPress's update cache once,
			// including sites where the theme was already registered in ShopAGG.
			delete_option( 'woodmart-update-time' );
			delete_option( 'woodmart-update-info' );
			delete_site_transient( 'update_themes' );
			wp_clean_themes_cache();
			update_option( 'woodmart_shopagg_update_provider_migrated', '1', false );
		}

		$managed = get_option( 'shopagg_app_store_managed_resources', array() );

		// Migrate installations registered with the former remote resource slug.
		if ( is_array( $managed ) && isset( $managed[ WOODMART_SLUG ] ) ) {
			if ( ! isset( $managed[ $this->theme_name ] ) ) {
				$managed[ $this->theme_name ] = $managed[ WOODMART_SLUG ];
			}

			unset( $managed[ WOODMART_SLUG ] );
			update_option( 'shopagg_app_store_managed_resources', $managed );
		}

		$current = is_array( $managed ) && isset( $managed[ $this->theme_name ] )
			? $managed[ $this->theme_name ]
			: array();

		if (
			'theme' === ( $current['type'] ?? '' ) &&
			$this->stylesheet === ( $current['asset_file'] ?? '' )
		) {
			return;
		}

		\ShopAGG_App_Store_Updater::register_managed_resource(
			$this->theme_name,
			'theme',
			absint( $current['resource_id'] ?? 0 ),
			$this->stylesheet
		);

	}

	/**
	 * Reset translations.
	 */
	public function reset_translations() {
		if (
			! current_user_can( 'manage_options' ) ||
			empty( $_GET['action'] ) ||
			empty( $_GET['_wpnonce'] ) ||
			'xts_reset_translations' !== $_GET['action'] ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'xts_reset_translations_nonce' )
		) {
			return;
		}

		$languages_dir = WP_LANG_DIR . '/themes/';
		$extensions    = array( 'mo', 'po', 'l10n.php' );

		foreach ( $this->available_languages as $language ) {
			foreach ( $extensions as $ext ) {
				$file = $languages_dir . 'woodmart-' . $language . '.' . $ext;

				if ( file_exists( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		delete_option( 'woodmart_installed_languages' );
		delete_option( 'woodmart_translations_version' );

		wp_safe_redirect( admin_url( 'admin.php?page=xts_status' ) );
		exit;
	}

	/**
	 * Update transient.
	 *
	 * @param string $value Data.
	 *
	 * @return false|mixed
	 */
	public function update_transient( $value ) {
		if ( isset( $_GET['force-check'] ) && '1' === (string) $_GET['force-check'] ) { // phpcs:ignore WordPress.Security
			return false;
		}

		return $value;
	}

	/**
	 * Update Transient.
	 *
	 * @param string $transient Transient.
	 *
	 * @return mixed
	 */
	public function set_update_transient( $transient ) {
		$this->check_for_update();

		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! empty( $this->info ) && is_object( $this->info ) ) {
			if ( $this->is_update_available() ) {
				$transient->response[ $this->stylesheet ] = json_decode( wp_json_encode( $this->info ), true );
			}
		}

		// Add translation updates if option is enabled.
		if ( woodmart_get_opt( 'auto_update_translations', '0' ) ) {
			$locales              = array_values( get_available_languages() );
			$locales              = array_unique( $locales );
			$installed_languages  = get_option( 'woodmart_installed_languages', array() );
			$translations_version = get_option( 'woodmart_translations_version', '' );

			$compare_version = version_compare( $translations_version, $this->current_version );

			foreach ( $this->available_languages as $language ) {
				// Skip if language is not active on the site.
				if ( ! in_array( $language, $locales, true ) ) {
					continue;
				}

				$is_installed = in_array( $language, $installed_languages, true );
				$needs_update = version_compare( $translations_version, $this->current_version ) < 0;

				// Add translation if it's not installed OR if it needs an update.
				if ( ! $is_installed || $needs_update ) {
					$transient->translations[] = array(
						'type'       => 'theme',
						'slug'       => $this->theme_name,
						'version'    => $this->info->new_version,
						'updated'    => time(),
						'package'    => 'https://woodmart.xtemos.com/translations/' . $this->current_version . '/woodmart-' . $language . '.zip',
						'autoupdate' => true,
						'language'   => $language,
					);
				}
			}
		}

		remove_action( 'site_transient_update_themes', array( $this, 'update_transient' ), 20, 2 );

		return $transient;
	}


	/**
	 * Store installed languages information.
	 *
	 * @param object $upgrader Upgrader object.
	 * @param array  $data Data.
	 * @return void
	 */
	public function store_installed_languages_information( $upgrader, $data ) {
		if ( ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) || ! woodmart_get_opt( 'auto_update_translations', '0' ) ) {
			return;
		}

		$installed_languages = get_option( 'woodmart_installed_languages', array() );
		foreach ( $data['translations'] as $translation ) {
			if ( $translation['slug'] !== $this->theme_name || ! isset( $translation['language'] ) || ! in_array( $translation['language'], $this->available_languages, true ) ) {
				continue;
			}

			$installed_languages[] = $translation['language'];
		}
		if ( ! empty( $installed_languages ) ) {
			update_option( 'woodmart_installed_languages', $installed_languages, false );
			update_option( 'woodmart_translations_version', $this->current_version, false );
		}
	}


	/**
	 * Get API result.
	 *
	 * @param string $result API result.
	 * @param string $action Action.
	 * @param object $args Args.
	 *
	 * @return mixed
	 */
	public function api_results( $result, $action, $args ) {
		$this->check_for_update();

		if ( isset( $args->slug ) && in_array( $args->slug, array( $this->theme_name, $this->stylesheet ), true ) && 'theme_information' === $action ) {
			if ( is_object( $this->info ) && ! empty( $this->info ) ) {
				$result = $this->info;
			}
		}

		return $result;
	}

	/**
	 * Check for theme update.
	 *
	 * @return void
	 */
	protected function check_for_update() {
		$this->info              = new stdClass();
		$this->info->new_version = $this->current_version;
		$this->info->version     = $this->current_version;
		$this->info->theme       = $this->stylesheet;
		$this->info->checked     = time();
		$this->info->url         = '';
		$this->info->package     = '';

		if ( ! class_exists( '\\ShopAGG_App_Store_Updater' ) ) {
			return;
		}

		$this->register_shopagg_theme();
		$update = \ShopAGG_App_Store_Updater::instance()->get_update_for_slug( $this->theme_name );

		if ( ! is_array( $update ) || empty( $update['version'] ) ) {
			return;
		}

		$resource_id = absint( $update['id'] ?? ( $update['resource_id'] ?? 0 ) );

		$this->info->new_version = (string) $update['version'];
		$this->info->version     = (string) $update['version'];
		$this->info->url         = ! empty( $update['homepage'] ) ? (string) $update['homepage'] : '';

		if ( $resource_id ) {
			\ShopAGG_App_Store_Updater::register_managed_resource(
				$this->theme_name,
				'theme',
				$resource_id,
				$this->stylesheet
			);

			$this->info->package = admin_url(
				'admin-ajax.php?action=shopagg_app_store_download_update&resource_id=' . $resource_id .
				'&nonce=' . wp_create_nonce( 'shopagg_app_store_update' )
			);
		}
	}

	/**
	 * Get API info.
	 *
	 * @return array|mixed|stdClass|null
	 */
	public function api_info() {
		$this->check_for_update();

		$response          = new stdClass();
		$response->version = $this->info->new_version;
		$response->theme   = $this->stylesheet;

		return $response;
	}

	/**
	 * Update plugin version.
	 *
	 * @param mixed $transient Transient.
	 *
	 * @return mixed
	 */
	public function update_plugins_version( $transient ) {
		$api        = Registry::get_instance()->api;
		$plugins    = array( 'js_composer', 'woodmart-images-optimizer' );
		$force      = false;
		$last_check = get_option( 'woodmart-plugins-update-time' );

		if ( ( isset( $_GET['force-check'] ) && '1' === (string) $_GET['force-check'] ) || ( isset( $_GET['tab'] ) && 'wizard' === $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security
			$force = true;
		}

		if ( ! $last_check ) {
			update_option( 'woodmart-plugins-update-time', time() );
		}

		if ( time() - $last_check > 172800 || $force || ! $last_check ) {
			update_option( 'woodmart-plugins-update-time', time() );

			foreach ( $plugins as $plugin ) {
				$query         = $this->api->call(
					'info/' . $plugin,
					array(),
					'get',
					$this->old_api_url
				);
				$response_code = wp_remote_retrieve_response_code( $query );

				if ( '200' !== (string) $response_code ) {
					continue;
				}

				$response = json_decode( wp_remote_retrieve_body( $query ) );

				if ( empty( $response ) || ! property_exists( $response, 'version' ) ) {
					continue;
				}

				update_option( 'woodmart_' . $plugin . '_version', $response->version );
			}
		}

		return $transient;
	}

	/**
	 * Check is update available.
	 *
	 * @return bool|int
	 */
	public function is_update_available() {
		$release_version = $this->release_version();

		return $release_version && version_compare( $this->current_version, $release_version, '<' );
	}

	/**
	 * Get download url files.
	 */
	public function download_url() {
		$this->check_for_update();

		return isset( $this->info->package ) ? $this->info->package : '';
	}

	/**
	 * Get maybe next version.
	 *
	 * @return mixed
	 */
	public function release_version() {
		$this->check_for_update();

		return isset( $this->info->new_version ) ? $this->info->new_version : $this->current_version;
	}

	/**
	 * Register SHOPAGG theme core plugin update.
	 *
	 * @param object $transient Transient.
	 * @return object
	 */
	public function register_core_plugin_update( $transient ) {
		$plugin_slug = SHOPAGG_WOO_THEME_CORE_FILE;

		if ( empty( $transient->checked ) || empty( $transient->checked[ $plugin_slug ] ) ) {
			return $transient;
		}

		$current_version = $transient->checked[ $plugin_slug ];
		$new_version     = WOODMART_CORE_VERSION;

		$item = (object) array(
			'slug'        => SHOPAGG_WOO_THEME_CORE_SLUG,
			'plugin'      => $plugin_slug,
			'new_version' => $new_version,
			'package'     => get_parent_theme_file_path( WOODMART_FRAMEWORK . '/plugins/shopagg-woo-theme-core.zip' ),
		);

		if ( version_compare( $current_version, $new_version, '<' ) ) {
			$transient->response[ $plugin_slug ] = $item;
		} else {
			$transient->no_update[ $plugin_slug ] = $item;
		}

		return $transient;
	}
}

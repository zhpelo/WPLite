<?php
/**
 * Import process.
 *
 * @package woodmart
 */

namespace XTS\Admin\Modules\Import;

if ( ! defined( 'WOODMART_THEME_DIR' ) ) {
	exit( 'No direct script access allowed' );
}

/**
 * Import process.
 */
class Process {
	/**
	 * Available versions.
	 *
	 * @var $version_list
	 */
	private $version_list;
	/**
	 * Current version.
	 *
	 * @var $version
	 */
	private $version;
	/**
	 * Current version type.
	 *
	 * @var $type
	 */
	private $type;
	/**
	 * Current process.
	 *
	 * @var $version
	 */
	private $current_process;
	/**
	 * Is version imported.
	 *
	 * @var bool
	 */
	private $is_version_imported;
	/**
	 * Helpers.
	 *
	 * @var Helpers
	 */
	private $helpers;
	/**
	 * Import error.
	 *
	 * @var \WP_Error|null
	 */
	private $error;
	/**
	 * Offset inside the current media XML file.
	 *
	 * @var int
	 */
	private $media_offset = 0;
	/**
	 * Whether the current media XML has another batch.
	 *
	 * @var bool
	 */
	private $media_has_more = false;
	/**
	 * Offset for the next media batch.
	 *
	 * @var int
	 */
	private $next_media_offset = 0;

	/**
	 * Constructor.
	 *
	 * @param string $version Version slug.
	 * @param string $current_process Current process name.
	 * @param string $type Version type.
	 * @param int    $media_offset Offset inside a media XML file.
	 */
	public function __construct( $version, $current_process, $type, $media_offset = 0 ) {
		$this->version             = $version;
		$this->type                = $type;
		$this->current_process     = $current_process;
		$this->media_offset        = absint( $media_offset );
		$this->version_list        = woodmart_get_config( 'versions' );
		$this->helpers             = Helpers::get_instance();
		$this->is_version_imported = $this->is_version_imported();

		$this->run_import();
	}

	/**
	 * Run import.
	 */
	public function run_import() {
		if ( $this->need_process( 'xml' ) && 'xml' === $this->current_process && ( ! $this->is_version_imported || ( 'page' === $this->type || 'element' === $this->type ) ) ) {
			if ( 'base' === $this->type ) {
				Before::get_instance();
			}

			new XML( $this->version, 'posts' );
		}

		if ( $this->need_process( 'xml_images' ) && ! $this->is_version_imported && strpos( $this->current_process, 'images' ) !== false ) {
			$xml                     = new XML( $this->version, $this->current_process, '', $this->media_offset );
			$this->media_has_more    = $xml->has_more_media();
			$this->next_media_offset = $xml->get_next_media_offset();
		}

		if ( 'other' === $this->current_process ) {
			$this->error = $this->validate_imported_content();

			if ( is_wp_error( $this->error ) ) {
				return;
			}

			if ( $this->need_process( 'widgets' ) ) {
				new Widgets( $this->version );
			}

			if ( $this->need_process( 'options' ) ) {
				new Options( $this->version, $this->type );
			}

			if ( $this->need_process( 'headers' ) && ! $this->is_version_imported ) {
				new Headers( $this->version );
			}

			if ( $this->need_process( 'home' ) ) {
				new Menu( $this->version );
			}

			if ( $this->need_process( 'images' ) ) {
				new Images( $this->version );
			}

			$import_after = After::get_instance();

			if ( 'base' === $this->type ) {
				$import_after->set_menu_locations();
				$import_after->set_blog_page();
				$import_after->set_shop_page();
				$import_after->enable_wpb_on_custom_post_types();
				$import_after->enable_elementor_on_custom_post_types();
				$import_after->show_all_fields_menu();
				$import_after->enable_myaccount_registration();
				$import_after->update_product_lookup_tables();
				$import_after->set_pages_sidebar();
				$import_after->wc_remove_uncategorized_cat();

				if ( 'elementor' === $this->helpers->get_page_builder() ) {
					$import_after->set_site_settings();
				}
			}

			$import_after->replace_db_urls();

			$imported_versions   = get_option( 'wd_import_imported_versions', array() );
			if ( ! in_array( $this->version, $imported_versions, true ) ) {
				$imported_versions[] = $this->version;
			}

			update_option( 'wd_import_imported_versions', $imported_versions, false );
			update_option( 'wd_import_theme_version', woodmart_get_theme_info( 'Version' ), false );
			update_option( 'woodmart_setup_status', 'done', false );

			if ( 'version' === $this->type ) {
				update_option( 'wd_import_current_version', $this->version, false );
			}

			if ( 'base' === $this->type ) {
				update_option( 'wd_import_current_base', $this->version, false );
			}

			$import_after->change_header_on_pages();

			do_action( 'woodmart_after_import' );
		}
	}

	/**
	 * Get an error raised by the current import step.
	 *
	 * @return \WP_Error|null
	 */
	public function get_error() {
		return $this->error;
	}

	/**
	 * Whether the current media XML has another batch.
	 *
	 * @return bool
	 */
	public function has_more_media() {
		return $this->media_has_more;
	}

	/**
	 * Get the next offset inside the current media XML file.
	 *
	 * @return int
	 */
	public function get_next_media_offset() {
		return $this->next_media_offset;
	}

	/**
	 * Determine whether all required content for this version was imported.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return $this->is_version_imported;
	}

	/**
	 * Verify required products and attachments before marking an import complete.
	 *
	 * @return \WP_Error|null
	 */
	private function validate_imported_content() {
		$imported_data = $this->helpers->get_imported_data( $this->version );
		$expected      = $this->get_expected_content_counts();
		$products      = isset( $imported_data['product'] ) && is_array( $imported_data['product'] ) ? count( $imported_data['product'] ) : 0;
		$attachments   = isset( $imported_data['attachment'] ) && is_array( $imported_data['attachment'] ) ? count( $imported_data['attachment'] ) : 0;

		if ( $expected['products'] > $products ) {
			return new \WP_Error(
				'shopagg_import_products_incomplete',
				sprintf(
					/* translators: 1: imported products, 2: expected products. */
					__( 'Product import is incomplete (%1$d of %2$d). Please confirm that WooCommerce and the required theme plugins are active, then retry the import.', 'woodmart' ),
					$products,
					$expected['products']
				)
			);
		}

		if ( $expected['attachments'] > $attachments ) {
			return new \WP_Error(
				'shopagg_import_media_incomplete',
				sprintf(
					/* translators: 1: imported attachments, 2: expected attachments. */
					__( 'Image import is incomplete (%1$d of %2$d). Check that the server can access the demo media CDN, then retry the import.', 'woodmart' ),
					$attachments,
					$expected['attachments']
				)
			);
		}

		return null;
	}

	/**
	 * Count the products and attachments declared in this demo's XML files.
	 *
	 * @return array
	 */
	private function get_expected_content_counts() {
		$builder     = $this->helpers->get_page_builder();
		$file_prefix = 'content';

		if ( 'elementor' === $builder ) {
			$file_prefix .= '-elementor';
		} elseif ( 'gutenberg' === $builder ) {
			$file_prefix .= '-gutenberg';
		}

		$counts = array(
			'products'    => 0,
			'attachments' => 0,
		);
		$files  = array( $file_prefix . '.xml' );

		if ( $this->need_process( 'xml_images' ) ) {
			for ( $index = 1; $index <= 4; $index++ ) {
				$files[] = $file_prefix . '-images' . $index . '.xml';
			}
		}

		foreach ( $files as $file_name ) {
			$file_path = $this->helpers->get_file_path( $file_name, $this->version );

			if ( ! $file_path ) {
				continue;
			}

			$content = $this->helpers->get_local_file_content( $file_path );
			if ( ! is_string( $content ) ) {
				continue;
			}

			$counts['products']    += substr_count( $content, '<wp:post_type><![CDATA[product]]></wp:post_type>' );
			$counts['attachments'] += substr_count( $content, '<wp:post_type><![CDATA[attachment]]></wp:post_type>' );
		}

		return $counts;
	}

	/**
	 * Is need process.
	 *
	 * @param string $process Process name.
	 *
	 * @return bool
	 */
	private function need_process( $process ) {
		return in_array( $process, explode( ',', $this->version_list[ $this->version ]['process'] ), true );
	}

	/**
	 * Is version imported.
	 *
	 * @return bool
	 */
	private function is_version_imported() {
		$imported_versions = get_option( 'wd_import_imported_versions', array() );
		$imported_data     = get_option( 'wd_imported_data_' . $this->version );
		$is_imported       = in_array( $this->version, $imported_versions, true ) && ! empty( $imported_data['page'] );

		if ( ! $is_imported || ! $this->need_process( 'xml_images' ) ) {
			return $is_imported;
		}

		$expected    = $this->get_expected_content_counts();
		$attachments = ! empty( $imported_data['attachment'] ) && is_array( $imported_data['attachment'] ) ? count( $imported_data['attachment'] ) : 0;

		return 0 === $expected['attachments'] || $attachments >= $expected['attachments'];
	}
}

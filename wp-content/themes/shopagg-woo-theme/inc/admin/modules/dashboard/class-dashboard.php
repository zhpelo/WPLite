<?php
/**
 * Dashboard admin module.
 *
 * @package woodmart
 */

namespace XTS\Admin\Modules;

use XTS\Modules\Dynamic_Discounts\Manager as Dynamic_Discounts_Manager;
use XTS\Modules\Patcher\Client;
use XTS\Admin\Modules\Options\Presets;
use XTS\Singleton;

/**
 * Dashboard class.
 */
class Dashboard extends Singleton {
	/**
	 * Constructor.
	 */
	public function init() {
		add_filter( 'register_post_type_args', array( $this, 'group_theme_post_types_in_dashboard' ), 20, 2 );
		add_filter( 'gettext', array( $this, 'replace_admin_branding_text' ), 20, 3 );
		add_filter( 'gettext_with_context', array( $this, 'replace_admin_branding_text_with_context' ), 20, 4 );
		add_filter( 'ngettext', array( $this, 'replace_admin_branding_plural' ), 20, 5 );
		add_filter( 'ngettext_with_context', array( $this, 'replace_admin_branding_plural_with_context' ), 20, 6 );
		add_filter( 'wp_prepare_themes_for_js', array( $this, 'replace_theme_card_branding' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_branding_script' ) );
		add_filter( 'views_edit-woodmart_layout', array( $this, 'print_header' ), 9 );
		add_filter( 'views_edit-woodmart_slide', array( $this, 'print_header' ), 9 );
		add_filter( 'views_edit-woodmart_sidebar', array( $this, 'print_header' ), 9 );
		add_filter( 'woodmart_slider_pre_add_form', array( $this, 'print_header' ), 9 );
		add_filter( 'views_edit-cms_block', array( $this, 'print_header' ), 9 );
		add_filter( 'cms_block_cat_pre_add_form', array( $this, 'print_header' ), 9 );
		add_filter( 'views_edit-wd_popup', array( $this, 'print_header' ), 9 );
		add_filter( 'views_edit-wd_popup_cat', array( $this, 'print_header' ), 9 );
		add_filter( 'views_edit-wd_floating_block', array( $this, 'print_header' ), 9 );
		add_filter( 'views_edit-wd_floating_block_cat', array( $this, 'print_header' ), 9 );
		add_action( 'admin_menu', array( $this, 'add_pages_to_dashboard_menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'normalize_dashboard_submenus' ), 99 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_classes' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );

		if ( current_user_can( apply_filters( 'woodmart_dashboard_theme_links_access', 'administrator' ) ) ) {
			add_action( 'admin_bar_menu', array( $this, 'add_pages_to_admin_bar_menu' ), 100 );
		}
	}

	/**
	 * Group theme-owned content types under the SHOPAGG theme dashboard menu.
	 *
	 * Only the menu parent is changed. Post type names, capabilities, URLs and
	 * editor behavior remain untouched.
	 *
	 * @param array  $args      Post type registration arguments.
	 * @param string $post_type Post type key.
	 * @return array
	 */
	public function group_theme_post_types_in_dashboard( $args, $post_type ) {
		$theme_post_types = array(
			'woodmart_layout',
			'woodmart_slide',
			'woodmart_sidebar',
			'cms_block',
			'wd_popup',
			'wd_floating_block',
			'portfolio',
		);

		if ( in_array( $post_type, $theme_post_types, true ) ) {
			$args['show_in_menu'] = 'xts_dashboard';
		}

		return $args;
	}

	/**
	 * Normalize labels and ordering for theme content submenus.
	 *
	 * WordPress uses each post type's "All items" label when it is attached to
	 * another menu. Restore the concise labels previously shown at the top level
	 * and keep the SHOPAGG dashboard as the first submenu entry.
	 *
	 * @return void
	 */
	public function normalize_dashboard_submenus() {
		global $submenu;

		if ( empty( $submenu['xts_dashboard'] ) || ! is_array( $submenu['xts_dashboard'] ) ) {
			return;
		}

		$labels = array(
			'edit.php?post_type=woodmart_layout'         => esc_html__( 'Layouts', 'woodmart' ),
			'edit.php?post_type=woodmart_slide'          => esc_html__( 'Slides', 'woodmart' ),
			'edit.php?post_type=woodmart_sidebar'        => esc_html__( 'Sidebars', 'woodmart' ),
			'edit.php?post_type=cms_block'               => esc_html__( 'HTML Blocks', 'woodmart' ),
			'edit.php?post_type=wd_popup'                => esc_html__( 'Popups', 'woodmart' ),
			'edit.php?post_type=wd_floating_block'       => esc_html__( 'Floating Blocks', 'woodmart' ),
			'edit.php?post_type=portfolio'               => esc_html__( 'Projects', 'woodmart' ),
		);
		$order = array(
			'xts_dashboard'                              => 0,
			'xts_theme_settings'                         => 10,
			'xts_theme_settings_backup'                  => 11,
			'xts_theme_settings_presets'                 => 12,
			'edit.php?post_type=woodmart_layout'         => 20,
			'edit.php?post_type=cms_block'               => 21,
			'edit.php?post_type=woodmart_sidebar'        => 22,
			'edit.php?post_type=wd_popup'                => 23,
			'edit.php?post_type=wd_floating_block'       => 24,
			'edit.php?post_type=portfolio'               => 25,
			'edit.php?post_type=woodmart_slide'          => 26,
			'xts_header_builder'                         => 30,
			'xts_prebuilt_websites'                      => 40,
			'xts_license'                                => 50,
			'xts_plugins'                                => 60,
			'xts_patcher'                                => 70,
			'xts_status'                                 => 80,
			'xts_changelog'                              => 90,
		);

		foreach ( $submenu['xts_dashboard'] as &$item ) {
			if ( isset( $item[2], $labels[ $item[2] ] ) ) {
				$item[0] = $labels[ $item[2] ];
			}
		}
		unset( $item );

		usort(
			$submenu['xts_dashboard'],
			static function ( $first, $second ) use ( $order ) {
				$first_order  = isset( $first[2], $order[ $first[2] ] ) ? $order[ $first[2] ] : 100;
				$second_order = isset( $second[2], $order[ $second[2] ] ) ? $order[ $second[2] ] : 100;

				return $first_order <=> $second_order;
			}
		);
	}

	/**
	 * Get logo url.
	 *
	 * @return string
	 */
	protected function get_logo_url() {
		$logo_url = defined( 'SHOPAGG_APP_STORE_PLUGIN_URL' )
			? SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/images/shopagg.svg'
			: plugins_url( 'shopagg-app-store/assets/images/shopagg.svg' );

		$white_label_logo = woodmart_get_opt( 'white_label_sidebar_icon_logo', array( 'url' => '' ) );
		if ( woodmart_get_opt( 'white_label' ) && ! empty( $white_label_logo['url'] ) ) {
			$image_data = woodmart_get_opt( 'white_label_sidebar_icon_logo' );

			if ( isset( $image_data['url'] ) && $image_data['url'] ) {
				$logo_url = wp_get_attachment_image_url( $image_data['id'], 'full' );
			}
		}

		return $logo_url;
	}

	/**
	 * Get theme name.
	 *
	 * @return string
	 */
	protected function get_theme_name() {
		$theme_name = 'SHOPAGG主题';

		if ( woodmart_get_opt( 'white_label' ) && woodmart_get_opt( 'white_label_theme_name' ) ) {
			$theme_name = woodmart_get_opt( 'white_label_theme_name' );
		}

		return $theme_name;
	}

	/**
	 * Replace legacy vendor names in translated backend copy only.
	 *
	 * Internal slugs, option keys, URLs and frontend content are intentionally
	 * untouched so this branding layer cannot alter theme behavior.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function replace_admin_branding_text( $translation, $text = '', $domain = '' ) {
		if ( ! is_admin() || 'woodmart' !== $domain ) {
			return $translation;
		}

		return str_replace(
			array(
				'SHOPAGG Theme Core',
				'WoodMart Core',
				'Woodmart Core',
				'WOODMART CORE',
				'WoodMart dashboard',
				'Woodmart dashboard',
				'WoodMart',
				'Woodmart',
				'WOODMART',
				'XTemos Studio',
				'Xtemos Studio',
				'XTemos',
				'Xtemos',
				'XTEMOS',
			),
			array(
				'SHOPAGG 主题核心',
				'SHOPAGG 主题核心',
				'SHOPAGG 主题核心',
				'SHOPAGG 主题核心',
				'SHOPAGG主题后台',
				'SHOPAGG主题后台',
				'SHOPAGG主题',
				'SHOPAGG主题',
				'SHOPAGG主题',
				'SHOPAGG主题',
				'SHOPAGG主题',
				'SHOPAGG主题',
				'SHOPAGG主题',
				'SHOPAGG主题',
			),
			$translation
		);
	}

	/**
	 * Replace branding in contextual translations.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Source text.
	 * @param string $context     Translation context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function replace_admin_branding_text_with_context( $translation, $text, $context, $domain ) {
		return $this->replace_admin_branding_text( $translation, $text, $domain );
	}

	/**
	 * Replace branding in plural translations.
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular source text.
	 * @param string $plural      Plural source text.
	 * @param int    $number      Number used to select the plural.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function replace_admin_branding_plural( $translation, $single, $plural, $number, $domain ) {
		return $this->replace_admin_branding_text( $translation, $single, $domain );
	}

	/**
	 * Replace branding in contextual plural translations.
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular source text.
	 * @param string $plural      Plural source text.
	 * @param int    $number      Number used to select the plural.
	 * @param string $context     Translation context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function replace_admin_branding_plural_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		return $this->replace_admin_branding_text( $translation, $single, $domain );
	}

	/**
	 * Enqueue a DOM-level fallback for hardcoded and dynamically inserted copy.
	 *
	 * @return void
	 */
	public function enqueue_branding_script() {
		$script_path = WOODMART_THEMEROOT . '/inc/admin/assets/js/shopagg-theme-branding.js';

		wp_enqueue_script(
			'shopagg-theme-admin-branding',
			WOODMART_ASSETS . '/js/shopagg-theme-branding.js',
			array(),
			file_exists( $script_path ) ? filemtime( $script_path ) : WOODMART_VERSION,
			true
		);
	}

	/**
	 * Rebrand the theme card shown under Appearance -> Themes.
	 *
	 * @param array $themes Prepared theme data.
	 * @return array
	 */
	public function replace_theme_card_branding( $themes ) {
		if ( ! is_array( $themes ) ) {
			return $themes;
		}

		foreach ( $themes as &$theme ) {
			if ( ! is_array( $theme ) || 'woodmart' !== ( $theme['id'] ?? '' ) ) {
				continue;
			}

			$theme['name']  = 'SHOPAGG主题';
			$theme['title'] = 'SHOPAGG主题';

			foreach ( array( 'author', 'authorAndUri', 'description' ) as $key ) {
				if ( isset( $theme[ $key ] ) && is_string( $theme[ $key ] ) ) {
					$theme[ $key ] = str_replace(
						array( 'WoodMart', 'Woodmart', 'XTemos', 'Xtemos' ),
						'SHOPAGG主题',
						$theme[ $key ]
					);
				}
			}

			$theme['screenshot'] = array( WOODMART_ASSETS_IMAGES . '/shopagg-theme-preview.svg' );
		}
		unset( $theme );

		return $themes;
	}
	/**
	 * Add theme settings links to the admin bar.
	 *
	 * @since 1.0.0
	 *
	 * @param object $admin_bar Admin bar object.
	 */
	public function add_pages_to_admin_bar_menu( $admin_bar ) {
		if ( ! woodmart_get_opt( 'theme_admin_bar_menu', true ) ) {
			return;
		}

		$theme_name       = $this->get_theme_name();
		$admin_bar_img    = '';
		$product          = woodmart_woocommerce_installed() ? wc_get_product() : false;
		$active_discounts = woodmart_get_opt( 'discounts_enabled', 0 ) && ! empty( $product ) && class_exists( 'XTS\Modules\Dynamic_Discounts\Manager', false ) ? Dynamic_Discounts_Manager::get_instance()->get_discount_rules( $product ) : array();

		if ( $this->get_logo_url() ) {
			$admin_bar_img = '<img src="' . esc_url( $this->get_logo_url() ) . '" alt="icon">';
		}

		$admin_bar->add_node(
			array(
				'id'    => 'xts_dashboard',
				'title' => $admin_bar_img . $theme_name,
				'href'  => admin_url( 'admin.php?page=xts_dashboard' ),
				'meta'  => array(
					'title' => $theme_name,
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_theme_settings',
				'title'  => '<i class="xts-i-theme-settings"></i>' . esc_html__( 'Theme settings', 'woodmart' ),
				'href'   => admin_url( 'admin.php?page=xts_theme_settings' ),
				'parent' => 'xts_dashboard',
				'meta'   => array(
					'title' => esc_html__( 'Theme settings', 'woodmart' ),
				),
			)
		);

		foreach ( Options::get_sections() as $key => $section ) {
			if ( isset( $section['parent'] ) ) {
				continue;
			}

			$admin_bar->add_node(
				array(
					'id'     => 'wd_' . $section['id'],
					'title'  => '<i class="' . $section['icon'] . '"></i>' . $section['name'],
					'href'   => admin_url( 'admin.php?page=xts_theme_settings&tab=' . $key ),
					'parent' => 'xts_theme_settings',
				)
			);
		}

		$active_presets = Presets::get_active_presets();
		if ( $active_presets ) {
			$admin_bar->add_node(
				array(
					'id'     => 'xts_theme_settings_presets_active',
					'title'  => '<i class="xts-i-cog"></i>' . esc_html__( 'Active presets', 'woodmart' ),
					'href'   => admin_url( 'admin.php?page=xts_theme_settings_presets' ),
					'parent' => 'xts_dashboard',
					'meta'   => array(
						'title' => esc_html__( 'Active theme settings presets', 'woodmart' ),
					),
				)
			);

			foreach ( $active_presets as $preset_id ) {
				$all_presets = Presets::get_all();

				$admin_bar->add_node(
					array(
						'id'     => 'xts_theme_settings_presets_active_' . $preset_id,
						'title'  => $all_presets[ $preset_id ]['name'],
						'href'   => admin_url( 'admin.php?page=xts_theme_settings&preset=' . $preset_id ),
						'parent' => 'xts_theme_settings_presets_active',
						'meta'   => array(
							'title' => $all_presets[ $preset_id ]['name'],
						),
					)
				);
			}
		}

		if ( ! empty( $active_discounts ) ) {
			$admin_bar->add_node(
				array(
					'id'     => 'wd_woo_discounts_active',
					'title'  => '<i class="xts-i-cog"></i>' . esc_html__( 'Active discount', 'woodmart' ),
					'href'   => admin_url( 'edit.php?post_type=wd_woo_discounts' ),
					'parent' => 'xts_dashboard',
					'meta'   => array(
						'title' => esc_html__( 'Active discounts', 'woodmart' ),
					),
				)
			);

			$admin_bar->add_node(
				array(
					'id'     => 'wd_woo_discounts_active_' . $active_discounts['post_id'],
					'title'  => $active_discounts['title'],
					'href'   => get_edit_post_link( $active_discounts['post_id'] ),
					'parent' => 'wd_woo_discounts_active',
					'meta'   => array(
						'title' => $active_discounts['title'],
					),
				)
			);
		}

		$admin_bar->add_node(
			array(
				'id'     => 'xts_header_builder',
				'title'  => '<i class="xts-i-header-builder"></i>' . esc_html__( 'Header builder', 'woodmart' ),
				'href'   => admin_url( 'admin.php?page=xts_header_builder' ),
				'parent' => 'xts_dashboard',
				'meta'   => array(
					'title' => esc_html__( 'Header builder', 'woodmart' ),
				),
			)
		);

		$header_object = whb_get_header();

		if ( $header_object && ! is_admin() ) {
			global $wp;

			$admin_bar->add_node(
				array(
					'id'     => 'xts_header_builder_edit',
					'title'  => esc_html__( 'Edit current header', 'woodmart' ),
					'href'   => home_url( add_query_arg( array(), $wp->request ) ) . '?whb-header-frontend=' . $header_object->get_id(),
					'parent' => 'xts_header_builder',
					'meta'   => array(
						'title' => $header_object->get_name(),
					),
				)
			);
		}

		$admin_bar->add_node(
			array(
				'id'     => 'xts_layouts',
				'title'  => '<i class="xts-i-layouts"></i>' . esc_html__( 'Layouts', 'woodmart' ),
				'href'   => admin_url( 'edit.php?post_type=woodmart_layout' ),
				'parent' => 'xts_dashboard',
				'meta'   => array(
					'title' => esc_html__( 'Layouts', 'woodmart' ),
					'class' => 'woodmart_layout-post-type',
				),
			)
		);

		if ( woodmart_get_opt( 'woodmart_slider', '1' ) ) {
			$admin_bar->add_node(
				array(
					'id'     => 'xts_sliders',
					'title'  => '<i class="xts-i-slides"></i>' . esc_html__( 'Sliders', 'woodmart' ),
					'href'   => admin_url( 'edit-tags.php?taxonomy=woodmart_slider&post_type=woodmart_slide' ),
					'parent' => 'xts_dashboard',
					'meta'   => array(
						'title' => esc_html__( 'Sliders', 'woodmart' ),
					),
				)
			);

			$admin_bar->add_node(
				array(
					'id'     => 'xts_slides',
					'title'  => esc_html__( 'Slides', 'woodmart' ),
					'href'   => admin_url( 'edit.php?post_type=woodmart_slide' ),
					'parent' => 'xts_sliders',
					'meta'   => array(
						'title' => esc_html__( 'Slides', 'woodmart' ),
					),
				)
			);

			$admin_bar->add_node(
				array(
					'id'     => 'xts_slides_add',
					'title'  => esc_html__( 'Add new slide', 'woodmart' ),
					'href'   => admin_url( 'post-new.php?post_type=woodmart_slide' ),
					'parent' => 'xts_sliders',
					'meta'   => array(
						'title' => esc_html__( 'Add new', 'woodmart' ),
					),
				)
			);
		}

		$admin_bar->add_node(
			array(
				'id'     => 'xts_html_block',
				'title'  => '<i class="xts-i-html-block"></i>' . esc_html__( 'HTML Block', 'woodmart' ),
				'href'   => admin_url( 'edit.php?post_type=cms_block' ),
				'parent' => 'xts_dashboard',
				'meta'   => array(
					'title' => esc_html__( 'HTML Block', 'woodmart' ),
					'class' => 'cms_block-post-type',
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_html_block_add',
				'title'  => esc_html__( 'Add block', 'woodmart' ),
				'href'   => admin_url( 'post-new.php?post_type=cms_block' ),
				'parent' => 'xts_html_block',
				'meta'   => array(
					'title' => esc_html__( 'Add new', 'woodmart' ),
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_html_block_category',
				'title'  => esc_html__( 'Categories', 'woodmart' ),
				'href'   => admin_url( 'edit-tags.php?taxonomy=cms_block_cat&post_type=cms_block' ),
				'parent' => 'xts_html_block',
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_popup',
				'title'  => '<i class="xts-i-popup"></i>' . esc_html__( 'Popups', 'woodmart' ),
				'href'   => admin_url( 'edit.php?post_type=wd_popup' ),
				'parent' => 'xts_dashboard',
				'meta'   => array(
					'title' => esc_html__( 'Popups', 'woodmart' ),
					'class' => 'wd_popup-post-type',
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_popup_add',
				'title'  => esc_html__( 'Add popup', 'woodmart' ),
				'href'   => admin_url( 'edit.php?post_type=wd_popup&create_template' ),
				'parent' => 'xts_popup',
				'meta'   => array(
					'title' => esc_html__( 'Add new', 'woodmart' ),
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_popup_category',
				'title'  => esc_html__( 'Categories', 'woodmart' ),
				'href'   => admin_url( 'edit-tags.php?taxonomy=wd_popup_cat&post_type=wd_popup' ),
				'parent' => 'xts_popup',
				'meta'   => array(
					'title' => esc_html__( 'Add new', 'woodmart' ),
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_floating_blocks',
				'title'  => '<i class="xts-i-fb"></i>' . esc_html__( 'Floating blocks', 'woodmart' ),
				'href'   => admin_url( 'edit.php?post_type=wd_floating_block' ),
				'parent' => 'xts_dashboard',
				'meta'   => array(
					'title' => esc_html__( 'Floating blocks', 'woodmart' ),
					'class' => 'wd_floating_block-post-type',
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_floating_blocks_add',
				'title'  => esc_html__( 'Add block', 'woodmart' ),
				'href'   => admin_url( 'edit.php?post_type=wd_floating_block&create_template' ),
				'parent' => 'xts_floating_blocks',
				'meta'   => array(
					'title' => esc_html__( 'Add new', 'woodmart' ),
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'xts_floating_blocks_category',
				'title'  => esc_html__( 'Categories', 'woodmart' ),
				'href'   => admin_url( 'edit-tags.php?taxonomy=wd_floating_block_cat&post_type=wd_floating_block' ),
				'parent' => 'xts_floating_blocks',
			)
		);

		$admin_bar->add_group(
			array(
				'parent' => 'xts_dashboard',
				'id'     => 'xts_dashboard_external',
				'meta'   => array(
					'class' => 'ab-sub-secondary',
				),
			)
		);

		if ( woodmart_get_opt( 'dummy_import', '1' ) ) {
			$admin_bar->add_node(
				array(
					'id'     => 'xts_prebuilt_websites',
					'title'  => '<i class="xts-i-dummy-content"></i>' . esc_html__( 'Prebuilt websites', 'woodmart' ),
					'href'   => admin_url( 'admin.php?page=xts_prebuilt_websites' ),
					'parent' => 'xts_dashboard_external',
					'meta'   => array(
						'title' => esc_html__( 'Prebuilt websites', 'woodmart' ),
					),
				)
			);
		}

		if ( woodmart_get_opt( 'white_label_theme_license_tab', '1' ) ) {
			$admin_bar->add_node(
				array(
					'parent' => 'xts_dashboard_external',
					'id'     => 'xts_license',
					'title'  => '<i class="xts-i-key"></i>' . esc_html__( 'Theme license', 'woodmart' ),
					'href'   => admin_url( 'admin.php?page=xts_license' ),
				)
			);
		}

		$admin_bar->add_node(
			array(
				'parent' => 'xts_dashboard_external',
				'id'     => 'xts_plugins',
				'title'  => '<i class="xts-i-puzzle"></i>' . esc_html__( 'Plugins', 'woodmart' ),
				'href'   => admin_url( 'admin.php?page=xts_plugins' ),
			)
		);

		$patches_count = class_exists( 'XTS\Modules\Patcher\Client' ) ? Client::get_instance()->get_count_patches_map() : '';

		$admin_bar->add_node(
			array(
				'parent' => 'xts_dashboard_external',
				'id'     => 'xts_patcher',
				'title'  => '<i class="xts-i-cog"></i>' . esc_html__( 'Patcher', 'woodmart' ) . $patches_count,
				'href'   => admin_url( 'admin.php?page=xts_patcher' ),
			)
		);
	}

	/**
	 * Add pages to dashboard menu.
	 */
	public function add_pages_to_dashboard_menu() {
		global $menu, $submenu;

		$theme_name = $this->get_theme_name();

		$separator_position = '31.1';

		if ( isset( $menu[ $separator_position ] ) ) {
			$separator_position = $separator_position + base_convert( substr( md5( 'separator-xts_dashboard-wp-menu-separator xts-dashboard' ), -4 ), 16, 10 ) * 0.00001;
		}

		$menu[ $separator_position ] = array( '', 'read', 'separator-xts_dashboard', '', 'wp-menu-separator xts-dashboard' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		add_menu_page(
			$theme_name,
			$theme_name,
			apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_dashboard' ),
			'xts_dashboard',
			array( $this, 'page_content' ),
			$this->get_logo_url(),
			31.2
		);

		add_submenu_page(
			'xts_dashboard',
			esc_html__( 'Theme settings', 'woodmart' ),
			esc_html__( 'Theme settings', 'woodmart' ),
			apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_theme_settings' ),
			'xts_theme_settings',
			array( $this, 'page_content' ),
			1
		);

		add_submenu_page(
			'xts_dashboard',
			esc_html__( 'Theme settings backup', 'woodmart' ),
			esc_html__( 'Theme settings backup', 'woodmart' ),
			'manage_options',
			'xts_theme_settings_backup',
			array( $this, 'page_content' ),
			1
		);

		add_submenu_page(
			'xts_dashboard',
			esc_html__( 'Theme settings presets', 'woodmart' ),
			esc_html__( 'Theme settings presets', 'woodmart' ),
			'manage_options',
			'xts_theme_settings_presets',
			array( $this, 'page_content' ),
			2
		);

		add_submenu_page(
			'xts_dashboard',
			esc_html__( 'Header builder', 'woodmart' ),
			esc_html__( 'Header builder', 'woodmart' ),
			apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_header_builder' ),
			'xts_header_builder',
			array( $this, 'page_content' ),
			3
		);

		if ( woodmart_get_opt( 'dummy_import', '1' ) ) {
			add_submenu_page(
				'xts_dashboard',
				esc_html__( 'Prebuilt websites', 'woodmart' ),
				esc_html__( 'Prebuilt websites', 'woodmart' ),
				apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_prebuilt_websites' ),
				'xts_prebuilt_websites',
				array( $this, 'page_content' ),
				4
			);
		}

		if ( woodmart_get_opt( 'white_label_theme_license_tab', '1' ) ) {
			add_submenu_page(
				'xts_dashboard',
				esc_html__( 'Theme license', 'woodmart' ),
				esc_html__( 'Theme license', 'woodmart' ),
				apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_license' ),
				'xts_license',
				array( $this, 'page_content' ),
				5
			);
		}

		add_submenu_page(
			'xts_dashboard',
			esc_html__( 'Plugins', 'woodmart' ),
			esc_html__( 'Plugins', 'woodmart' ),
			apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'plugins' ),
			'xts_plugins',
			array( $this, 'page_content' ),
			6
		);

		$patches_count = class_exists( 'XTS\Modules\Patcher\Client' ) ? Client::get_instance()->get_count_patches_map() : '';

		add_submenu_page(
			'xts_dashboard',
			esc_html__( 'Patcher', 'woodmart' ),
			esc_html__( 'Patcher', 'woodmart' ) . $patches_count,
			apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_patcher' ),
			'xts_patcher',
			array( $this, 'page_content' ),
			7
		);

		add_submenu_page(
			'xts_dashboard',
			esc_html__( 'Status', 'woodmart' ),
			esc_html__( 'Status', 'woodmart' ),
			apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_status' ),
			'xts_status',
			array( $this, 'page_content' ),
			8
		);

		if ( woodmart_get_opt( 'white_label_changelog_tab', '1' ) ) {
			add_submenu_page(
				'xts_dashboard',
				esc_html__( 'Changelog', 'woodmart' ),
				esc_html__( 'Changelog', 'woodmart' ),
				apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_changelog' ),
				'xts_changelog',
				array( $this, 'page_content' ),
				9
			);
		}

		if ( current_user_can( 'manage_options' ) ) {
			// Hide submenu pages in woodmart dashboard menu.
			$hide_submenu = array( 'xts_theme_settings_backup', 'xts_theme_settings_presets' );

			foreach ( $submenu['xts_dashboard'] as $key => $xts_dashboard_submenu ) {
				if ( in_array( $xts_dashboard_submenu[2], $hide_submenu, true ) ) {
					$submenu['xts_dashboard'][ $key ][4] = 'xts-hidden'; // phpcs:ignore.
				}
			}
		}
	}

	/**
	 * Get page content callback.
	 */
	public function page_content() {
		$current_page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore
		$wizard       = Setup_Wizard::get_instance();

		if ( ! in_array( $current_page, $this->get_allowed_pages(), true ) ) {
			return;
		}

		if ( $wizard->is_setup() && 'done' !== get_option( 'woodmart_setup_status' ) ) {
			$wizard->setup_wizard_template();
			$this->print_footer();
			return;
		}

		do_action( 'xts_dashboard_before_page' );

		$this->print_header();
		$this->print_template( str_replace( 'xts_', '', $current_page ) );
		$this->print_footer();
	}

	/**
	 * Get allowed pages.
	 *
	 * @return array
	 */
	protected function get_allowed_pages() {
		return array(
			'xts_dashboard',
			'xts_theme_settings_backup',
			'xts_theme_settings_presets',
			'xts_theme_settings',
			'xts_prebuilt_websites',
			'xts_header_builder',
			'xts_license',
			'xts_patcher',
			'xts_changelog',
			'xts_plugins',
			'xts_status',
		);
	}

	/**
	 * Get allowed post types.
	 *
	 * @return array
	 */
	protected function get_allowed_post_types() {
		return array(
			'woodmart_layout',
			'woodmart_slide',
			'woodmart_sidebar',
			'wd_popup',
			'wd_floating_block',
			'cms_block',
		);
	}

	/**
	 * Print header.
	 *
	 * @param array $views Views.
	 *
	 * @return array|false
	 */
	public function print_header( $views = false ) {
		$this->print_template( 'header' );

		return $views;
	}

	/**
	 * Print footer.
	 */
	public function print_footer() {
		$this->print_template( 'footer' );
	}

	/**
	 * Print template file.
	 *
	 * @param string $name Template name.
	 */
	public function print_template( $name ) {
		include_once get_parent_theme_file_path( WOODMART_FRAMEWORK . '/admin/modules/dashboard/templates/' . $name . '.php' );
	}

	/**
	 * Add custom class to body.
	 *
	 * @param string $classes Body classes.
	 *
	 * @return string
	 */
	public function admin_body_classes( $classes ) {
		global $hook_suffix, $post;

		$current_page      = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore
		$current_post_type = isset( $_GET['post_type'] ) ? wp_unslash( $_GET['post_type'] ) : ''; // phpcs:ignore
		$builder           = 'native' === woodmart_get_opt( 'current_builder' ) ? 'gutenberg' : woodmart_get_current_page_builder();
		$classes          .= ' xts-builder-' . $builder;

		if ( in_array( $current_page, $this->get_allowed_pages(), true ) || ( ( 'edit.php' === $hook_suffix || 'edit-tags.php' === $hook_suffix ) && in_array( $current_post_type, $this->get_allowed_post_types(), true ) ) ) {
			$classes = wd_add_cssclass( 'xts-pages', $classes );
		}

		if ( Setup_Wizard::get_instance()->is_setup() && 'done' !== get_option( 'woodmart_setup_status' ) ) {
			$classes = wd_add_cssclass( 'xts-wizard', $classes );
		}

		// Woodmart draws its original icon with a CSS pseudo-element. Whenever a
		// real image icon is present (SHOPAGG by default or a white-label logo),
		// use the existing body class that suppresses that duplicate icon.
		if ( $this->get_logo_url() ) {
			$classes = wd_add_cssclass( 'wd-white-label-img', $classes );
		}

		if ( $post ) {
			if ( 'product' === $post->post_type && woodmart_get_opt( 'enable_gutenberg_for_products' ) ) {
				if ( 'no' !== get_post_meta( $post->ID, '_wd_gutenberg_product_mode', true ) && has_blocks( $post->post_content ) ) {
					$classes = wd_add_cssclass( 'xts-gutenberg-editor-active', $classes );
				} else {
					$classes = wd_add_cssclass( 'xts-gutenberg-editor-inactive', $classes );
				}
			}

			if ( in_array( woodmart_get_opt( 'site_width' ), array( 'boxed', 'boxed-2' ), true ) && in_array( $post->post_type, array( 'post', 'page', 'portfolio', 'woodmart_layout' ), true ) ) {
				$class   = 'boxed' === woodmart_get_opt( 'site_width' ) ? 'xts-wrapper-boxed' : 'xts-wrapper-boxed-2';
				$classes = wd_add_cssclass( $class, $classes );
			}

			$classes = wd_add_cssclass( 'wd', $classes );
		}

		return $classes;
	}

	/**
	 * Admin notice.
	 */
	public function admin_notice() {
		if ( Setup_Wizard::get_instance()->is_setup() || ! current_user_can( 'install_plugins' ) || ( woodmart_is_core_installed() && version_compare( WOODMART_CORE_VERSION, WOODMART_CORE_PLUGIN_VERSION, '==' ) ) ) {
			return;
		}

		global $hook_suffix;

		$current_page      = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore
		$current_post_type = isset( $_GET['post_type'] ) ? wp_unslash( $_GET['post_type'] ) : ''; // phpcs:ignore

		if ( in_array( $current_page, $this->get_allowed_pages(), true ) || ( ( 'edit.php' === $hook_suffix || 'edit-tags.php' === $hook_suffix ) && in_array( $current_post_type, $this->get_allowed_post_types(), true ) ) ) {
			if ( ! woodmart_is_core_installed() ) {
				// translators: %s Admin URL.
				$message = sprintf( __( '<strong>Important:</strong> SHOPAGG Theme Core is not installed. You can install the plugin <a href="%s">here</a>.', 'woodmart' ), esc_url( admin_url( 'admin.php?page=xts_plugins' ) ) );
			} else {
				// translators: %s Admin URL.
				$message = sprintf( __( '<strong>Important:</strong> SHOPAGG Theme Core is outdated. You can update the plugin <a href="%s">here</a>.', 'woodmart' ), esc_url( admin_url( 'admin.php?page=xts_plugins' ) ) );
			}

			?>
			<div class="xts-notice xts-error">
				<?php echo wp_kses( $message, true ); ?>
			</div>
			<?php
		}
	}
}

Dashboard::get_instance();

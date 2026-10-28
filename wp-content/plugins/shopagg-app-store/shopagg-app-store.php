<?php
/**
 * Plugin Name: SHOPAGG App Store
 * Plugin URI: https://shopagg.com
 * Description: 安装并管理来自 SHOPAGG 应用商店的 WordPress 插件和主题。
 * Version: 1.1.1
 * Author: SHOPAGG
 * Author URI: https://shopagg.com
 * Text Domain: shopagg-app-store
 * Requires at least: 6.8
 * Requires PHP: 8.0
 */


if (! defined('ABSPATH')) {
    exit;
}

// Constants
define('SHOPAGG_APP_STORE_VERSION', '1.1.1');
define('SHOPAGG_APP_STORE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPAGG_APP_STORE_PLUGIN_URL', plugin_dir_url(__FILE__));
if (! defined('SHOPAGG_APP_STORE_API_DOMAIN')) {
    define('SHOPAGG_APP_STORE_API_DOMAIN', 'https://www.shopagg.com');
}
define('SHOPAGG_APP_STORE_DEFAULT_API_URL', SHOPAGG_APP_STORE_API_DOMAIN . '/api/shopagg-app-store/');
define('SHOPAGG_APP_STORE_CLIENT_PLUGIN_SLUG', 'shopagg-app-store');
define('SHOPAGG_APP_STORE_TYPE_WORDPRESS_PLUGIN', 'wordpress_plugin');
define('SHOPAGG_APP_STORE_TYPE_WORDPRESS_THEME', 'wordpress_theme');

// Include files
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-auth.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-market.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-installer.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-updater.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-license.php';

/**
 * Check if user is logged in to ShopAGG App Store.
 */
function shopagg_app_store_is_logged_in() {
    $token = get_option('shopagg_app_store_access_token');
    return ! empty($token);
}

/**
 * Get current access token.
 */
function shopagg_app_store_get_token() {
    return get_option('shopagg_app_store_access_token', '');
}

/**
 * Get current user data.
 */
function shopagg_app_store_get_user() {
    return get_option('shopagg_app_store_user', []);
}

/**
 * Check whether a slug belongs to the ShopAGG App Store client plugin itself.
 *
 * @param string $slug
 * @return bool
 */
function shopagg_app_store_is_client_plugin_slug($slug) {
    return sanitize_key((string) $slug) === SHOPAGG_APP_STORE_CLIENT_PLUGIN_SLUG;
}

/**
 * Check whether a resource payload represents the ShopAGG App Store client plugin itself.
 *
 * @param array $resource
 * @return bool
 */
function shopagg_app_store_is_client_resource($resource) {
    return is_array($resource)
        && ! empty($resource['slug'])
        && shopagg_app_store_is_client_plugin_slug($resource['slug']);
}

/**
 * Check whether an API resource belongs in the WordPress client.
 *
 * @param array $resource
 * @return bool
 */
function shopagg_app_store_is_wordpress_resource($resource) {
    return is_array($resource)
        && isset($resource['type'])
        && in_array($resource['type'], [
            SHOPAGG_APP_STORE_TYPE_WORDPRESS_PLUGIN,
            SHOPAGG_APP_STORE_TYPE_WORDPRESS_THEME,
        ], true);
}

/**
 * Convert an App Store resource type to the native WordPress asset type.
 * Legacy values are accepted for resources installed by older client versions.
 *
 * @param string $type
 * @return string
 */
function shopagg_app_store_wordpress_asset_type($type) {
    if (in_array($type, [SHOPAGG_APP_STORE_TYPE_WORDPRESS_PLUGIN, 'plugin'], true)) {
        return 'plugin';
    }

    if (in_array($type, [SHOPAGG_APP_STORE_TYPE_WORDPRESS_THEME, 'theme'], true)) {
        return 'theme';
    }

    return '';
}

/**
 * Find the installed plugin main file for a store resource.
 *
 * Store slugs do not always match the ZIP's top-level directory (for example,
 * a package may unpack to "plugin-slug-1.2.3"). Prefer the file recorded after
 * installation, then use WordPress plugin metadata and a versioned-directory
 * fallback instead of assuming "slug/slug.php".
 *
 * @param string $slug Resource slug.
 * @return string|null Plugin basename or null when not installed.
 */
function shopagg_app_store_find_plugin_file($slug) {
    if (! function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $slug = sanitize_key((string) $slug);
    if ($slug === '') {
        return null;
    }

    $plugins = get_plugins();
    $managed = get_option('shopagg_app_store_managed_resources', []);
    $recorded_file = is_array($managed) && ! empty($managed[$slug]['asset_file'])
        ? plugin_basename((string) $managed[$slug]['asset_file'])
        : '';

    if ($recorded_file !== '' && isset($plugins[$recorded_file])) {
        return $recorded_file;
    }

    foreach ($plugins as $file => $plugin) {
        $directory = dirname($file);
        $text_domain = sanitize_key((string) ($plugin['TextDomain'] ?? ''));

        if ($directory === $slug || $text_domain === $slug || basename($file, '.php') === $slug) {
            return $file;
        }
    }

    $versioned_directory = '/^' . preg_quote($slug, '/') . '(?:[-_.]?v?\d+(?:[.-]\d+)*)$/i';
    foreach (array_keys($plugins) as $file) {
        if (preg_match($versioned_directory, dirname($file))) {
            return $file;
        }
    }

    return null;
}

/**
 * Find the installed theme stylesheet for a store resource.
 *
 * @param string $slug Resource slug.
 * @return string|null Theme stylesheet or null when not installed.
 */
function shopagg_app_store_find_theme_stylesheet($slug) {
    $slug = sanitize_key((string) $slug);
    if ($slug === '') {
        return null;
    }

    $managed = get_option('shopagg_app_store_managed_resources', []);
    $recorded_stylesheet = is_array($managed) && ! empty($managed[$slug]['asset_file'])
        ? sanitize_key((string) $managed[$slug]['asset_file'])
        : '';

    if ($recorded_stylesheet !== '' && wp_get_theme($recorded_stylesheet)->exists()) {
        return $recorded_stylesheet;
    }

    $theme = wp_get_theme($slug);
    if ($theme->exists()) {
        return $theme->get_stylesheet();
    }

    $versioned_directory = '/^' . preg_quote($slug, '/') . '(?:[-_.]?v?\d+(?:[.-]\d+)*)$/i';
    foreach (wp_get_themes() as $stylesheet => $installed_theme) {
        $text_domain = sanitize_key((string) $installed_theme->get('TextDomain'));

        if ($installed_theme->get_template() === $slug || $text_domain === $slug || preg_match($versioned_directory, $stylesheet)) {
            return $stylesheet;
        }
    }

    return null;
}

/**
 * Get ShopAGG dashboard URL from API base URL.
 */
function shopagg_app_store_get_dashboard_url() {
    return SHOPAGG_APP_STORE_API_DOMAIN . '/dashboard/api-tokens';
}

/**
 * Build the connect page URL for binding an API token.
 *
 * @param string $redirect_to Optional admin URL to return to after connecting.
 * @return string
 */
function shopagg_app_store_get_connect_url($redirect_to = '') {
    $args = [
        'page' => 'shopagg-app-store',
        'tab' => 'account',
    ];

    if ($redirect_to !== '') {
        $args['redirect_to'] = $redirect_to;
    }

    return add_query_arg($args, admin_url('admin.php'));
}

/**
 * Build the fixed App Store navigation shared by every client page.
 *
 * @param string $active_tab  Current page/tab.
 * @param string $preset_type Current resource type filter.
 * @return array
 */
function shopagg_app_store_get_shell_navigation($active_tab = 'browse', $preset_type = 'all') {
    $is_connected = shopagg_app_store_is_logged_in();

    return [
        'top_nav' => [
            [
                'label' => '资源库',
                'url' => admin_url('admin.php?page=shopagg-app-store'),
                'active' => in_array($active_tab, ['browse', 'updates'], true),
            ],
            [
                'label' => '订单',
                'url' => admin_url('admin.php?page=shopagg-app-store&tab=orders'),
                'active' => $active_tab === 'orders',
                'disabled' => ! $is_connected,
            ],
            [
                'label' => '账号',
                'url' => shopagg_app_store_get_connect_url(),
                'active' => $active_tab === 'account',
            ],
        ],
        'side_nav' => [
            [
                'title' => '',
                'items' => [
                    [
                        'label' => '全部资源',
                        'url' => admin_url('admin.php?page=shopagg-app-store'),
                        'active' => $active_tab === 'browse' && $preset_type === 'all',
                    ],
                    [
                        'label' => '插件',
                        'url' => admin_url('admin.php?page=shopagg-app-store&tab=plugins'),
                        'active' => $active_tab === 'browse' && $preset_type === 'plugin',
                    ],
                    [
                        'label' => '主题',
                        'url' => admin_url('admin.php?page=shopagg-app-store&tab=themes'),
                        'active' => $active_tab === 'browse' && $preset_type === 'theme',
                    ],
                    [
                        'label' => '可更新',
                        'url' => admin_url('admin.php?page=shopagg-app-store&tab=updates'),
                        'active' => $active_tab === 'updates',
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Normalize the current WordPress site domain for license binding.
 */
function shopagg_app_store_get_site_domain() {
    $home_url = home_url('/');
    $host = wp_parse_url($home_url, PHP_URL_HOST);
    $path = wp_parse_url($home_url, PHP_URL_PATH);

    if (! is_string($host) || $host === '') {
        return untrailingslashit($home_url);
    }

    $normalized = strtolower($host);
    $path = is_string($path) ? trim($path) : '';

    if ($path !== '' && $path !== '/') {
        $normalized .= '/' . trim($path, '/');
    }

    return $normalized;
}

/**
 * Render the shared admin layout shell.
 *
 * @param array $args Layout config.
 */
function shopagg_app_store_render_admin_shell_start($args = []) {
    $top_nav = isset($args['top_nav']) && is_array($args['top_nav']) ? $args['top_nav'] : [];
    $side_nav = isset($args['side_nav']) && is_array($args['side_nav']) ? $args['side_nav'] : [];
    $is_connected = shopagg_app_store_is_logged_in();
    $user = shopagg_app_store_get_user();
    ?>
    <div class="wrap shopagg-app-store-wrap">
        <hr class="wp-header-end">
        <div class="shopagg-admin-layout">
            <header class="shopagg-admin-header">
                <div class="shopagg-admin-brand">
                    <img src="<?php echo esc_url(SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/images/shopagg.svg'); ?>" alt="ShopAGG" class="shopagg-admin-brand-logo" aria-hidden="true">
                    <div>
                        <strong><i>SHOPAGG</i></strong>
                        <span>WordPress 应用商店</span>
                    </div>
                    <button type="button"
                            class="shopagg-admin-sidebar-toggle"
                            aria-expanded="false"
                            aria-controls="shopagg-admin-sidebar-panel">
                        导航
                    </button>
                </div>

                <nav class="shopagg-admin-topnav" aria-label="主导航">
                    <?php foreach ($top_nav as $item) : ?>
                        <?php
                        $label = isset($item['label']) ? (string) $item['label'] : '';
                        $url = isset($item['url']) ? (string) $item['url'] : '';
                        $active = ! empty($item['active']);
                        $disabled = ! empty($item['disabled']);
                        ?>
                        <?php if ($disabled) : ?>
                            <span class="shopagg-admin-topnav-link is-disabled"><?php echo esc_html($label); ?></span>
                        <?php else : ?>
                            <a class="shopagg-admin-topnav-link <?php echo $active ? 'is-active' : ''; ?>" href="<?php echo esc_url($url); ?>">
                                <?php echo esc_html($label); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>

                <div class="shopagg-admin-account">
                    <span class="shopagg-admin-account-status <?php echo $is_connected ? 'is-connected' : 'is-disconnected'; ?>">
                        <?php echo $is_connected ? '已连接' : '未连接'; ?>
                    </span>
                    <div class="shopagg-admin-account-meta">
                        <strong><?php echo esc_html($is_connected ? ($user['name'] ?? '当前账号') : '请先连接 API Token'); ?></strong>
                        <span><?php echo esc_html($is_connected ? ($user['email'] ?? shopagg_app_store_get_site_domain()) : shopagg_app_store_get_site_domain()); ?></span>
                    </div>
                </div>
            </header>

            <div class="shopagg-admin-body">
                <aside class="shopagg-admin-sidebar" id="shopagg-admin-sidebar-panel">
                    <?php foreach ($side_nav as $group) : ?>
                        <?php
                        $group_title = isset($group['title']) ? (string) $group['title'] : '';
                        $items = isset($group['items']) && is_array($group['items']) ? $group['items'] : [];
                        ?>
                        <section class="shopagg-admin-sidebar-group">
                            <?php if ($group_title !== '') : ?>
                                <h2><?php echo esc_html($group_title); ?></h2>
                            <?php endif; ?>

                            <div class="shopagg-admin-sidebar-links">
                                <?php foreach ($items as $item) : ?>
                                    <?php
                                    $label = isset($item['label']) ? (string) $item['label'] : '';
                                    $url = isset($item['url']) ? (string) $item['url'] : '';
                                    $active = ! empty($item['active']);
                                    $disabled = ! empty($item['disabled']);
                                    $target = ! empty($item['target']) ? (string) $item['target'] : '';
                                    ?>
                                    <?php if ($disabled) : ?>
                                        <span class="shopagg-admin-sidebar-link is-disabled"><?php echo esc_html($label); ?></span>
                                    <?php else : ?>
                                        <a class="shopagg-admin-sidebar-link <?php echo $active ? 'is-active' : ''; ?>"
                                           href="<?php echo esc_url($url); ?>"
                                           <?php echo $target !== '' ? 'target="' . esc_attr($target) . '" rel="noopener noreferrer"' : ''; ?>>
                                            <?php echo esc_html($label); ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </aside>

                <main class="shopagg-admin-main">
    <?php
}

/**
 * Close the shared admin layout shell.
 */
function shopagg_app_store_render_admin_shell_end() {
    ?>
                </main>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Clear cached license check result for a resource.
 */
function shopagg_app_store_forget_license_cache($resource_id) {
    $resource_id = absint($resource_id);

    if ($resource_id) {
        delete_transient('shopagg_license_' . $resource_id);
    }
}

/**
 * Initialize the plugin.
 */
function shopagg_app_store_init() {
    // Initialize components
    ShopAGG_App_Store_Auth::instance();
    ShopAGG_App_Store_Market::instance();
    ShopAGG_App_Store_Installer::instance();
    ShopAGG_App_Store_Updater::instance();
    ShopAGG_App_Store_License::instance();
}
add_action('plugins_loaded', 'shopagg_app_store_init');

/**
 * Add admin menu.
 */
function shopagg_app_store_admin_menu() {
    add_menu_page(
        'ShopAGG 应用商店',
        '应用商店',
        'manage_options',
        'shopagg-app-store',
        'shopagg_app_store_render_page',
        'dashicons-store',
        30
    );
}
add_action('admin_menu', 'shopagg_app_store_admin_menu');

/**
 * Main page render.
 */
function shopagg_app_store_render_page() {
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'browse';
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    $resource_id = isset($_GET['resource_id']) ? absint($_GET['resource_id']) : 0;
    $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';

    if ($action === 'connect' || $tab === 'account') {
        if (shopagg_app_store_is_logged_in() && ! empty($redirect_to)) {
            wp_safe_redirect($redirect_to);
            exit;
        }

        ShopAGG_App_Store_Auth::instance()->render_login_page();
        return;
    }

    if ($action === 'checkout' && $resource_id > 0) {
        ShopAGG_App_Store_Market::instance()->render_checkout_page($resource_id);
        return;
    }

    if ($action === 'detail' && $resource_id > 0) {
        ShopAGG_App_Store_Market::instance()->render_detail_page($resource_id);
        return;
    }

    ShopAGG_App_Store_Market::instance()->render_market_page($tab);
}

/**
 * Enqueue admin styles and scripts.
 */
function shopagg_app_store_admin_enqueue($hook) {
    if (strpos($hook, 'shopagg-app-store') === false) {
        return;
    }

    $css_file = SHOPAGG_APP_STORE_PLUGIN_DIR . 'assets/css/style.css';
    $js_file  = SHOPAGG_APP_STORE_PLUGIN_DIR . 'assets/js/app.js';

    wp_enqueue_style(
        'shopagg-app-store-css',
        SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/css/style.css',
        [],
        filemtime($css_file)
    );

    wp_enqueue_script(
        'shopagg-app-store-js',
        SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/js/app.js',
        ['jquery'],
        filemtime($js_file),
        true
    );

    wp_localize_script('shopagg-app-store-js', 'shopaggAppStore', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('shopagg_app_store_nonce'),
        'i18n'    => [
            'existingOrderIntro' => '此资源已有未付款订单。请完成付款以继续。',
            'orderCreatedIntro' => '您的订单已生成。请选择一种付款方式继续。',
            'choosePaymentMethod' => '请选择付款方式。',
            'startingPayment' => '正在发起支付...',
            'completeAlipayInWindow' => '请在新窗口中完成支付宝付款。',
            'popupBlocked' => '浏览器阻止了弹出窗口。请允许弹出窗口并重试。',
            'scanWechat' => '请用微信扫描二维码完成支付。',
            'paymentSuccessInstall' => '付款成功。您现在可以安装',
            'connecting' => '连接中...',
            'connect' => '连接',
            'connectionFailed' => '连接失败。请重试。',
            'installing' => '正在安装...',
            'install' => '安装',
            'processing' => '处理中...',
            'retry' => '重试',
            'creatingOrder' => '正在创建订单...',
            'purchase' => '购买',
            'savingReview' => '正在保存评价...',
            'publishReview' => '发布评价',
            'updateReview' => '更新评价',
            'reviewSaved' => '你的评价已保存。',
            'reviewSaveFailed' => '保存评价失败，请重试。',
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'shopagg_app_store_admin_enqueue');

/**
 * Plugin activation.
 */
function shopagg_app_store_activate() {
    // Nothing specific to do on activation for now
}
register_activation_hook(__FILE__, 'shopagg_app_store_activate');

/**
 * Plugin deactivation - clean up tokens.
 */
function shopagg_app_store_deactivate() {
    delete_option('shopagg_app_store_access_token');
    delete_option('shopagg_app_store_user');
    delete_option('shopagg_app_store_managed_resources');
}
register_deactivation_hook(__FILE__, 'shopagg_app_store_deactivate');

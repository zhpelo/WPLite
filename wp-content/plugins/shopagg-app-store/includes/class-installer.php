<?php
/**
 * ShopAGG App Store Installer
 *
 * Handles installation of plugins and themes from ShopAGG App Store.
 */


if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_Installer {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Install a resource by ID.
     *
     * @param int $resource_id
        * @return array|WP_Error
     */
    public function install($resource_id) {
        if (! shopagg_app_store_is_logged_in()) {
            return new WP_Error('not_logged_in', '您必须登录后才能安装资源。');
        }

        $api = ShopAGG_App_Store_API_Client::instance();

        // Get resource info to determine type
        $resource_info = $api->get('resources/' . $resource_id);
        if (is_wp_error($resource_info)) {
            return $resource_info;
        }

        $resource = $resource_info['resource'];

        if (shopagg_app_store_is_client_resource($resource)) {
            return new WP_Error('managed_separately', 'ShopAGG 应用商店插件通过独立渠道更新，不能在市场内部安装。');
        }

        if (! shopagg_app_store_is_wordpress_resource($resource)) {
            return new WP_Error('invalid_type', '该资源不属于 WordPress 插件或主题。');
        }

        $type = shopagg_app_store_wordpress_asset_type($resource['type']);

        // Do not reinstall a resource that WordPress already knows about. The
        // package directory may include a version and therefore differ from the
        // resource slug.
        $existing_asset = $type === 'plugin'
            ? $this->find_plugin_file($resource['slug'])
            : $this->find_theme_stylesheet($resource['slug']);

        if (! empty($existing_asset)) {
            $normalized_asset = $this->normalize_existing_plugin_directory($resource['slug'], $type, $existing_asset);
            if (is_wp_error($normalized_asset)) {
                return $normalized_asset;
            }
            $existing_asset = $normalized_asset;

            ShopAGG_App_Store_Updater::register_managed_resource(
                $resource['slug'],
                $type,
                $resource['id'],
                $existing_asset
            );

            return $this->build_install_result($resource, $type, $existing_asset, true);
        }

        // Get download URL from API
        $result = $api->get('download/' . $resource_id, [
            'domain' => shopagg_app_store_get_site_domain(),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['download_url'])) {
            return new WP_Error('no_download_url', '获取下载 URL 失败。');
        }

        // Download the package file ourselves using wp_remote_get()
        // This avoids wp_safe_remote_get() which blocks local/private IPs
        $tmp_file = $this->download_package($result['download_url']);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        // Install based on type
        if ($type === 'plugin') {
            $install_result = $this->install_from_file($tmp_file, 'plugin', $resource['slug']);
        } elseif ($type === 'theme') {
            $install_result = $this->install_from_file($tmp_file, 'theme', $resource['slug']);
        } else {
            @unlink($tmp_file);
            return new WP_Error('invalid_type', '资源类型无效。');
        }

        // Register for auto-updates tracking
        if (is_array($install_result)) {
            wp_clean_plugins_cache(true);
            wp_clean_themes_cache(true);

            $asset_file = ! empty($install_result['target'])
                ? $install_result['target']
                : ($type === 'plugin'
                    ? $this->find_plugin_file($resource['slug'])
                    : $this->find_theme_stylesheet($resource['slug']));

            if (empty($asset_file)) {
                return new WP_Error(
                    'installed_asset_not_found',
                    $type === 'plugin'
                        ? '插件已解压，但 WordPress 未能识别插件主文件。请检查 ZIP 包目录和插件头信息。'
                        : '主题已解压，但 WordPress 未能识别主题目录。请检查 ZIP 包结构和 style.css。'
                );
            }

            ShopAGG_App_Store_Updater::register_managed_resource(
                $resource['slug'],
                $type,
                $resource['id'],
                $asset_file
            );

            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');
            wp_clean_themes_cache();

            return $this->build_install_result($resource, $type, $asset_file, false);
        }

        return $install_result;
    }

    /**
     * Build activation URL for installed resource.
     *
     * @param string $type
     * @param string $slug
     * @return string
     */
    private function get_activate_url($type, $slug) {
        if ($type === 'plugin') {
            $plugin_file = $this->find_plugin_file($slug);
            if (empty($plugin_file)) {
                return '';
            }

            return wp_nonce_url(
                admin_url('plugins.php?action=activate&plugin=' . rawurlencode($plugin_file)),
                'activate-plugin_' . $plugin_file
            );
        }

        if ($type === 'theme') {
            return wp_nonce_url(
                admin_url('themes.php?action=activate&stylesheet=' . rawurlencode($slug)),
                'switch-theme_' . $slug
            );
        }

        return '';
    }

    /**
     * Find plugin main file path by slug.
     *
     * @param string $slug
     * @return string|null
     */
    private function find_plugin_file($slug) {
        return shopagg_app_store_find_plugin_file($slug);
    }

    /**
     * Find installed theme stylesheet by slug.
     *
     * @param string $slug
     * @return string
     */
    private function find_theme_stylesheet($slug) {
        return shopagg_app_store_find_theme_stylesheet($slug);
    }

    private function build_install_result($resource, $type, $asset_file, $already_installed) {
        return [
            'installed' => true,
            'already_installed' => (bool) $already_installed,
            'type' => $type,
            'target' => $asset_file,
            'active' => $type === 'plugin'
                ? $this->is_plugin_active($asset_file)
                : $this->is_theme_active($asset_file),
            'activate_url' => $this->get_activate_url($type, $resource['slug']),
            'activate_label' => $type === 'plugin' ? '激活插件' : '激活主题',
        ];
    }

    /**
     * Move a legacy plugin directory to the canonical resource slug.
     *
     * @param string $slug       Resource slug.
     * @param string $type       Native WordPress resource type.
     * @param string $asset_file Plugin basename or theme stylesheet.
     * @return string|WP_Error Canonical asset identifier on success.
     */
    public function normalize_existing_plugin_directory($slug, $type, $asset_file) {
        if ($type !== 'plugin' || empty($asset_file)) {
            return $asset_file;
        }

        $slug = sanitize_key((string) $slug);
        $asset_file = plugin_basename((string) $asset_file);
        $current_directory = dirname($asset_file);

        if ($slug === '' || $current_directory === $slug) {
            return $asset_file;
        }

        if ($current_directory === '.' || strpos($current_directory, '/') !== false) {
            return new WP_Error('invalid_plugin_directory', '现有插件目录结构无效，无法按资源 slug 规范化。');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (! WP_Filesystem()) {
            return new WP_Error('filesystem_unavailable', '无法连接 WordPress 文件系统，插件目录未能重命名。');
        }

        global $wp_filesystem;

        $source = trailingslashit(WP_PLUGIN_DIR) . $current_directory;
        $destination = trailingslashit(WP_PLUGIN_DIR) . $slug;

        if ($wp_filesystem->exists($destination)) {
            return new WP_Error('canonical_directory_exists', sprintf('插件目录 %s 已存在，请先处理目录冲突。', $slug));
        }

        $moved = move_dir($source, $destination, false);
        if (is_wp_error($moved)) {
            return $moved;
        }

        $relative_main_file = ltrim(substr($asset_file, strlen($current_directory)), '/');
        $canonical_file = $slug . '/' . $relative_main_file;

        $active_plugins = get_option('active_plugins', []);
        if (is_array($active_plugins)) {
            $active_plugins = array_map(function ($plugin_file) use ($asset_file, $canonical_file) {
                return $plugin_file === $asset_file ? $canonical_file : $plugin_file;
            }, $active_plugins);
            update_option('active_plugins', $active_plugins);
        }

        if (is_multisite()) {
            $network_active = get_site_option('active_sitewide_plugins', []);
            if (is_array($network_active) && isset($network_active[$asset_file])) {
                $activated_at = $network_active[$asset_file];
                unset($network_active[$asset_file]);
                $network_active[$canonical_file] = $activated_at;
                update_site_option('active_sitewide_plugins', $network_active);
            }
        }

        wp_clean_plugins_cache(true);

        return $canonical_file;
    }

    private function is_plugin_active($plugin_file) {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return ! empty($plugin_file) && is_plugin_active($plugin_file);
    }

    private function is_theme_active($stylesheet) {
        return ! empty($stylesheet) && get_stylesheet() === $stylesheet;
    }

    /**
     * Download a package file from URL.
     *
     * Uses wp_remote_get() instead of wp_safe_remote_get() to allow
     * downloading from local/private network addresses.
     *
     * @param string $url
     * @return string|WP_Error Path to temp file on success.
     */
    private function download_package($url) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp_file = wp_tempnam($url);
        if (! $tmp_file) {
            return new WP_Error('tmp_file_error', '无法创建临时文件。');
        }

        $response = wp_remote_get($url, [
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $tmp_file,
        ]);

        if (is_wp_error($response)) {
            @unlink($tmp_file);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            @unlink($tmp_file);
            return new WP_Error(
                'download_failed',
                sprintf('下载失败，HTTP 状态为 %d。', $code)
            );
        }

        $filesize = filesize($tmp_file);
        if ($filesize === 0) {
            @unlink($tmp_file);
            return new WP_Error('download_empty', '下载的文件为空。');
        }

        return $tmp_file;
    }

    /**
     * Install a plugin or theme from a pre-downloaded local file.
     *
     * Hooks into upgrader_pre_download to provide the local file,
     * bypassing WordPress's built-in download (which uses wp_safe_remote_get).
     *
     * @param string $tmp_file Path to downloaded ZIP file.
     * @param string $type     'plugin' or 'theme'.
     * @param string $slug     Canonical resource slug.
     * @return array|WP_Error
     */
    private function install_from_file($tmp_file, $type, $slug) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ($type === 'plugin') {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        } else {
            require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        }

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = ($type === 'plugin')
            ? new Plugin_Upgrader($skin)
            : new Theme_Upgrader($skin);

        // Hook upgrader_pre_download to skip WP's download and use our local file
        $filter = function ($reply, $package) use ($tmp_file) {
            if ($package === 'shopagg://local-package') {
                return $tmp_file;
            }
            return $reply;
        };
        add_filter('upgrader_pre_download', $filter, 10, 2);

        // WordPress uses the extracted root directory as the final install
        // directory. Rename that temporary source first so the local directory
        // is always identical to the backend resource slug.
        $source_filter = function ($source) use ($slug) {
            if (is_wp_error($source)) {
                return $source;
            }

            global $wp_filesystem;

            $slug = sanitize_key((string) $slug);
            $source = trailingslashit($source);
            if ($slug === '' || basename(untrailingslashit($source)) === $slug) {
                return $source;
            }

            $canonical_source = trailingslashit(dirname(untrailingslashit($source))) . trailingslashit($slug);
            if ($wp_filesystem->exists($canonical_source)) {
                return new WP_Error('canonical_source_exists', '解压目录中已存在与资源 slug 同名的目录。');
            }

            $moved = move_dir($source, $canonical_source, false);

            return is_wp_error($moved) ? $moved : $canonical_source;
        };
        add_filter('upgrader_source_selection', $source_filter, 20, 1);

        $result = $upgrader->install('shopagg://local-package');

        remove_filter('upgrader_pre_download', $filter, 10);
        remove_filter('upgrader_source_selection', $source_filter, 20);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            $errors = $skin->get_errors();
            if (is_wp_error($errors) && $errors->has_errors()) {
                return $errors;
            }
            return new WP_Error('install_failed',
                $type === 'plugin'
                    ? '插件安装失败。'
                    : '主题安装失败。'
            );
        }

        if ($type === 'plugin') {
            $target = $upgrader->plugin_info();
        } else {
            $theme = $upgrader->theme_info();
            $target = $theme instanceof WP_Theme ? $theme->get_stylesheet() : '';
        }

        return ['target' => $target ?: ''];
    }
}

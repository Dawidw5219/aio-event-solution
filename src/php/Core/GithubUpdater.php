<?php

namespace App4You;

/**
 * GithubUpdater — universal drop-in GitHub-release updater for WordPress plugins.
 *
 * Add `GitHub URI: owner/repo` to the plugin header, then in the main plugin file:
 *
 *   require_once __DIR__ . '/includes/GithubUpdater.php';
 *
 * That's it. The loader scans every installed plugin, picks up those carrying
 * a `GitHub URI` header, and registers an updater for each. If several plugins
 * ship this same file, the first-loaded copy wins via class_exists guard and
 * still handles the rest.
 *
 * Releases MUST include a .zip asset — no zipball fallback on purpose
 * (GitHub's zipball pulls the whole repo including dev files).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(__NAMESPACE__ . '\\GithubUpdater')) {

    class GithubUpdater
    {
        const VERSION         = '1.0.0';
        const CACHE_TTL       = 6 * HOUR_IN_SECONDS;
        const FAILURE_TTL     = HOUR_IN_SECONDS;
        const RATE_LIMIT_FLAG = 'github_updater_rate_limited';

        private $plugin_slug;
        private $plugin_file;
        private $github_repo;
        private $plugin_data;
        private $github_response;

        public function __construct($plugin_file)
        {
            $this->plugin_file = $plugin_file;
            $this->plugin_slug = plugin_basename($plugin_file);
            $this->plugin_data = get_file_data($plugin_file, [
                'Version'     => 'Version',
                'Name'        => 'Plugin Name',
                'Author'      => 'Author',
                'AuthorURI'   => 'Author URI',
                'PluginURI'   => 'Plugin URI',
                'Description' => 'Description',
                'RequiresPHP' => 'Requires PHP',
                'RequiresWP'  => 'Requires at least',
                'GitHubURI'   => 'GitHub URI',
            ]);

            $this->github_repo = $this->plugin_data['GitHubURI'] ?? '';
        }

        public static function bootstrap()
        {
            static $booted = false;
            if ($booted) {
                return;
            }
            $booted = true;

            if (!is_admin() && !wp_doing_cron()) {
                return;
            }

            add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'filter_update_plugins']);
            add_filter('plugins_api', [__CLASS__, 'filter_plugins_api'], 20, 3);
            add_filter('upgrader_post_install', [__CLASS__, 'filter_post_install'], 10, 3);
            add_action('admin_notices', [__CLASS__, 'render_rate_limit_notice']);
        }

        /**
         * Scan every installed plugin for `GitHub URI` and build one updater per match.
         * Memoized — disk scan runs once per request.
         */
        public static function instances()
        {
            static $instances = null;
            if ($instances !== null) {
                return $instances;
            }

            $instances = [];

            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            foreach (get_plugins() as $plugin_slug => $_data) {
                $full_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
                $headers   = get_file_data($full_path, ['GitHubURI' => 'GitHub URI']);

                if (!empty($headers['GitHubURI'])) {
                    $instances[$plugin_slug] = new self($full_path);
                }
            }

            return $instances;
        }

        public static function filter_update_plugins($transient)
        {
            foreach (self::instances() as $updater) {
                $transient = $updater->check_update($transient);
            }
            return $transient;
        }

        public static function filter_plugins_api($result, $action, $args)
        {
            if ($action !== 'plugin_information') {
                return $result;
            }

            foreach (self::instances() as $updater) {
                $maybe = $updater->plugin_info($result, $action, $args);
                if ($maybe !== $result) {
                    return $maybe;
                }
            }
            return $result;
        }

        public static function filter_post_install($response, $hook_extra, $result)
        {
            if (empty($hook_extra['plugin'])) {
                return $result;
            }

            $instances = self::instances();
            if (isset($instances[$hook_extra['plugin']])) {
                return $instances[$hook_extra['plugin']]->after_install($response, $hook_extra, $result);
            }
            return $result;
        }

        public static function render_rate_limit_notice()
        {
            if (!current_user_can('update_plugins')) {
                return;
            }
            if (!get_transient(self::RATE_LIMIT_FLAG)) {
                return;
            }
            echo '<div class="notice notice-warning is-dismissible"><p><strong>GithubUpdater:</strong> GitHub API rate limit reached — plugin update checks paused for up to 1 hour.</p></div>';
        }

        private function cache_key()
        {
            return 'github_updater_' . md5(strtolower($this->github_repo));
        }

        private function get_github_release()
        {
            if (!empty($this->github_response)) {
                return $this->github_response;
            }

            if (empty($this->github_repo)) {
                return null;
            }

            $repo_parts = explode('/', $this->github_repo);
            if (count($repo_parts) !== 2) {
                return null;
            }

            $cache_key = $this->cache_key();

            // Honour WP "Check again" force-check — bypasses our cache too.
            $force_check = !empty($_GET['force-check']) && is_admin();

            if (!$force_check) {
                $cached = get_transient($cache_key);
                if ($cached === 'failure') {
                    return null;
                }
                if ($cached !== false) {
                    $this->github_response = $cached;
                    return $cached;
                }
            }

            $url = sprintf(
                'https://api.github.com/repos/%s/%s/releases/latest',
                trim($repo_parts[0]),
                trim($repo_parts[1])
            );

            $response = wp_remote_get($url, [
                'headers' => [
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                ],
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) {
                set_transient($cache_key, 'failure', self::FAILURE_TTL);
                return null;
            }

            $code = wp_remote_retrieve_response_code($response);

            if ($code === 403 || $code === 429) {
                set_transient(self::RATE_LIMIT_FLAG, 1, self::FAILURE_TTL);
                set_transient($cache_key, 'failure', self::FAILURE_TTL);
                return null;
            }

            if ($code !== 200) {
                set_transient($cache_key, 'failure', self::FAILURE_TTL);
                return null;
            }

            $data = json_decode(wp_remote_retrieve_body($response));
            if (empty($data)) {
                set_transient($cache_key, 'failure', self::FAILURE_TTL);
                return null;
            }

            set_transient($cache_key, $data, self::CACHE_TTL);
            $this->github_response = $data;
            return $data;
        }

        public function check_update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }

            $release = $this->get_github_release();
            if (empty($release) || empty($release->tag_name)) {
                return $transient;
            }

            $current_version = $this->plugin_data['Version'] ?? '0.0.0';
            $latest_version  = ltrim($release->tag_name, 'v');

            if (version_compare($latest_version, $current_version, '>')) {
                $download_url = $this->get_download_url($release);

                if ($download_url) {
                    $transient->response[$this->plugin_slug] = (object) [
                        'slug'         => dirname($this->plugin_slug),
                        'plugin'       => $this->plugin_slug,
                        'new_version'  => $latest_version,
                        'url'          => $release->html_url ?? '',
                        'package'      => $download_url,
                        'icons'        => [],
                        'banners'      => [],
                        'tested'       => '',
                        'requires_php' => $this->plugin_data['RequiresPHP'] ?? '',
                    ];
                }
            }

            return $transient;
        }

        private function get_download_url($release)
        {
            if (!empty($release->assets) && is_array($release->assets)) {
                foreach ($release->assets as $asset) {
                    if (isset($asset->name) && str_ends_with($asset->name, '.zip')) {
                        return $asset->browser_download_url ?? $asset->url;
                    }
                }
            }

            return null;
        }

        public function plugin_info($result, $action, $args)
        {
            if (dirname($this->plugin_slug) !== ($args->slug ?? '')) {
                return $result;
            }

            $release = $this->get_github_release();
            if (empty($release)) {
                return $result;
            }

            $latest_version = ltrim($release->tag_name ?? '', 'v');

            return (object) [
                'name'           => $this->plugin_data['Name'] ?? dirname($this->plugin_slug),
                'slug'           => dirname($this->plugin_slug),
                'version'        => $latest_version,
                'author'         => $this->plugin_data['Author'] ?? '',
                'author_profile' => $this->plugin_data['AuthorURI'] ?? '',
                'homepage'       => $this->plugin_data['PluginURI'] ?? ($release->html_url ?? ''),
                'requires'       => $this->plugin_data['RequiresWP'] ?? '',
                'tested'         => '',
                'requires_php'   => $this->plugin_data['RequiresPHP'] ?? '',
                'downloaded'     => 0,
                'last_updated'   => $release->published_at ?? '',
                'sections'       => [
                    'description' => $this->plugin_data['Description'] ?? '',
                    'changelog'   => $this->format_changelog($release->body ?? ''),
                ],
                'download_link'  => $this->get_download_url($release),
            ];
        }

        private function format_changelog($body)
        {
            if (empty($body)) {
                return '<p>No changelog available.</p>';
            }

            $html = esc_html($body);
            $html = nl2br($html);
            $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
            $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
            $html = preg_replace('/^- /m', '• ', $html);

            return '<div class="changelog">' . $html . '</div>';
        }

        public function after_install($response, $hook_extra, $result)
        {
            global $wp_filesystem;

            if (empty($wp_filesystem)) {
                return $result;
            }

            $was_active         = is_plugin_active($this->plugin_slug);
            $plugin_folder      = dirname($this->plugin_slug);
            $proper_destination = WP_PLUGIN_DIR . '/' . $plugin_folder;

            if ($result['destination'] !== $proper_destination) {
                $wp_filesystem->move($result['destination'], $proper_destination);
                $result['destination'] = $proper_destination;
            }

            if ($was_active) {
                activate_plugin($this->plugin_slug);
            }

            return $result;
        }
    }

    GithubUpdater::bootstrap();
}

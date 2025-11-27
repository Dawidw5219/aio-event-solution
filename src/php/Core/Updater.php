<?php

namespace AIOEvents\Core;

/**
 * GitHub Updater for WordPress plugins
 * Checks for updates from GitHub releases and allows updating through WP admin
 * 
 * Add "GitHub URI: owner/repo" to your plugin header to enable
 */
class Updater
{
  private $plugin_slug;
  private $plugin_file;
  private $github_repo;
  private $plugin_data;
  private $github_response;

  /**
   * Initialize the updater
   *
   * @param string $plugin_file Main plugin file path
   */
  public function __construct($plugin_file)
  {
    $this->plugin_file = $plugin_file;
    $this->plugin_slug = plugin_basename($plugin_file);
    $this->plugin_data = get_file_data($plugin_file, [
      'Version' => 'Version',
      'Name' => 'Plugin Name',
      'Author' => 'Author',
      'AuthorURI' => 'Author URI',
      'PluginURI' => 'Plugin URI',
      'Description' => 'Description',
      'RequiresPHP' => 'Requires PHP',
      'RequiresWP' => 'Requires at least',
      'GitHubURI' => 'GitHub URI',
    ]);

    $this->github_repo = $this->plugin_data['GitHubURI'] ?? '';

    if (empty($this->github_repo)) {
      return; // No GitHub URI configured
    }

    add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
    add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
    add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
  }

  /**
   * Get latest release from GitHub
   */
  private function get_github_release()
  {
    if (!empty($this->github_response)) {
      return $this->github_response;
    }

    if (empty($this->github_repo)) {
      return null;
    }

    // Parse repo (format: owner/repo)
    $repo_parts = explode('/', $this->github_repo);
    if (count($repo_parts) !== 2) {
      return null;
    }

    $url = sprintf(
      'https://api.github.com/repos/%s/%s/releases/latest',
      trim($repo_parts[0]),
      trim($repo_parts[1])
    );

    $args = [
      'headers' => [
        'Accept' => 'application/vnd.github.v3+json',
        'User-Agent' => 'WordPress/' . get_bloginfo('version'),
      ],
      'timeout' => 10,
    ];

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
      return null;
    }

    $this->github_response = json_decode(wp_remote_retrieve_body($response));
    return $this->github_response;
  }

  /**
   * Check for plugin updates
   */
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

    // Remove 'v' prefix from tag if present
    $latest_version = ltrim($release->tag_name, 'v');

    if (version_compare($latest_version, $current_version, '>')) {
      // Find the zip asset
      $download_url = $this->get_download_url($release);

      if ($download_url) {
        $transient->response[$this->plugin_slug] = (object) [
          'slug' => dirname($this->plugin_slug),
          'plugin' => $this->plugin_slug,
          'new_version' => $latest_version,
          'url' => $release->html_url ?? '',
          'package' => $download_url,
          'icons' => [],
          'banners' => [],
          'tested' => '',
          'requires_php' => $this->plugin_data['RequiresPHP'] ?? '',
        ];
      }
    }

    return $transient;
  }

  /**
   * Get download URL from release
   */
  private function get_download_url($release)
  {
    // First try to find a .zip asset
    if (!empty($release->assets) && is_array($release->assets)) {
      foreach ($release->assets as $asset) {
        if (isset($asset->name) && str_ends_with($asset->name, '.zip')) {
          return $asset->browser_download_url ?? $asset->url;
        }
      }
    }

    // Fallback to zipball
    return $release->zipball_url ?? null;
  }

  /**
   * Plugin info for the update details popup
   */
  public function plugin_info($result, $action, $args)
  {
    if ($action !== 'plugin_information') {
      return $result;
    }

    if (dirname($this->plugin_slug) !== ($args->slug ?? '')) {
      return $result;
    }

    $release = $this->get_github_release();
    if (empty($release)) {
      return $result;
    }

    $latest_version = ltrim($release->tag_name ?? '', 'v');

    return (object) [
      'name' => $this->plugin_data['Name'] ?? 'AIO Event Solution',
      'slug' => dirname($this->plugin_slug),
      'version' => $latest_version,
      'author' => $this->plugin_data['Author'] ?? '',
      'author_profile' => $this->plugin_data['AuthorURI'] ?? '',
      'homepage' => $this->plugin_data['PluginURI'] ?? $release->html_url ?? '',
      'requires' => $this->plugin_data['RequiresWP'] ?? '',
      'tested' => '',
      'requires_php' => $this->plugin_data['RequiresPHP'] ?? '',
      'downloaded' => 0,
      'last_updated' => $release->published_at ?? '',
      'sections' => [
        'description' => $this->plugin_data['Description'] ?? '',
        'changelog' => $this->format_changelog($release->body ?? ''),
      ],
      'download_link' => $this->get_download_url($release),
    ];
  }

  /**
   * Format changelog from GitHub release body
   */
  private function format_changelog($body)
  {
    if (empty($body)) {
      return '<p>No changelog available.</p>';
    }

    // Convert markdown to basic HTML
    $html = esc_html($body);
    $html = nl2br($html);
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
    $html = preg_replace('/^- /m', '• ', $html);

    return '<div class="changelog">' . $html . '</div>';
  }

  /**
   * After install - rename folder to match plugin slug
   */
  public function after_install($response, $hook_extra, $result)
  {
    global $wp_filesystem;

    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
      return $result;
    }

    // GitHub zipball extracts to owner-repo-hash folder
    // We need to rename it to match the plugin folder name
    $plugin_folder = dirname($this->plugin_slug);
    $proper_destination = WP_PLUGIN_DIR . '/' . $plugin_folder;

    if ($result['destination'] !== $proper_destination) {
      $wp_filesystem->move($result['destination'], $proper_destination);
      $result['destination'] = $proper_destination;
    }

    // Re-activate plugin
    activate_plugin($this->plugin_slug);

    return $result;
  }
}


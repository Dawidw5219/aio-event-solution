<?php

namespace AIOEvents\Core;

/**
 * Manages frontend and admin asset enqueueing
 */
class Assets
{
  /**
   * Register asset hooks
   */
  public static function register()
  {
    add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin']);
    add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend']);
  }

  /**
   * Enqueue admin assets
   */
  public static function enqueue_admin($hook)
  {
    if (!self::should_load_admin_assets($hook)) {
      return;
    }

    $colors = self::get_theme_colors();

    wp_enqueue_style(
      'aio-events-admin',
      AIO_EVENTS_URL . 'assets/css/generated/admin.min.css',
      [],
      AIO_EVENTS_VERSION
    );

    wp_add_inline_style('aio-events-admin', self::build_css_variables($colors));

    wp_enqueue_script(
      'aio-events-admin',
      AIO_EVENTS_URL . 'assets/js/admin.min.js',
      ['jquery'],
      AIO_EVENTS_VERSION,
      true
    );

    wp_localize_script('aio-events-admin', 'aioEventsAdmin', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('aio-events-admin'),
      'i18n' => [
        'confirmDelete' => __('Are you sure you want to delete this event?', 'aio-event-solution'),
        'confirmPastDate' => __('This event is in the past. Are you sure?', 'aio-event-solution'),
      ],
    ]);
  }

  /**
   * Enqueue frontend assets
   */
  public static function enqueue_frontend()
  {
    $colors = self::get_theme_colors();

    wp_enqueue_style(
      'aio-events-frontend',
      AIO_EVENTS_URL . 'assets/css/generated/frontend.min.css',
      [],
      AIO_EVENTS_VERSION
    );

    wp_add_inline_style('aio-events-frontend', self::build_css_variables($colors, true));

    wp_enqueue_script(
      'aio-events-frontend',
      AIO_EVENTS_URL . 'assets/js/frontend.min.js',
      ['jquery'],
      AIO_EVENTS_VERSION,
      true
    );

    wp_localize_script('aio-events-frontend', 'aioEvents', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'restUrl' => rest_url('aio-events/v1/'),
      'nonce' => wp_create_nonce('aio-events-frontend'),
      'i18n' => self::get_frontend_i18n(),
    ]);
  }

  /**
   * Check if admin assets should be loaded
   */
  private static function should_load_admin_assets($hook)
  {
    $event_pages = ['post.php', 'post-new.php', 'edit.php'];

    if (in_array($hook, $event_pages)) {
      $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : '';
      if (empty($post_type) && isset($_GET['post'])) {
        $post_type = get_post_type($_GET['post']);
      }
      if ($post_type === 'aio_event') {
        return true;
      }
    }

    return strpos($hook, 'aio-events-settings') !== false;
  }

  /**
   * Get theme colors from settings
   */
  private static function get_theme_colors()
  {
    $settings = get_option('aio_events_settings', []);
    
    return [
      'primary' => $settings['primary_color'] ?? '#2271b1',
      'secondary' => $settings['secondary_color'] ?? '#f0f0f1',
      'content_bg' => $settings['content_box_background'] ?? '#f3f3f3',
    ];
  }

  /**
   * Build CSS variables string
   */
  private static function build_css_variables($colors, $include_background = false)
  {
    $primary = $colors['primary'];
    $secondary = $colors['secondary'];

    $css = ":root {
      --aio-events-color-primary: {$primary};
      --aio-events-color-primary-hover: " . self::adjust_brightness($primary, -20) . ";
      --aio-events-color-primary-light: " . self::adjust_brightness($primary, 85) . ";
      --aio-events-color-secondary: {$secondary};
      --aio-events-color-secondary-hover: " . self::adjust_brightness($secondary, -10) . ";
      --aio-events-color-secondary-dark: #50575e;";

    if ($include_background) {
      $css .= "\n      --aio-events-background: {$colors['content_bg']};";
    }

    $css .= "\n    }";

    return $css;
  }

  /**
   * Get frontend i18n strings
   */
  private static function get_frontend_i18n()
  {
    return [
      'registering' => __('Registering...', 'aio-event-solution'),
      'registerNow' => __('Register Now', 'aio-event-solution'),
      'error' => __('Error', 'aio-event-solution'),
      'success' => __('Success', 'aio-event-solution'),
      'loading' => __('Loading...', 'aio-event-solution'),
      'registered' => __('Registered!', 'aio-event-solution'),
      'errorOccurred' => __('An error occurred. Please try again.', 'aio-event-solution'),
      'invalidResponse' => __('Invalid server response', 'aio-event-solution'),
      'errorGeneric' => __('An error occurred', 'aio-event-solution'),
    ];
  }

  /**
   * Adjust color brightness
   */
  private static function adjust_brightness($hex, $percent)
  {
    $hex = str_replace('#', '', $hex);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = max(0, min(255, $r + ($percent * 255 / 100)));
    $g = max(0, min(255, $g + ($percent * 255 / 100)));
    $b = max(0, min(255, $b + ($percent * 255 / 100)));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
  }
}


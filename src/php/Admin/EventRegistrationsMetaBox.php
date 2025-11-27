<?php

namespace AIOEvents\Admin;

/**
 * Event Registrations Meta Box
 * Displays list of registrations for an event
 */
class EventRegistrationsMetaBox
{
  /**
   * Register meta box
   */
  public static function register()
  {
    add_action('add_meta_boxes', [self::class, 'add_meta_box']);
    add_action('add_meta_boxes_aio_event', [self::class, 'add_meta_box']);
  }

  /**
   * Add meta box to event edit screen
   */
  public static function add_meta_box($post_type, $post = null)
  {
    // Ensure we're on the correct post type
    if ($post_type !== 'aio_event') {
      return;
    }

    add_meta_box(
      'aio_event_registrations',
      __('Event Registrations', 'aio-event-solution'),
      [self::class, 'render'],
      'aio_event',
      'normal',
      'high'
    );
  }

  /**
   * Render meta box content
   */
  public static function render($post)
  {
    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    require_once AIO_EVENTS_PATH . 'php/Email/Scheduler.php';

    // Check if table exists
    $table_exists = \AIOEvents\Database\RegistrationRepository::table_exists();

    // Get registrations
    $registrations = [];
    if ($table_exists) {
      $results = \AIOEvents\Database\RegistrationRepository::get_by_event_id($post->ID);
      $registrations = $results;
    }

    // Get email stats for this event
    $email_stats = \AIOEvents\Email\Scheduler::get_event_email_stats($post->ID);

    // Check if event is cancelled (permanent)
    $event_cancelled = get_post_meta($post->ID, '_aio_event_cancelled', true) === '1';

    // Get Brevo templates for cancellation email
    $settings = get_option('aio_events_settings', []);
    $api_key = $settings['brevo_api_key'] ?? '';
    $templates = [];

    if (!empty($api_key)) {
      require_once AIO_EVENTS_PATH . 'php/Email/BrevoClient.php';
      $brevo = new \AIOEvents\Email\BrevoClient($api_key);
      $templates_result = $brevo->get_email_templates();
      if (!is_wp_error($templates_result) && is_array($templates_result)) {
        $templates = array_map(function ($template) {
          return is_array($template) ? $template : (array) $template;
        }, $templates_result);
      }
    }

    // Prepare context for Twig
    $context = [
      'table_exists' => $table_exists,
      'registrations' => $registrations,
      'email_stats' => $email_stats,
      'event_cancelled' => $event_cancelled,
      'templates' => $templates,
      'post_id' => $post->ID,
      'cancel_nonce' => wp_create_nonce('aio_cancel_event'),
    ];

    // Render using Timber/Twig
    \Timber\Timber::render('admin/meta-boxes/event-registrations.twig', $context);
  }
}


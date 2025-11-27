<?php

namespace AIOEvents\Admin;

/**
 * Event Details Meta Box
 * Handles the Event Details panel in the admin edit screen
 */
class EventDetailsMetaBox
{
  /**
   * Register meta box
   */
  public static function register()
  {
    add_action('add_meta_boxes', [self::class, 'add_meta_box']);
    add_action('add_meta_boxes_aio_event', [self::class, 'add_meta_box']);
    add_action('save_post_aio_event', [self::class, 'save'], 10, 1);
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
      'aio_event_details',
      __('Event Details', 'aio-event-solution'),
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
    // Check for validation errors
    $errors = get_transient('aio_event_required_settings_error_' . $post->ID);
    if ($errors && is_array($errors)) {
      add_action('admin_notices', function() use ($errors) {
        echo '<div class="notice notice-error"><p><strong>';
        echo esc_html__('Cannot publish event!', 'aio-event-solution');
        echo '</strong></p><ul style="margin-left: 20px;">';
        foreach ($errors as $error) {
          echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul><p>';
        echo sprintf(
          '<a href="%s" class="button button-primary">%s</a>',
          admin_url('edit.php?post_type=aio_event&page=aio-events-settings'),
          esc_html__('Go to Settings', 'aio-event-solution')
        );
        echo '</p></div>';
      });
      delete_transient('aio_event_required_settings_error_' . $post->ID);
    }

    // Get post-event message from event meta
    $post_event_message = get_post_meta($post->ID, '_aio_event_post_event_message', true);
    
    // If this is a new event (auto-draft) and message is empty, copy from global settings
    if (empty($post_event_message) && $post->post_status === 'auto-draft') {
      $settings = get_option('aio_events_settings', []);
      $global_message = $settings['global_post_event_message'] ?? '';
      
      // Copy global message to event meta (as actual copy, not reference)
      if (!empty($global_message)) {
        update_post_meta($post->ID, '_aio_event_post_event_message', $global_message);
        $post_event_message = $global_message;
      }
    }

    // Prepare data for Twig
    $context = [
      'subtitle' => get_post_meta($post->ID, '_aio_event_subtitle', true),
      'start_date' => get_post_meta($post->ID, '_aio_event_start_date', true),
      'start_time' => get_post_meta($post->ID, '_aio_event_start_time', true),
      'post_event_message' => $post_event_message,
      'stream_url' => get_post_meta($post->ID, '_aio_event_stream_url', true),
      'post_id' => $post->ID,
    ];

    // Render using Timber/Twig
    \Timber\Timber::render('admin/meta-boxes/event-details.twig', $context);
  }




  /**
   * Save meta box data
   */
  public static function save($post_id)
  {
    // Check nonce
    if (
      !isset($_POST['aio_event_details_nonce']) ||
      !wp_verify_nonce($_POST['aio_event_details_nonce'], 'aio_event_details_nonce')
    ) {
      return;
    }

    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    // Note: Validation of required settings is handled in EventPostType::validate_before_publish()
    // This allows saving as draft, but prevents publishing without required settings

    // Save subtitle
    if (isset($_POST['aio_event_subtitle'])) {
      update_post_meta($post_id, '_aio_event_subtitle', sanitize_text_field($_POST['aio_event_subtitle']));
    }

    // Save start date
    if (isset($_POST['aio_event_start_date'])) {
      update_post_meta($post_id, '_aio_event_start_date', sanitize_text_field($_POST['aio_event_start_date']));
    }

    // Save start time
    if (isset($_POST['aio_event_start_time'])) {
      update_post_meta($post_id, '_aio_event_start_time', sanitize_text_field($_POST['aio_event_start_time']));
    }

    // Save location
    if (isset($_POST['aio_event_location'])) {
      update_post_meta($post_id, '_aio_event_location', sanitize_text_field($_POST['aio_event_location']));
    }

    // Save max attendees
    if (isset($_POST['aio_event_max_attendees'])) {
      update_post_meta($post_id, '_aio_event_max_attendees', absint($_POST['aio_event_max_attendees']));
    }

    // Save post-event message
    // If empty and this is first save, copy from global settings
    if (isset($_POST['aio_event_post_event_message'])) {
      $message = wp_kses_post($_POST['aio_event_post_event_message']);
      
      // If empty and event doesn't have a message yet, copy from global
      if (empty($message)) {
        $existing_message = get_post_meta($post_id, '_aio_event_post_event_message', true);
        if (empty($existing_message)) {
          $settings = get_option('aio_events_settings', []);
          $global_message = $settings['global_post_event_message'] ?? '';
          if (!empty($global_message)) {
            $message = $global_message;
          }
        }
      }
      
      update_post_meta($post_id, '_aio_event_post_event_message', $message);
    } else {
      // If field not set and this is first save, copy from global
      $existing_message = get_post_meta($post_id, '_aio_event_post_event_message', true);
      if (empty($existing_message)) {
        $settings = get_option('aio_events_settings', []);
        $global_message = $settings['global_post_event_message'] ?? '';
        if (!empty($global_message)) {
          update_post_meta($post_id, '_aio_event_post_event_message', $global_message);
        }
      }
    }

    // Save stream URL (YouTube/Twitch)
    if (isset($_POST['aio_event_stream_url'])) {
      $stream_url = esc_url_raw($_POST['aio_event_stream_url']);
      update_post_meta($post_id, '_aio_event_stream_url', $stream_url);
    }
  }
}


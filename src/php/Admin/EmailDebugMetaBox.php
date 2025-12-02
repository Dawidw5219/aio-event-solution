<?php

namespace AIOEvents\Admin;

use Timber\Timber;

/**
 * Email Debug Meta Box
 * Allows testing email sending for events using the same logic as production
 */
class EmailDebugMetaBox
{
  /**
   * Register meta box
   */
  public static function register()
  {
    add_action('add_meta_boxes_aio_event', [self::class, 'add_meta_box']);
  }

  /**
   * Add meta box to event edit screen
   */
  public static function add_meta_box()
  {
    add_meta_box(
      'aio_event_email_debug',
      __('🧪 Email Debug', 'aio-event-solution'),
      [self::class, 'render'],
      'aio_event',
      'side',
      'low'
    );
  }

  /**
   * Render meta box content
   */
  public static function render($post)
  {
    $settings = get_option('aio_events_settings', []);
    
    // Get template IDs from settings or event overrides
    $templates = [
      'registration' => [
        'label' => __('After Registration', 'aio-event-solution'),
        'id' => absint(get_post_meta($post->ID, '_aio_event_email_template_after_registration', true)) 
                ?: absint($settings['email_template_after_registration'] ?? 0),
      ],
      'reminder' => [
        'label' => __('Reminder (before event)', 'aio-event-solution'),
        'id' => absint(get_post_meta($post->ID, '_aio_event_email_template_before_event', true)) 
                ?: absint($settings['email_template_before_event'] ?? 0),
      ],
      'join' => [
        'label' => __('Join Link', 'aio-event-solution'),
        'id' => absint(get_post_meta($post->ID, '_aio_event_email_template_join_event', true)) 
                ?: absint($settings['email_template_join_event'] ?? 0),
      ],
      'followup' => [
        'label' => __('Follow-up (after event)', 'aio-event-solution'),
        'id' => absint(get_post_meta($post->ID, '_aio_event_email_template_after_event', true)) 
                ?: absint($settings['email_template_after_event'] ?? 0),
      ],
    ];
    
    $context = [
      'post_id' => $post->ID,
      'templates' => $templates,
      'nonce' => wp_create_nonce('aio_send_test_email'),
      'default_email' => get_option('admin_email'),
      'has_brevo' => !empty($settings['brevo_api_key']),
    ];
    
    Timber::render('admin/meta-boxes/email-debug.twig', $context);
  }

  /**
   * Send test email - called via AJAX
   * Uses the EXACT same logic as production email sending
   */
  public static function send_test_email()
  {
    check_ajax_referer('aio_send_test_email', 'nonce');
    
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }
    
    $event_id = absint($_POST['event_id'] ?? 0);
    $email_type = sanitize_text_field($_POST['email_type'] ?? '');
    $test_email = sanitize_email($_POST['test_email'] ?? '');
    $test_name = sanitize_text_field($_POST['test_name'] ?? 'Test User');
    
    if (empty($event_id) || empty($email_type) || empty($test_email) || !is_email($test_email)) {
      wp_send_json_error(['message' => __('Missing required fields', 'aio-event-solution')]);
    }
    
    $event = get_post($event_id);
    if (!$event || $event->post_type !== 'aio_event') {
      wp_send_json_error(['message' => __('Invalid event', 'aio-event-solution')]);
    }
    
    // Get template ID based on type
    $settings = get_option('aio_events_settings', []);
    $template_id = self::get_template_id($event_id, $email_type, $settings);
    
    if (empty($template_id)) {
      wp_send_json_error([
        'message' => sprintf(__('No template configured for "%s" email type', 'aio-event-solution'), $email_type),
      ]);
    }
    
    // Initialize Brevo client
    require_once AIO_EVENTS_PATH . 'php/Email/BrevoClient.php';
    $brevo = new \AIOEvents\Email\BrevoClient();
    
    if (!$brevo->is_configured()) {
      wp_send_json_error(['message' => __('Brevo API not configured', 'aio-event-solution')]);
    }
    
    // Build params - SAME logic as Scheduler::schedule_email_type()
    $event_date = get_post_meta($event_id, '_aio_event_start_date', true);
    $event_time = get_post_meta($event_id, '_aio_event_start_time', true);
    $timezone_string = wp_timezone_string();
    $event_time_with_tz = $event_time ? $event_time . ' (' . $timezone_string . ')' : '';
    
    // Build join URL - SAME logic as Scheduler::schedule_email_type()
    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    require_once AIO_EVENTS_PATH . 'php/Event/JoinLink.php';
    
    $event_join_url = '';
    
    // First, try to find existing registration for this email and event
    $existing_registration = \AIOEvents\Database\RegistrationRepository::get_by_event_and_email($event_id, $test_email);
    
    if (!empty($existing_registration) && !empty($existing_registration['join_token'])) {
      // Use existing registration's token
      $rest_url = rest_url('aio-events/v1/join');
      $event_join_url = add_query_arg('token', urlencode($existing_registration['join_token']), $rest_url);
    } else {
      // No registration exists - try to get any registration for this event to use its token structure
      $any_registrations = \AIOEvents\Database\RegistrationRepository::get_by_event_id($event_id, 1);
      
      if (!empty($any_registrations) && !empty($any_registrations[0]['join_token'])) {
        // Use first registration's token for testing (user will see how URL looks)
        $rest_url = rest_url('aio-events/v1/join');
        $event_join_url = add_query_arg('token', urlencode($any_registrations[0]['join_token']), $rest_url);
      } else {
        // No registrations at all - generate a demo token (won't work but shows format)
        $demo_token = \AIOEvents\Event\JoinLink::generate_token($event_id, $test_email);
        $rest_url = rest_url('aio-events/v1/join');
        $event_join_url = add_query_arg('token', urlencode($demo_token), $rest_url);
      }
    }
    
    $params = [
      'event_title' => $event->post_title,
      'event_date' => $event_date ? date_i18n(get_option('date_format'), strtotime($event_date)) : '',
      'event_time' => $event_time_with_tz,
      'event_join_url' => $event_join_url,
      'attendee_name' => $test_name,
      'recipient_name' => $test_name, // alias
      'timezone' => $timezone_string,
    ];
    
    // Send email via Brevo - SAME method as production
    $result = $brevo->schedule_email(
      $template_id,
      [['email' => $test_email, 'name' => $test_name]],
      $params,
      null, // Send immediately
      ['tags' => ['test-email', 'event-' . $email_type, 'event-' . $event_id]]
    );
    
    if (is_wp_error($result)) {
      wp_send_json_error([
        'message' => sprintf(__('Failed to send: %s', 'aio-event-solution'), $result->get_error_message()),
      ]);
    }
    
    // Log test email
    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
    \AIOEvents\Logging\ActivityLogger::log(
      'email_sent',
      'test_email_' . $email_type,
      sprintf('Test %s email sent to %s', $email_type, $test_email),
      ['template_id' => $template_id, 'is_test' => true],
      $event_id,
      $test_email,
      'success'
    );
    
    wp_send_json_success([
      'message' => sprintf(
        __('✅ Test email (%s) sent to %s using template #%d', 'aio-event-solution'),
        $email_type,
        $test_email,
        $template_id
      ),
    ]);
  }
  
  /**
   * Get template ID for email type
   */
  private static function get_template_id($event_id, $email_type, $settings)
  {
    $type_map = [
      'registration' => ['meta' => '_aio_event_email_template_after_registration', 'setting' => 'email_template_after_registration'],
      'reminder' => ['meta' => '_aio_event_email_template_before_event', 'setting' => 'email_template_before_event'],
      'join' => ['meta' => '_aio_event_email_template_join_event', 'setting' => 'email_template_join_event'],
      'followup' => ['meta' => '_aio_event_email_template_after_event', 'setting' => 'email_template_after_event'],
    ];
    
    if (!isset($type_map[$email_type])) {
      return 0;
    }
    
    $map = $type_map[$email_type];
    
    // Check event-specific override first
    $template_id = absint(get_post_meta($event_id, $map['meta'], true));
    
    // Fall back to global setting
    if (empty($template_id)) {
      $template_id = absint($settings[$map['setting']] ?? 0);
    }
    
    return $template_id;
  }
}


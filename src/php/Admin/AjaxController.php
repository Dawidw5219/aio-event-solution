<?php

namespace AIOEvents\Admin;

use AIOEvents\Database\RegistrationRepository;
use AIOEvents\Email\BrevoClient;

/**
 * Handles all AJAX requests for admin and frontend
 */
class AjaxController
{
  /**
   * Register all AJAX handlers
   */
  public static function register()
  {
    // Admin handlers
    add_action('wp_ajax_aio_test_brevo_connection', [self::class, 'test_brevo_connection']);
    add_action('wp_ajax_aio_save_brevo_key', [self::class, 'save_brevo_key']);
    add_action('wp_ajax_aio_force_run_cron', [self::class, 'force_run_cron']);
    add_action('wp_ajax_aio_check_github_updates', [self::class, 'check_github_updates']);
    add_action('wp_ajax_aio_cancel_event', [self::class, 'cancel_event']);
    add_action('wp_ajax_aio_export_registrations_csv', [self::class, 'export_registrations_csv']);
    add_action('wp_ajax_aio_events_clear_scheduled_emails', [self::class, 'clear_scheduled_emails']);
    add_action('wp_ajax_aio_events_test_debug_email', [self::class, 'test_debug_email']);

    // Frontend handlers
    add_action('wp_ajax_aio_register_event', [self::class, 'register_event']);
    add_action('wp_ajax_nopriv_aio_register_event', [self::class, 'register_event']);
  }

  /**
   * Force run cron job - uses the same hook as scheduled cron for consistency
   */
  public static function force_run_cron()
  {
    check_ajax_referer('aio-events-admin', 'nonce');
    self::require_admin();

    // Use the same hook as scheduled cron - this ensures logging to CronLogger
    require_once AIO_EVENTS_PATH . 'php/Core/Cron.php';
    $result = \AIOEvents\Core\Cron::run_scheduler();

    if ($result === false) {
      wp_send_json_error([
        'message' => __('Brevo API not configured', 'aio-event-solution'),
      ]);
    }

    $scheduled = is_array($result) ? ($result['scheduled'] ?? 0) : 0;
    $errors = is_array($result) ? ($result['errors'] ?? 0) : 0;

    if ($scheduled === 0 && $errors === 0) {
      $message = __('Cron executed. No emails to schedule at this time.', 'aio-event-solution');
    } else {
      $message = sprintf(
        __('Cron executed. Scheduled/sent %d email(s).', 'aio-event-solution'),
        $scheduled
      );

      if ($errors > 0) {
        $message .= ' ' . sprintf(__('%d error(s).', 'aio-event-solution'), $errors);
      }
    }

    wp_send_json_success([
      'message' => $message,
      'scheduled' => $scheduled,
      'errors' => $errors,
    ]);
  }

  /**
   * Check for GitHub updates
   */
  public static function check_github_updates()
  {
    check_ajax_referer('aio-events-admin', 'nonce');
    self::require_admin();

    delete_site_transient('update_plugins');
    delete_transient('update_plugins');
    
    $plugin_data = get_file_data(
      AIO_EVENTS_PATH . 'aio-event-solution.php',
      ['Version' => 'Version', 'GitHubURI' => 'GitHub URI']
    );
    $current_version = $plugin_data['Version'] ?? '0.0.0';
    $github_repo = $plugin_data['GitHubURI'] ?? '';
    
    if (empty($github_repo)) {
      wp_send_json_error(['message' => __('GitHub URI not configured', 'aio-event-solution')]);
    }
    
    $repo_parts = explode('/', $github_repo);
    $url = sprintf(
      'https://api.github.com/repos/%s/%s/releases/latest',
      trim($repo_parts[0]),
      trim($repo_parts[1])
    );
    
    $response = wp_remote_get($url, [
      'headers' => [
        'Accept' => 'application/vnd.github.v3+json',
        'User-Agent' => 'WordPress/' . get_bloginfo('version'),
      ],
      'timeout' => 10,
    ]);
    
    if (is_wp_error($response)) {
      wp_send_json_error(['message' => $response->get_error_message()]);
    }
    
    if (wp_remote_retrieve_response_code($response) !== 200) {
      wp_send_json_error(['message' => __('Could not fetch GitHub releases', 'aio-event-solution')]);
    }
    
    $release = json_decode(wp_remote_retrieve_body($response));
    $latest_version = ltrim($release->tag_name ?? '', 'v');
    
    if (version_compare($latest_version, $current_version, '>')) {
      wp_send_json_success([
        'message' => sprintf(
          __('Update available! v%s → v%s. Go to <a href="%s">Plugins</a> to update.', 'aio-event-solution'),
          $current_version,
          $latest_version,
          admin_url('plugins.php')
        ),
      ]);
    }

    wp_send_json_success([
      'message' => sprintf(__('You have the latest version (v%s).', 'aio-event-solution'), $current_version),
    ]);
  }

  /**
   * Register event (frontend)
   */
  public static function register_event()
  {
    check_ajax_referer('aio-events-frontend', 'nonce');

    $event_id = absint($_POST['event_id'] ?? 0);
    $name = sanitize_text_field($_POST['name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');

    if (empty($event_id) || empty($name) || empty($email) || !is_email($email)) {
      wp_send_json_error(['message' => __('All fields are required', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Event/Registration.php';
    $result = \AIOEvents\Event\Registration::register($event_id, $email, $name, $phone, []);

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    if (isset($result['already_registered'])) {
      wp_send_json_error(['message' => __('You are already registered for this event', 'aio-event-solution')]);
    }

    wp_send_json_success(['message' => __('Registration completed successfully!', 'aio-event-solution')]);
  }

  /**
   * Test Brevo connection
   */
  public static function test_brevo_connection()
  {
    check_ajax_referer('aio_test_brevo', 'nonce');
    self::require_admin();

    $api_key = sanitize_text_field($_POST['api_key'] ?? '');
    if (empty($api_key)) {
      wp_send_json_error(['message' => __('API key is required', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Email/BrevoClient.php';
    $brevo = new BrevoClient($api_key);
    $result = $brevo->test_connection();

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success([
      'message' => sprintf(
        __('Connected successfully! Account: %s', 'aio-event-solution'),
        $result['email'] ?? 'Unknown'
      ),
    ]);
  }

  /**
   * Save Brevo API key
   */
  public static function save_brevo_key()
  {
    check_ajax_referer('aio_save_brevo_key', 'nonce');
    self::require_admin();

    $api_key = sanitize_text_field($_POST['api_key'] ?? '');
    if (empty($api_key)) {
      wp_send_json_error(['message' => __('API key is required', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Email/BrevoClient.php';
    $brevo = new BrevoClient($api_key);
    $result = $brevo->test_connection();

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    $settings = get_option('aio_events_settings', []);
    $settings['brevo_api_key'] = $api_key;
    update_option('aio_events_settings', $settings);

    wp_send_json_success([
      'message' => sprintf(
        __('Connected! Account: %s. Reloading...', 'aio-event-solution'),
        $result['email'] ?? 'Unknown'
      ),
    ]);
  }

  /**
   * Cancel event permanently - sends cancellation emails and marks event as cancelled
   */
  public static function cancel_event()
  {
    check_ajax_referer('aio_cancel_event', 'nonce');
    self::require_admin();

    $event_id = absint($_POST['event_id'] ?? 0);
    if (empty($event_id)) {
      wp_send_json_error(['message' => __('Event ID is required', 'aio-event-solution')]);
    }

    $event = get_post($event_id);
    if (!$event || $event->post_type !== 'aio_event') {
      wp_send_json_error(['message' => __('Invalid event', 'aio-event-solution')]);
    }

    // Check if already cancelled
    if (get_post_meta($event_id, '_aio_event_cancelled', true) === '1') {
      wp_send_json_error(['message' => __('This event is already cancelled', 'aio-event-solution')]);
    }

    // Mark event as cancelled (permanent)
    update_post_meta($event_id, '_aio_event_cancelled', '1');
    update_post_meta($event_id, '_aio_event_cancelled_at', current_time('mysql'));
    update_post_meta($event_id, '_aio_event_emails_cancelled', '1'); // Also disable automatic emails

    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
    \AIOEvents\Logging\ActivityLogger::log(
      'event',
      'event_cancelled',
      sprintf('Event cancelled: %s', $event->post_title),
      [],
      $event_id,
      null,
      'warning'
    );

    $message = __('Event has been cancelled.', 'aio-event-solution');
    $sent_count = 0;

    // Send cancellation email if template selected
    $template_id = absint($_POST['template_id'] ?? 0);
    if ($template_id) {
      require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
      require_once AIO_EVENTS_PATH . 'php/Email/BrevoClient.php';
      
      $registrations = RegistrationRepository::get_by_event_id($event_id);
      
      if (!empty($registrations)) {
        $brevo = new BrevoClient();
        if ($brevo->is_configured()) {
          $sent_count = self::send_cancellation_emails($brevo, $template_id, $registrations, $event);
          $message .= ' ' . sprintf(__('Sent %d cancellation email(s).', 'aio-event-solution'), $sent_count);
        }
      } else {
        $message .= ' ' . __('No registrations to notify.', 'aio-event-solution');
      }
    }

    wp_send_json_success([
      'message' => $message,
      'sent' => $sent_count,
    ]);
  }

  /**
   * Send cancellation emails to all registrations
   */
  private static function send_cancellation_emails($brevo, $template_id, $registrations, $event)
  {
    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
    
    $event_date = get_post_meta($event->ID, '_aio_event_start_date', true);
    $event_time = get_post_meta($event->ID, '_aio_event_start_time', true);
    $timezone_string = wp_timezone_string();
    
    $sent_count = 0;
    
    foreach ($registrations as $registration) {
      $params = [
        'event_title' => $event->post_title,
        'event_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
        'event_time' => $event_time ? $event_time . ' (' . $timezone_string . ')' : '',
        'attendee_name' => $registration['name'],
        'recipient_name' => $registration['name'], // alias
        'event_join_url' => get_permalink($event->ID),
      ];
      
      $result = $brevo->schedule_email(
        $template_id,
        [['email' => $registration['email'], 'name' => $registration['name']]],
        $params,
        null,
        ['tags' => ['event-cancellation', 'event-' . $event->ID]]
      );
      
      if (!is_wp_error($result)) {
        $sent_count++;
        \AIOEvents\Logging\ActivityLogger::log(
          'email_sent',
          'cancellation_notification',
          sprintf('Cancellation notification sent to %s', $registration['email']),
          ['template_id' => $template_id],
          $event->ID,
          $registration['email'],
          'success'
        );
      }
    }
    
    return $sent_count;
  }

  /**
   * Export registrations to CSV
   */
  public static function export_registrations_csv()
  {
    check_ajax_referer('aio_export_csv', 'nonce');
    self::require_admin();

    $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
    if (empty($event_id)) {
      wp_die(__('Invalid event ID', 'aio-event-solution'));
    }

    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';

    if (!RegistrationRepository::table_exists()) {
      wp_die(__('Registrations table does not exist', 'aio-event-solution'));
    }

    $registrations = RegistrationRepository::get_by_event_id($event_id);
    $event = get_post($event_id);

    if (!$event) {
      wp_die(__('Event not found', 'aio-event-solution'));
    }

    self::output_csv($registrations, $event_id);
  }

  /**
   * Output CSV file
   */
  private static function output_csv($registrations, $event_id)
  {
    $filename = 'rejestracje-event-' . $event_id . '-' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // BOM for UTF-8

    $output = fopen('php://output', 'w');

    // Use comma as delimiter (standard CSV)
    fputcsv($output, [
      __('Name', 'aio-event-solution'),
      __('Email', 'aio-event-solution'),
      __('Registration Date', 'aio-event-solution'),
      __('Attendance', 'aio-event-solution'),
    ]);

    foreach ($registrations as $registration) {
      fputcsv($output, [
        $registration['name'] ?? '',
        $registration['email'] ?? '',
        $registration['registered_at'] 
          ? date_i18n('Y-m-d H:i', strtotime($registration['registered_at'])) 
          : '',
        !empty($registration['clicked_join_link']) 
          ? __('Yes', 'aio-event-solution') 
          : __('No', 'aio-event-solution'),
      ]);
    }

    fclose($output);
    exit;
  }

  /**
   * Clear all scheduled emails (legacy table)
   */
  public static function clear_scheduled_emails()
  {
    check_ajax_referer('aio-events-admin', 'nonce');
    self::require_admin();

    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_scheduled_emails';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("TRUNCATE TABLE {$table_name}");

    wp_send_json_success([
      'message' => sprintf(__('Cleared %d scheduled emails.', 'aio-event-solution'), $count),
    ]);
  }

  /**
   * Send test debug email
   */
  public static function test_debug_email()
  {
    check_ajax_referer('aio-events-admin', 'nonce');
    self::require_admin();

    $settings = get_option('aio_events_settings', []);
    $debug_email = $settings['debug_email'] ?? '';

    if (empty($debug_email) || !is_email($debug_email)) {
      wp_send_json_error([
        'message' => __('Debug email address is not configured. Please set it first.', 'aio-event-solution'),
      ]);
    }

    $subject = sprintf(
      '[%s] AIO Events - Test Email',
      get_bloginfo('name')
    );

    $body = sprintf(
      '<h2>%s</h2>
      <p>%s</p>
      <p><strong>%s:</strong> %s</p>
      <p><strong>%s:</strong> %s</p>
      <p><strong>%s:</strong> %s</p>
      <hr>
      <p style="color: #666; font-size: 12px;">%s</p>',
      __('Test Email from AIO Events', 'aio-event-solution'),
      __('This is a test email to verify that your debug email configuration is working correctly.', 'aio-event-solution'),
      __('Site', 'aio-event-solution'),
      home_url(),
      __('Admin Email', 'aio-event-solution'),
      get_option('admin_email'),
      __('WordPress Version', 'aio-event-solution'),
      get_bloginfo('version'),
      __('This email was sent from AIO Events plugin settings.', 'aio-event-solution')
    );

    $headers = [
      'Content-Type: text/html; charset=UTF-8',
      'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
    ];

    $sent = wp_mail($debug_email, $subject, $body, $headers);

    if ($sent) {
      wp_send_json_success([
        'message' => sprintf(__('Test email sent to %s', 'aio-event-solution'), $debug_email),
      ]);
    } else {
      wp_send_json_error([
        'message' => __('Failed to send test email. Check your WordPress mail configuration.', 'aio-event-solution'),
      ]);
    }
  }

  /**
   * Require admin permissions
   */
  private static function require_admin()
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }
  }
}

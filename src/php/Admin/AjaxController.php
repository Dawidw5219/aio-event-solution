<?php

namespace AIOEvents\Admin;

use AIOEvents\Repositories\RegistrationRepository;
use AIOEvents\Scheduler\EmailScheduler;
use AIOEvents\Integrations\BrevoAPI;

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
    add_action('wp_ajax_aio_cancel_scheduled_emails', [self::class, 'cancel_scheduled_emails']);
    add_action('wp_ajax_aio_force_run_cron', [self::class, 'force_run_cron']);
    add_action('wp_ajax_aio_check_github_updates', [self::class, 'check_github_updates']);
    add_action('wp_ajax_aio_cancel_event_emails', [self::class, 'cancel_event_emails']);
    add_action('wp_ajax_aio_export_registrations_csv', [self::class, 'export_registrations_csv']);

    // Frontend handlers
    add_action('wp_ajax_aio_register_event', [self::class, 'register_event']);
    add_action('wp_ajax_nopriv_aio_register_event', [self::class, 'register_event']);
  }

  /**
   * Force run cron job
   */
  public static function force_run_cron()
  {
    check_ajax_referer('aio-events-admin', 'nonce');
    self::require_admin();

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    $scheduler = new EmailScheduler();
    $processed = $scheduler->process_scheduled_emails();

    wp_send_json_success([
      'message' => sprintf(
        __('Cron executed. Processed %d email(s).', 'aio-event-solution'),
        $processed
      ),
      'processed' => $processed,
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

    require_once AIO_EVENTS_PATH . 'php/Services/RegistrationService.php';
    $result = \AIOEvents\Services\RegistrationService::register($event_id, $email, $name, $phone, []);

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

    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new BrevoAPI($api_key);
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

    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new BrevoAPI($api_key);
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
   * Cancel scheduled emails
   */
  public static function cancel_scheduled_emails()
  {
    check_ajax_referer('aio_cancel_emails', 'nonce');
    self::require_admin();

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';

    $settings = get_option('aio_events_settings', []);
    $api_key = $settings['brevo_api_key'] ?? '';

    if (empty($api_key)) {
      wp_send_json_error(['message' => __('Brevo API key not configured', 'aio-event-solution')]);
    }

    $replacement_template_id = absint($_POST['replacement_template_id'] ?? 0);
    $scheduled_emails = EmailScheduler::get_scheduled_emails(['limit' => 1000]);

    if (empty($scheduled_emails)) {
      wp_send_json_success(['message' => __('No scheduled emails to cancel', 'aio-event-solution')]);
    }

    $result = self::process_email_cancellation(
      $scheduled_emails,
      $api_key,
      $replacement_template_id
    );

    wp_send_json_success(['message' => $result['message']]);
  }

  /**
   * Cancel scheduled emails for specific event
   */
  public static function cancel_event_emails()
  {
    check_ajax_referer('aio_cancel_event_emails', 'nonce');
    self::require_admin();

    $event_id = absint($_POST['event_id'] ?? 0);
    if (empty($event_id)) {
      wp_send_json_error(['message' => __('Event ID is required', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';

    $settings = get_option('aio_events_settings', []);
    $api_key = $settings['brevo_api_key'] ?? '';

    if (empty($api_key)) {
      wp_send_json_error(['message' => __('Brevo API key not configured', 'aio-event-solution')]);
    }

    $replacement_template_id = absint($_POST['replacement_template_id'] ?? 0);
    $event_emails = EmailScheduler::get_scheduled_emails([
      'event_id' => $event_id,
      'limit' => 1000,
    ]);

    if (empty($event_emails)) {
      wp_send_json_success(['message' => __('No scheduled emails for this event', 'aio-event-solution')]);
    }

    $result = self::process_email_cancellation(
      $event_emails,
      $api_key,
      $replacement_template_id,
      $event_id
    );

    // Toggle cancellation state
    $emails_cancelled = get_post_meta($event_id, '_aio_event_emails_cancelled', true) === '1';

    if ($emails_cancelled) {
      delete_post_meta($event_id, '_aio_event_emails_cancelled');
      $message = __('Email scheduling restored for this event.', 'aio-event-solution');
    } else {
      update_post_meta($event_id, '_aio_event_emails_cancelled', '1');
      $message = $result['message'];
    }

    wp_send_json_success(['message' => $message]);
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

    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';

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

    fputcsv($output, [
      __('Name', 'aio-event-solution'),
      __('Email', 'aio-event-solution'),
      __('Registration Date', 'aio-event-solution'),
      __('Attendance', 'aio-event-solution'),
    ], ';');

    foreach ($registrations as $registration) {
      fputcsv($output, [
        $registration['name'] ?? '',
        $registration['email'] ?? '',
        $registration['registered_at'] 
          ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($registration['registered_at'])) 
          : '',
        !empty($registration['clicked_join_link']) 
          ? __('Yes', 'aio-event-solution') 
          : __('No', 'aio-event-solution'),
      ], ';');
    }

    fclose($output);
    exit;
  }

  /**
   * Process email cancellation
   */
  private static function process_email_cancellation($emails, $api_key, $replacement_template_id, $event_id = null)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_scheduled_emails';
    
    $brevo = new BrevoAPI();
    $cancelled_count = 0;
    $replacement_sent_count = 0;
    $all_recipients = [];
    $events_processed = [];

    foreach ($emails as $email) {
      // Cancel in Brevo
      if ($email['status'] === 'scheduled' && !empty($email['brevo_message_id'])) {
        wp_remote_request(
          'https://api.brevo.com/v3/smtp/email/' . urlencode($email['brevo_message_id']),
          [
            'method' => 'DELETE',
            'headers' => [
              'api-key' => $api_key,
              'Content-Type' => 'application/json',
            ],
            'timeout' => 15,
          ]
        );
      }

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->update(
        $table_name,
        ['status' => 'cancelled'],
        ['id' => $email['id']],
        ['%s'],
        ['%d']
      );
      $cancelled_count++;

      // Collect recipients for replacement email
      if (!empty($replacement_template_id)) {
        $should_collect = ($event_id !== null) || !in_array($email['event_id'], $events_processed);
        
        if ($should_collect) {
          $events_processed[] = $email['event_id'];
          $brevo_list_ids = json_decode($email['recipient_list_ids'], true);
          
          if (!empty($brevo_list_ids) && is_array($brevo_list_ids)) {
            $recipients = EmailScheduler::get_recipients_from_lists($brevo_list_ids);
            foreach ($recipients as $recipient) {
              if (!isset($all_recipients[$recipient['email']])) {
                $all_recipients[$recipient['email']] = $recipient;
              }
            }
          }
        }
      }
    }

    // Send replacement emails
    if (!empty($replacement_template_id) && !empty($all_recipients)) {
      $replacement_sent_count = self::send_replacement_emails(
        $brevo,
        $replacement_template_id,
        array_values($all_recipients),
        $event_id
      );
    }

    $message = sprintf(__('Cancelled %d scheduled emails.', 'aio-event-solution'), $cancelled_count);
    if ($replacement_sent_count > 0) {
      $message .= ' ' . sprintf(__('Sent %d replacement emails.', 'aio-event-solution'), $replacement_sent_count);
    }

    return ['cancelled' => $cancelled_count, 'replacements' => $replacement_sent_count, 'message' => $message];
  }

  /**
   * Send replacement emails
   */
  private static function send_replacement_emails($brevo, $template_id, $recipients, $event_id = null)
  {
    $event = $event_id ? get_post($event_id) : null;
    
    $event_params = [
      'event_title' => $event ? $event->post_title : __('Cancellation message', 'aio-event-solution'),
      'event_date' => date_i18n(get_option('date_format')),
      'event_join_url' => $event ? get_permalink($event->ID) : home_url(),
      'event_location' => '',
    ];

    $tags = $event_id 
      ? ['event-replacement', 'event-' . $event_id]
      : ['event-replacement', 'cancelled-emails'];

    $result = $brevo->schedule_email($template_id, $recipients, $event_params, null, ['tags' => $tags]);

    return !is_wp_error($result) ? count($recipients) : 0;
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


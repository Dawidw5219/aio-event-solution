<?php

namespace AIOEvents;

use AIOEvents\Admin\SettingsPage;
use AIOEvents\PostTypes\EventPostType;

/**
 * Main Plugin Class - Singleton
 */
class Plugin
{
  private static $instance = null;

  /**
   * Get singleton instance
   */
  public static function instance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Constructor
   */
  private function __construct()
  {
    $this->init_hooks();
  }

  /**
   * Initialize hooks
   */
  private function init_hooks()
  {
    add_action('init', [$this, 'register_post_types']);
    add_action('init', [$this, 'register_shortcodes']);
    add_action('init', [$this, 'init_registration']);
    add_action('admin_menu', [$this, 'register_admin_pages']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    add_filter('template_include', [$this, 'load_event_templates']);

    // Register helper function for UTC conversion
    add_action('init', [$this, 'register_helper_functions']);

    // Initialize GitHub updater for private repo updates
    $this->init_github_updater();

    // Register REST API routes
    require_once AIO_EVENTS_PATH . 'php/API/BrevoWebhookController.php';
    \AIOEvents\API\BrevoWebhookController::register();
    require_once AIO_EVENTS_PATH . 'php/API/JoinEventController.php';
    \AIOEvents\API\JoinEventController::register();

    add_action('aio_events_schedule_daily_emails', [$this, 'run_email_scheduler']);
    $this->schedule_email_cron();
  }

  /**
   * Schedule email cron job (runs daily at 23:30)
   */
  private function schedule_email_cron()
  {
    if (!wp_next_scheduled('aio_events_schedule_daily_emails')) {
      $timestamp = strtotime("today 23:30");
      if ($timestamp < time()) {
        $timestamp = strtotime("tomorrow 23:30");
      }
      wp_schedule_event($timestamp, 'daily', 'aio_events_schedule_daily_emails');
    }
  }

  /**
   * Run email scheduler (called by cron)
   */
  public function run_email_scheduler()
  {
    $start_time = microtime(true);
    $hook_name = 'aio_events_schedule_daily_emails';

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    require_once AIO_EVENTS_PATH . 'php/Scheduler/CronLogger.php';

    // Ensure tables exist
    \AIOEvents\Scheduler\EmailScheduler::create_table();
    \AIOEvents\Scheduler\CronLogger::create_table();

    try {
      $result = \AIOEvents\Scheduler\EmailScheduler::run_daily_schedule();
      $execution_duration = microtime(true) - $start_time;

      // Extract scheduled count from result if available
      $scheduled_count = 0;
      $error_count = 0;
      if (is_array($result)) {
        $scheduled_count = $result['scheduled'] ?? 0;
        $error_count = $result['errors'] ?? 0;
      }

      if ($result === false || is_wp_error($result)) {
        \AIOEvents\Scheduler\CronLogger::log(
          $hook_name,
          'error',
          is_wp_error($result) ? $result->get_error_message() : 'Email scheduler returned false',
          $execution_duration,
          $scheduled_count,
          $error_count
        );
      } else {
        \AIOEvents\Scheduler\CronLogger::log(
          $hook_name,
          'success',
          sprintf(__('Scheduled %d emails', 'aio-event-solution'), $scheduled_count),
          $execution_duration,
          $scheduled_count,
          $error_count
        );
      }

      return $result;
    } catch (\Exception $e) {
      $execution_duration = microtime(true) - $start_time;
      \AIOEvents\Scheduler\CronLogger::log(
        $hook_name,
        'error',
        sprintf(__('Exception: %s', 'aio-event-solution'), $e->getMessage()),
        $execution_duration,
        0,
        1
      );
      error_log('[AIO Events Cron] Exception: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * Register helper functions for Twig templates
   */
  public function register_helper_functions()
  {
    // Function is defined at the bottom of this file in global scope
  }

  /**
   * Initialize GitHub updater
   * Repo is defined in plugin header: GitHub URI: owner/repo
   */
  private function init_github_updater()
  {
    require_once AIO_EVENTS_PATH . 'php/Updater/GitHubUpdater.php';
    new \AIOEvents\Updater\GitHubUpdater(AIO_EVENTS_PATH . 'aio-event-solution.php');
  }

  /**
   * AJAX: Force run cron job to process scheduled emails
   */
  public function ajax_force_run_cron()
  {
    check_ajax_referer('aio-events-admin', 'nonce');
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    $scheduler = new \AIOEvents\Scheduler\EmailScheduler();
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
   * Initialize plugin features
   */
  public function init_registration()
  {
    // Initialize category meta fields
    require_once AIO_EVENTS_PATH . 'php/Taxonomies/EventCategoryMeta.php';
    \AIOEvents\Taxonomies\EventCategoryMeta::init();

    // Run database migrations
    $this->migrate_registrations_table();

    // Add AJAX handlers for admin
    add_action('wp_ajax_aio_test_brevo_connection', [$this, 'ajax_test_brevo_connection']);
    add_action('wp_ajax_aio_create_registrations_table', [$this, 'ajax_create_registrations_table']);
    add_action('wp_ajax_aio_cancel_scheduled_emails', [$this, 'ajax_cancel_scheduled_emails']);
    add_action('wp_ajax_aio_force_run_cron', [$this, 'ajax_force_run_cron']);
    add_action('wp_ajax_aio_cancel_event_emails', [$this, 'ajax_cancel_event_emails']);
    add_action('wp_ajax_aio_export_registrations_csv', [$this, 'ajax_export_registrations_csv']);

    // Add AJAX handlers for frontend
    add_action('wp_ajax_aio_register_event', [$this, 'ajax_register_event']);
    add_action('wp_ajax_nopriv_aio_register_event', [$this, 'ajax_register_event']);
  }

  /**
   * Migrate registrations table - add join_token column if missing
   */
  private function migrate_registrations_table()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_registrations';

    // Check if table exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

    if (!$table_exists) {
      return; // Table doesn't exist yet, will be created with join_token column
    }

    // Check if join_token column exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $column_exists = $wpdb->get_results($wpdb->prepare(
      "SHOW COLUMNS FROM $table_name LIKE %s",
      'join_token'
    ));

    if (empty($column_exists)) {
      // Add join_token column
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $wpdb->query("ALTER TABLE $table_name ADD COLUMN join_token varchar(255) DEFAULT NULL");

      // Add index if it doesn't exist
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $wpdb->query("ALTER TABLE $table_name ADD INDEX join_token (join_token)");

      error_log('[AIO Events] Added join_token column to registrations table');
    }

    // Check if clicked_join_link column exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $clicked_column_exists = $wpdb->get_results($wpdb->prepare(
      "SHOW COLUMNS FROM $table_name LIKE %s",
      'clicked_join_link'
    ));

    if (empty($clicked_column_exists)) {
      // Add clicked_join_link column
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $wpdb->query("ALTER TABLE $table_name ADD COLUMN clicked_join_link tinyint(1) DEFAULT 0 NOT NULL");

      error_log('[AIO Events] Added clicked_join_link column to registrations table');
    }
  }

  /**
   * AJAX handler for event registration
   */
  public function ajax_register_event()
  {
    check_ajax_referer('aio-events-frontend', 'nonce');

    $event_id = absint($_POST['event_id'] ?? 0);
    $name = sanitize_text_field($_POST['name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');

    if (empty($event_id) || empty($name) || empty($email) || !is_email($email)) {
      wp_send_json_error(['message' => __('All fields are required', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';
    
    if (!\AIOEvents\Repositories\RegistrationRepository::table_exists()) {
      wp_send_json_error(['message' => __('Registrations table does not exist', 'aio-event-solution')]);
    }

    if (\AIOEvents\Repositories\RegistrationRepository::is_registered($event_id, $email)) {
      wp_send_json_error(['message' => __('You are already registered for this event', 'aio-event-solution')]);
    }

    // Use reusable method to save registration
    $result = $this->save_registration_data($event_id, $email, $name, $phone, false);

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    if (isset($result['already_registered'])) {
      wp_send_json_error(['message' => __('You are already registered for this event', 'aio-event-solution')]);
    }

    wp_send_json_success(['message' => __('Registration completed successfully!', 'aio-event-solution')]);
  }

  /**
   * AJAX handler to test Brevo connection
   */
  public function ajax_test_brevo_connection()
  {
    check_ajax_referer('aio_test_brevo', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }

    $api_key = sanitize_text_field($_POST['api_key'] ?? '');

    if (empty($api_key)) {
      wp_send_json_error(['message' => __('API key is required', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new \AIOEvents\Integrations\BrevoAPI($api_key);
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
   * AJAX handler to cancel scheduled emails and optionally send replacement
   */
  public function ajax_cancel_scheduled_emails()
  {
    check_ajax_referer('aio_cancel_emails', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';

    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_scheduled_emails';

    $replacement_template_id = absint($_POST['replacement_template_id'] ?? 0);
    $scheduled_emails = \AIOEvents\Scheduler\EmailScheduler::get_scheduled_emails(['limit' => 1000]);

    if (empty($scheduled_emails)) {
      wp_send_json_success(['message' => __('No scheduled emails to cancel', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new \AIOEvents\Integrations\BrevoAPI();
    $settings = get_option('aio_events_settings', []);
    $api_key = $settings['brevo_api_key'] ?? '';

    if (empty($api_key)) {
      wp_send_json_error(['message' => __('Brevo API key not configured', 'aio-event-solution')]);
    }

    $cancelled_count = 0;
    $replacement_sent_count = 0;
    $events_processed = [];
    $all_recipients = [];

    foreach ($scheduled_emails as $email) {
      if ($email['status'] === 'scheduled' && !empty($email['brevo_message_id'])) {
        $cancel_response = wp_remote_request(
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

      $wpdb->update(
        $table_name,
        ['status' => 'cancelled'],
        ['id' => $email['id']],
        ['%s'],
        ['%d']
      );
      $cancelled_count++;

      if (!empty($replacement_template_id) && !in_array($email['event_id'], $events_processed)) {
        $events_processed[] = $email['event_id'];
        $event = get_post($email['event_id']);
        $brevo_list_ids = json_decode($email['recipient_list_ids'], true);

        if ($event && !empty($brevo_list_ids) && is_array($brevo_list_ids)) {
          $recipients = \AIOEvents\Scheduler\EmailScheduler::get_recipients_from_lists($brevo_list_ids);

          foreach ($recipients as $recipient) {
            $email_key = $recipient['email'];
            if (!isset($all_recipients[$email_key])) {
              $all_recipients[$email_key] = $recipient;
            }
          }
        }
      }
    }

    if (!empty($replacement_template_id) && !empty($all_recipients)) {
      $recipients_array = array_values($all_recipients);
      $event_params = [
        'event_title' => __('Cancellation message', 'aio-event-solution'),
        'event_date' => date_i18n(get_option('date_format')),
        'event_join_url' => home_url(),
        'event_location' => '',
      ];

      $result = $brevo->schedule_email(
        $replacement_template_id,
        $recipients_array,
        $event_params,
        null,
        ['tags' => ['event-replacement', 'cancelled-emails']]
      );

      if (!is_wp_error($result)) {
        $replacement_sent_count = count($recipients_array);
      }
    }

    $message = sprintf(
      __('Cancelled %d scheduled emails.', 'aio-event-solution'),
      $cancelled_count
    );

    if (!empty($replacement_template_id) && $replacement_sent_count > 0) {
      $message .= ' ' . sprintf(
        __('Sent %d replacement emails.', 'aio-event-solution'),
        $replacement_sent_count
      );
    }

    wp_send_json_success(['message' => $message]);
  }

  /**
   * AJAX handler to cancel scheduled emails for a specific event
   */
  public function ajax_cancel_event_emails()
  {
    check_ajax_referer('aio_cancel_event_emails', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }

    $event_id = absint($_POST['event_id'] ?? 0);
    if (empty($event_id)) {
      wp_send_json_error(['message' => __('Event ID is required', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';

    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_scheduled_emails';

    $replacement_template_id = absint($_POST['replacement_template_id'] ?? 0);
    $event_emails = \AIOEvents\Scheduler\EmailScheduler::get_scheduled_emails([
      'event_id' => $event_id,
      'limit' => 1000,
    ]);

    if (empty($event_emails)) {
      wp_send_json_success(['message' => __('No scheduled emails for this event', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new \AIOEvents\Integrations\BrevoAPI();
    $settings = get_option('aio_events_settings', []);
    $api_key = $settings['brevo_api_key'] ?? '';

    if (empty($api_key)) {
      wp_send_json_error(['message' => __('Brevo API key not configured', 'aio-event-solution')]);
    }

    $cancelled_count = 0;
    $replacement_sent_count = 0;
    $all_recipients = [];

    foreach ($event_emails as $email) {
      if ($email['status'] === 'scheduled' && !empty($email['brevo_message_id'])) {
        $cancel_response = wp_remote_request(
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

      $wpdb->update(
        $table_name,
        ['status' => 'cancelled'],
        ['id' => $email['id']],
        ['%s'],
        ['%d']
      );
      $cancelled_count++;

      if (!empty($replacement_template_id)) {
        $brevo_list_ids = json_decode($email['recipient_list_ids'], true);
        if (!empty($brevo_list_ids) && is_array($brevo_list_ids)) {
          $recipients = \AIOEvents\Scheduler\EmailScheduler::get_recipients_from_lists($brevo_list_ids);

          foreach ($recipients as $recipient) {
            $email_key = $recipient['email'];
            if (!isset($all_recipients[$email_key])) {
              $all_recipients[$email_key] = $recipient;
            }
          }
        }
      }
    }

    if (!empty($replacement_template_id) && !empty($all_recipients)) {
      $event = get_post($event_id);
      $recipients_array = array_values($all_recipients);
      $event_params = [
        'event_title' => $event ? $event->post_title : __('Cancellation message', 'aio-event-solution'),
        'event_date' => date_i18n(get_option('date_format')),
        'event_join_url' => $event ? get_permalink($event->ID) : home_url(),
      ];

      $result = $brevo->schedule_email(
        $replacement_template_id,
        $recipients_array,
        $event_params,
        null,
        ['tags' => ['event-replacement', 'event-' . $event_id]]
      );

      if (!is_wp_error($result)) {
        $replacement_sent_count = count($recipients_array);
      }
    }

    // Check if emails are already cancelled (to toggle)
    $emails_already_cancelled = get_post_meta($event_id, '_aio_event_emails_cancelled', true) === '1';

    if ($emails_already_cancelled) {
      // Restore emails - remove cancellation flag
      delete_post_meta($event_id, '_aio_event_emails_cancelled');
      $message = __('Email scheduling restored for this event. New emails will be scheduled automatically.', 'aio-event-solution');
    } else {
      // Cancel emails - set cancellation flag to prevent future scheduling
      update_post_meta($event_id, '_aio_event_emails_cancelled', '1');
      $message = sprintf(
        __('Cancelled %d scheduled emails for this event. Automatic scheduling of future emails has been disabled.', 'aio-event-solution'),
        $cancelled_count
      );

      if (!empty($replacement_template_id) && $replacement_sent_count > 0) {
        $message .= ' ' . sprintf(
          __('Sent %d replacement emails.', 'aio-event-solution'),
          $replacement_sent_count
        );
      }
    }

    wp_send_json_success(['message' => $message]);
  }

  /**
   * AJAX handler to create registrations table
   */
  public function ajax_create_registrations_table()
  {
    check_ajax_referer('aio_create_table', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'aio_event_registrations';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      event_id bigint(20) unsigned NOT NULL,
      name varchar(255) NOT NULL,
      email varchar(255) NOT NULL,
      phone varchar(50) DEFAULT NULL,
      registered_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      brevo_added tinyint(1) DEFAULT 0 NOT NULL,
      join_token varchar(255) DEFAULT NULL,
      clicked_join_link tinyint(1) DEFAULT 0 NOT NULL,
      PRIMARY KEY  (id),
      KEY event_id (event_id),
      KEY email (email),
      KEY join_token (join_token)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/php/upgrade.php';
    dbDelta($sql);

    // Add join_token column if it doesn't exist (migration for existing tables)
    $column_exists = $wpdb->get_results($wpdb->prepare(
      "SHOW COLUMNS FROM $table_name LIKE %s",
      'join_token'
    ));
    if (empty($column_exists)) {
      $wpdb->query("ALTER TABLE $table_name ADD COLUMN join_token varchar(255) DEFAULT NULL");
      $wpdb->query("ALTER TABLE $table_name ADD INDEX join_token (join_token)");
    }

    // Add clicked_join_link column if it doesn't exist (migration for existing tables)
    $clicked_column_exists = $wpdb->get_results($wpdb->prepare(
      "SHOW COLUMNS FROM $table_name LIKE %s",
      'clicked_join_link'
    ));
    if (empty($clicked_column_exists)) {
      $wpdb->query("ALTER TABLE $table_name ADD COLUMN clicked_join_link tinyint(1) DEFAULT 0 NOT NULL");
    }

    // Check if table was created
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
      wp_send_json_success(['message' => __('Registrations table created successfully!', 'aio-event-solution')]);
    } else {
      wp_send_json_error(['message' => __('Failed to create table. Check database permissions.', 'aio-event-solution')]);
    }
  }

  /**
   * AJAX handler to export registrations to CSV
   */
  public function ajax_export_registrations_csv()
  {
    check_ajax_referer('aio_export_csv', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_die(__('Permission denied', 'aio-event-solution'));
    }

    $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;

    if (empty($event_id)) {
      wp_die(__('Invalid event ID', 'aio-event-solution'));
    }

    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';

    if (!\AIOEvents\Repositories\RegistrationRepository::table_exists()) {
      wp_die(__('Tabela rejestracji nie istnieje', 'aio-event-solution'));
    }

    $registrations = \AIOEvents\Repositories\RegistrationRepository::get_by_event_id($event_id);
    $event = get_post($event_id);

    if (!$event) {
      wp_die(__('Event not found', 'aio-event-solution'));
    }

    // Set headers for CSV download
    $filename = 'rejestracje-event-' . $event_id . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Add BOM for UTF-8
    echo "\xEF\xBB\xBF";

    // Open output stream
    $output = fopen('php://output', 'w');

    // CSV headers - same as table columns
    $headers = [
      __('Name', 'aio-event-solution'),
      __('Email', 'aio-event-solution'),
      __('Registration Date', 'aio-event-solution'),
      __('Attendance', 'aio-event-solution'),
    ];
    fputcsv($output, $headers, ';');

    // CSV rows - same columns as table
    foreach ($registrations as $registration) {
      $row = [
        $registration['name'] ?? '',
        $registration['email'] ?? '',
        $registration['registered_at'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($registration['registered_at'])) : '',
        !empty($registration['clicked_join_link']) ? __('Yes', 'aio-event-solution') : __('No', 'aio-event-solution'),
      ];
      fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;
  }

  /**
   * Register custom post types
   */
  public function register_post_types()
  {
    EventPostType::register();
  }

  /**
   * Register admin pages
   */
  public function register_admin_pages()
  {
    SettingsPage::register();
    require_once AIO_EVENTS_PATH . 'php/Admin/ScheduledEmailsPage.php';
    \AIOEvents\Admin\ScheduledEmailsPage::register();

    require_once AIO_EVENTS_PATH . 'php/Admin/CronStatusPage.php';
    \AIOEvents\Admin\CronStatusPage::register();
  }

  /**
   * Save registration data to database and send confirmation email
   * This is a reusable method called from both AJAX and webhook handlers
   */
  public function save_registration_data($event_id, $email, $name, $phone = '', $from_brevo = false)
  {
    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';
    
    if (!\AIOEvents\Repositories\RegistrationRepository::table_exists()) {
      return new \WP_Error('no_table', __('Registrations table does not exist', 'aio-event-solution'));
    }

    // Check if already registered
    if (\AIOEvents\Repositories\RegistrationRepository::is_registered($event_id, $email)) {
      // Already registered - only resend email, don't duplicate in database or Brevo
      require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
      \AIOEvents\Scheduler\EmailScheduler::send_registration_email($event_id, $email, $name);
      return ['success' => true, 'already_registered' => true];
    }

    // Generate join token
    require_once AIO_EVENTS_PATH . 'php/JoinTokenGenerator.php';
    $join_token = \AIOEvents\JoinTokenGenerator::generate($event_id, $email);

    // Create new registration
    $result = \AIOEvents\Repositories\RegistrationRepository::create(
      $event_id,
      $email,
      $name,
      $phone,
      $from_brevo,
      $join_token
    );

    if (is_wp_error($result)) {
      return $result;
    }

    $insert_id = $result['insert_id'];

    // Add to Brevo list if configured and not already added via form
    if (!$from_brevo) {
      $settings = get_option('aio_events_settings', []);
      $global_default_list_id = $settings['default_brevo_list_id'] ?? '';

      // Get event-specific list ID, fallback to global default
      $event_list_id = get_post_meta($event_id, '_aio_event_brevo_list_id', true);
      if (empty($event_list_id) && !empty($global_default_list_id)) {
        $event_list_id = $global_default_list_id;
      }

      // Also check old meta key for migration
      if (empty($event_list_id)) {
        $old_list_ids = get_post_meta($event_id, '_aio_event_brevo_list_ids', true);
        if (is_array($old_list_ids) && !empty($old_list_ids)) {
          $event_list_id = absint($old_list_ids[0]); // Use first list from old array
        }
      }

      if (!empty($event_list_id)) {
        require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
        $brevo = new \AIOEvents\Integrations\BrevoAPI();

        if ($brevo->is_configured()) {
          $name_parts = explode(' ', $name, 2);
          $first_name = $name_parts[0] ?? '';
          $last_name = $name_parts[1] ?? '';

          $attributes = [
            'FIRSTNAME' => $first_name,
            'LASTNAME' => $last_name,
          ];
          if (!empty($phone)) {
            $attributes['SMS'] = $phone;
          }

          // Update EVENTS attribute - append event ID to existing list
          $existing_contact = $brevo->get_contact($email);
          $events_attribute_name = 'EVENTS';
          
          if (!is_wp_error($existing_contact) && $existing_contact !== null) {
            // Contact exists - get existing EVENTS attribute
            $existing_events = $existing_contact['attributes'][$events_attribute_name] ?? '';
            $existing_event_ids = [];
            
            if (!empty($existing_events)) {
              // Parse existing event IDs (comma-separated)
              $existing_event_ids = array_map('trim', explode(',', $existing_events));
              $existing_event_ids = array_filter($existing_event_ids); // Remove empty values
            }
            
            // Add current event ID if not already present
            $event_id_str = (string) $event_id;
            if (!in_array($event_id_str, $existing_event_ids, true)) {
              $existing_event_ids[] = $event_id_str;
            }
            
            // Join back with commas
            $attributes[$events_attribute_name] = implode(',', $existing_event_ids);
          } else {
            // New contact - just add current event ID
            $attributes[$events_attribute_name] = (string) $event_id;
          }

          $brevo->add_contact_to_list($email, $event_list_id, $attributes);
          \AIOEvents\Repositories\RegistrationRepository::update($insert_id, ['brevo_added' => true]);
        }
      }
    }

    // Send confirmation email
    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    \AIOEvents\Scheduler\EmailScheduler::send_registration_email($event_id, $email, $name);

    return ['success' => true, 'insert_id' => $insert_id];
  }

  /**
   * Register shortcodes
   */
  public function register_shortcodes()
  {
    require_once AIO_EVENTS_PATH . 'php/Shortcodes/EventsShortcode.php';
    \AIOEvents\Shortcodes\EventsShortcode::register();
  }

  /**
   * Load event templates
   */
  public function load_event_templates($template)
  {
    if (is_singular('aio_event')) {
      $plugin_template = AIO_EVENTS_PATH . 'php/Frontend/single-aio_event.php';
      if (file_exists($plugin_template)) {
        return $plugin_template;
      }
    }

    // Archive only for taxonomies (categories and tags), not for post type archive
    if (is_tax('aio_event_category') || is_tax('aio_event_tag')) {
      $plugin_template = AIO_EVENTS_PATH . 'php/Frontend/archive-aio_event.php';
      if (file_exists($plugin_template)) {
        return $plugin_template;
      }
    }

    return $template;
  }

  /**
   * Enqueue admin assets
   */
  public function enqueue_admin_assets($hook)
  {
    // Load on event pages and settings
    $event_pages = ['post.php', 'post-new.php', 'edit.php'];

    // Check if it's an event page
    $is_event_page = false;
    if (in_array($hook, $event_pages)) {
      $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : '';
      if (empty($post_type) && isset($_GET['post'])) {
        $post_type = get_post_type($_GET['post']);
      }
      $is_event_page = $post_type === 'aio_event';
    }

    $is_settings_page = strpos($hook, 'aio-events-settings') !== false;

    if (!$is_event_page && !$is_settings_page) {
      return;
    }

    // Get colors from settings
    $settings = get_option('aio_events_settings', []);
    $primary_color = $settings['primary_color'] ?? '#2271b1';
    $secondary_color = $settings['secondary_color'] ?? '#f0f0f1';

    wp_enqueue_style(
      'aio-events-admin',
      AIO_EVENTS_URL . 'assets/css/generated/admin.min.css',
      [],
      AIO_EVENTS_VERSION
    );

    // Inject CSS variables for admin
    $custom_css = "
      :root {
        --aio-events-color-primary: {$primary_color};
        --aio-events-color-primary-hover: " . $this->adjust_brightness($primary_color, -20) . ";
        --aio-events-color-primary-light: " . $this->adjust_brightness($primary_color, 85) . ";
        --aio-events-color-secondary: {$secondary_color};
        --aio-events-color-secondary-hover: " . $this->adjust_brightness($secondary_color, -10) . ";
        --aio-events-color-secondary-dark: #50575e;
      }
    ";
    wp_add_inline_style('aio-events-admin', $custom_css);

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
  public function enqueue_frontend_assets()
  {
    // Get colors from settings
    $settings = get_option('aio_events_settings', []);
    $primary_color = $settings['primary_color'] ?? '#2271b1';
    $secondary_color = $settings['secondary_color'] ?? '#f0f0f1';

    wp_enqueue_style(
      'aio-events-frontend',
      AIO_EVENTS_URL . 'assets/css/generated/frontend.min.css',
      [],
      AIO_EVENTS_VERSION
    );

    // Get content box background color
    $content_box_background = $settings['content_box_background'] ?? '#f3f3f3';

    // Inject CSS variables
    $custom_css = "
      :root {
        --aio-events-color-primary: {$primary_color};
        --aio-events-color-primary-hover: " . $this->adjust_brightness($primary_color, -20) . ";
        --aio-events-color-primary-light: " . $this->adjust_brightness($primary_color, 85) . ";
        --aio-events-color-secondary: {$secondary_color};
        --aio-events-color-secondary-hover: " . $this->adjust_brightness($secondary_color, -10) . ";
        --aio-events-color-secondary-dark: #50575e;
        --aio-events-background: {$content_box_background};
      }
    ";
    wp_add_inline_style('aio-events-frontend', $custom_css);

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
      'i18n' => [
        'registering' => __('Registering...', 'aio-event-solution'),
        'registerNow' => __('Register Now', 'aio-event-solution'),
        'error' => __('Error', 'aio-event-solution'),
        'success' => __('Success', 'aio-event-solution'),
        'loading' => __('Loading...', 'aio-event-solution'),
        'registered' => __('Registered!', 'aio-event-solution'),
        'errorOccurred' => __('An error occurred. Please try again.', 'aio-event-solution'),
        'invalidResponse' => __('Invalid server response', 'aio-event-solution'),
        'errorGeneric' => __('An error occurred', 'aio-event-solution'),
      ],
    ]);
  }


  /**
   * Adjust color brightness
   */
  private function adjust_brightness($hex, $percent)
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

/**
 * Helper function to get UTC start and end times for an event
 * Must be in global namespace for Twig/Timber function() calls
 *
 * @param string $date Event date (Y-m-d)
 * @param string $time Event time (H:i)
 * @param int $duration_hours Duration in hours
 * @return array ['start' => 'YYYYMMDDTHHmmssZ', 'end' => 'YYYYMMDDTHHmmssZ']
 */
function aio_event_utc_times($date, $time, $duration_hours = 1)
{
  if (empty($date) || empty($time)) {
    return ['start' => '', 'end' => ''];
  }

  $wp_timezone = wp_timezone();
  $datetime_str = $date . ' ' . $time;

  try {
    $start = new \DateTime($datetime_str, $wp_timezone);
    $end = clone $start;
    $end->modify('+' . $duration_hours . ' hour');

    // Convert to UTC
    $utc = new \DateTimeZone('UTC');
    $start->setTimezone($utc);
    $end->setTimezone($utc);

    return [
      'start' => $start->format('Ymd\THis\Z'),
      'end' => $end->format('Ymd\THis\Z'),
    ];
  } catch (\Exception $e) {
    return ['start' => '', 'end' => ''];
  }
}

<?php

namespace AIOEvents\Scheduler;

use AIOEvents\Integrations\BrevoAPI;

/**
 * Email Scheduler
 * Handles scheduling event notification emails through Brevo
 */
class EmailScheduler
{
  private static $table_name = null;

  /**
   * Get scheduled emails table name
   */
  public static function get_table_name()
  {
    if (self::$table_name === null) {
      global $wpdb;
      self::$table_name = $wpdb->prefix . 'aio_event_scheduled_emails';
    }
    return self::$table_name;
  }

  /**
   * Create scheduled emails table
   */
  public static function create_table()
  {
    global $wpdb;
    $table_name = self::get_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      event_id bigint(20) unsigned NOT NULL,
      event_title varchar(255) NOT NULL,
      event_date date NOT NULL,
      brevo_template_id int(11) DEFAULT NULL,
      recipient_list_ids text DEFAULT NULL,
      scheduled_for datetime NOT NULL,
      scheduled_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      brevo_message_id varchar(255) DEFAULT NULL,
      status varchar(20) DEFAULT 'pending' NOT NULL,
      error_message text DEFAULT NULL,
      created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      PRIMARY KEY  (id),
      KEY event_id (event_id),
      KEY scheduled_for (scheduled_for),
      KEY status (status),
      KEY brevo_message_id (brevo_message_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
  }

  /**
   * Run daily scheduler - called by cron
   * Plans emails for events that are X days in the future
   */
  public static function run_daily_schedule()
  {
    $settings = get_option('aio_events_settings', []);
    $global_before_template_id = absint($settings['email_template_before_event'] ?? 0);
    $global_join_template_id = absint($settings['email_template_join_event'] ?? 0);
    $global_after_template_id = absint($settings['email_template_after_event'] ?? 0);

    // Get timing settings (in minutes)
    $time_before_event = absint($settings['email_time_before_event'] ?? 1440); // Default: 24h
    $time_join_event = absint($settings['email_time_join_event'] ?? 10); // Default: 10 minutes
    $time_after_event = absint($settings['email_time_after_event'] ?? 120); // Default: 2h

    $brevo = new BrevoAPI();
    if (!$brevo->is_configured()) {
      error_log('[AIO Events] Brevo API key not configured');
      return false;
    }

    $scheduled_count = 0;
    $error_count = 0;

    // Get all upcoming events (within next 7 days to cover all email types)
    $today = date('Y-m-d');
    $future_date = date('Y-m-d', strtotime('+7 days'));
    $events = self::get_upcoming_events_range($today, $future_date);

    foreach ($events as $event) {
      // Skip if emails are cancelled for this event
      if (get_post_meta($event->ID, '_aio_event_emails_cancelled', true) === '1') {
        continue;
      }

      $event_date = get_post_meta($event->ID, '_aio_event_start_date', true);
      $event_time = get_post_meta($event->ID, '_aio_event_start_time', true);
      
      if (empty($event_date)) {
        continue;
      }

      // Parse event datetime
      $event_datetime_str = $event_date . ' ' . ($event_time ?: '00:00');
      $event_datetime = strtotime($event_datetime_str);
      
      if (!$event_datetime) {
        continue;
      }

      // 1. Schedule "before event" reminder email
      if (!empty($global_before_template_id)) {
        $event_template_id = absint(get_post_meta($event->ID, '_aio_event_email_template_before_event', true));
        $template_id = !empty($event_template_id) ? $event_template_id : $global_before_template_id;

        if (!empty($template_id)) {
          // Calculate send time: event time minus configured minutes
          $send_datetime = $event_datetime - ($time_before_event * 60);
          $send_date = date('Y-m-d', $send_datetime);
          $send_time = date('H:i', $send_datetime);

          // Only schedule if in the future and within 24 hours (Brevo limit)
          // If further than 24h, it will be saved as pending and scheduled later
          if ($send_datetime > time() && $send_datetime <= (time() + DAY_IN_SECONDS)) {
            $result = self::schedule_event_email($event, $template_id, $send_date, $send_time, 'before');
            if ($result && !is_wp_error($result)) {
              $scheduled_count++;
            } elseif (is_wp_error($result)) {
              $error_count++;
              error_log('[AIO Events Scheduler] Error scheduling before email for event ' . $event->ID . ': ' . $result->get_error_message());
            }
          } elseif ($send_datetime > (time() + DAY_IN_SECONDS)) {
            // Email is more than 24h away - save as pending to schedule later
            $result = self::schedule_event_email($event, $template_id, $send_date, $send_time, 'before');
            if (is_array($result) && isset($result['status']) && $result['status'] === 'pending') {
              // Successfully saved as pending
              $scheduled_count++;
            }
          }
        }
      }

      // 2. Schedule "join event" email (10 minutes before)
      if (!empty($global_join_template_id)) {
        $event_template_id = absint(get_post_meta($event->ID, '_aio_event_email_template_join_event', true));
        $template_id = !empty($event_template_id) ? $event_template_id : $global_join_template_id;

        if (!empty($template_id)) {
          // Calculate send time: event time minus configured minutes
          $send_datetime = $event_datetime - ($time_join_event * 60);
          $send_date = date('Y-m-d', $send_datetime);
          $send_time = date('H:i', $send_datetime);

          // Only schedule if in the future and within 24 hours (Brevo limit)
          // If further than 24h, it will be saved as pending and scheduled later
          if ($send_datetime > time() && $send_datetime <= (time() + DAY_IN_SECONDS)) {
            $result = self::schedule_event_email($event, $template_id, $send_date, $send_time, 'join');
            if ($result && !is_wp_error($result)) {
              $scheduled_count++;
            } elseif (is_wp_error($result)) {
              $error_count++;
              error_log('[AIO Events Scheduler] Error scheduling join email for event ' . $event->ID . ': ' . $result->get_error_message());
            }
          } elseif ($send_datetime > (time() + DAY_IN_SECONDS)) {
            // Email is more than 24h away - save as pending to schedule later
            $result = self::schedule_event_email($event, $template_id, $send_date, $send_time, 'join');
            if (is_array($result) && isset($result['status']) && $result['status'] === 'pending') {
              // Successfully saved as pending
              $scheduled_count++;
            }
          }
        }
      }

      // 3. Schedule "after event" email (2h after)
      if (!empty($global_after_template_id)) {
        $event_template_id = absint(get_post_meta($event->ID, '_aio_event_email_template_after_event', true));
        $template_id = !empty($event_template_id) ? $event_template_id : $global_after_template_id;

        if (!empty($template_id)) {
          // Only schedule if event already happened (or is happening now)
          if ($event_datetime < time()) {
            // Calculate send time: event time plus configured minutes
            $send_datetime = $event_datetime + ($time_after_event * 60);
            $send_date = date('Y-m-d', $send_datetime);
            $send_time = date('H:i', $send_datetime);

            // Only schedule if in the future and within 24 hours (Brevo limit)
            // If further than 24h, it will be saved as pending and scheduled later
            if ($send_datetime > time() && $send_datetime <= (time() + DAY_IN_SECONDS)) {
              $result = self::schedule_event_email($event, $template_id, $send_date, $send_time, 'after');
              if ($result && !is_wp_error($result)) {
                $scheduled_count++;
              } elseif (is_wp_error($result)) {
                $error_count++;
                error_log('[AIO Events Scheduler] Error scheduling after email for event ' . $event->ID . ': ' . $result->get_error_message());
              }
            } elseif ($send_datetime > (time() + DAY_IN_SECONDS)) {
              // Email is more than 24h away - save as pending to schedule later
              $result = self::schedule_event_email($event, $template_id, $send_date, $send_time, 'after');
              if (is_array($result) && isset($result['status']) && $result['status'] === 'pending') {
                // Successfully saved as pending
                $scheduled_count++;
              }
            }
          }
        }
      }
    }

    // Retry pending emails
    $pending_result = self::retry_pending_emails(null);
    if (is_array($pending_result)) {
      $scheduled_count += $pending_result['scheduled'] ?? 0;
      $error_count += $pending_result['errors'] ?? 0;
    }

    return [
      'scheduled' => $scheduled_count,
      'errors' => $error_count,
    ];
  }

  /**
   * Get upcoming events for a specific date
   */
  private static function get_upcoming_events($target_date)
  {
    $args = [
      'post_type' => 'aio_event',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'meta_query' => [
        [
          'key' => '_aio_event_start_date',
          'value' => $target_date,
          'compare' => '=',
          'type' => 'DATE',
        ],
      ],
    ];

    $query = new \WP_Query($args);
    return $query->posts;
  }

  /**
   * Get upcoming events within a date range
   */
  private static function get_upcoming_events_range($start_date, $end_date)
  {
    $args = [
      'post_type' => 'aio_event',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'meta_query' => [
        [
          'key' => '_aio_event_start_date',
          'value' => [$start_date, $end_date],
          'compare' => 'BETWEEN',
          'type' => 'DATE',
        ],
      ],
      'orderby' => 'meta_value',
      'meta_key' => '_aio_event_start_date',
      'order' => 'ASC',
    ];

    $query = new \WP_Query($args);
    return $query->posts;
  }

  /**
   * Get past events that ended today or yesterday
   */
  private static function get_past_events($after_date)
  {
    $args = [
      'post_type' => 'aio_event',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'meta_query' => [
        [
          'key' => '_aio_event_start_date',
          'value' => $after_date,
          'compare' => '<=',
          'type' => 'DATE',
        ],
      ],
    ];

    $query = new \WP_Query($args);
    return $query->posts;
  }

  /**
   * Schedule email for a single event
   */
  public static function schedule_event_email($event, $template_id, $target_date, $schedule_time = '23:30', $email_type = 'before')
  {
    require_once AIO_EVENTS_PATH . 'php/Repositories/ScheduledEmailRepository.php';

    if (!is_object($event)) {
      $event = get_post($event);
    }

    if (!$event || $event->post_type !== 'aio_event') {
      return new \WP_Error('invalid_event', __('Invalid event', 'aio-event-solution'));
    }

    $event_date = get_post_meta($event->ID, '_aio_event_start_date', true);
    
    // Get event-specific list ID, fallback to global default
    $settings = get_option('aio_events_settings', []);
    $global_default_list_id = $settings['default_brevo_list_id'] ?? '';
    
    $event_list_id = get_post_meta($event->ID, '_aio_event_brevo_list_id', true);
    if (empty($event_list_id) && !empty($global_default_list_id)) {
      $event_list_id = $global_default_list_id;
    }
    
    // Also check old meta key for migration
    if (empty($event_list_id)) {
      $old_list_ids = get_post_meta($event->ID, '_aio_event_brevo_list_ids', true);
      if (is_array($old_list_ids) && !empty($old_list_ids)) {
        $event_list_id = absint($old_list_ids[0]); // Use first list from old array
      }
    }
    
    // Convert to array for compatibility with existing code
    $brevo_list_ids = !empty($event_list_id) ? [$event_list_id] : [];

    if (empty($brevo_list_ids)) {
      return new \WP_Error('no_lists', __('Event has no Brevo lists configured', 'aio-event-solution'));
    }

    $scheduled_datetime = sprintf('%s %s:00', $target_date, $schedule_time);
    $scheduled_for = date('Y-m-d H:i:s', strtotime($scheduled_datetime));
    // Brevo limit: max 24 hours in advance (some plans may allow 48h, but 24h is safer)
    $max_schedule_time = strtotime('+24 hours');

    $brevo = new BrevoAPI();
    if (!$brevo->is_configured()) {
      return new \WP_Error('no_api', __('Brevo API not configured', 'aio-event-solution'));
    }

    if (strtotime($scheduled_for) > $max_schedule_time) {
      \AIOEvents\Repositories\ScheduledEmailRepository::create([
        'event_id' => $event->ID,
        'event_title' => $event->post_title,
        'event_date' => $event_date,
        'brevo_template_id' => $template_id,
        'recipient_list_ids' => $brevo_list_ids,
        'scheduled_for' => $scheduled_for,
        'status' => 'pending',
        'error_message' => __('Too far in the future - will be scheduled when possible (max 24h)', 'aio-event-solution'),
      ]);
      return ['status' => 'pending', 'message' => __('Saved as pending - will be scheduled later', 'aio-event-solution')];
    }

    $recipients = self::get_recipients_from_lists($brevo_list_ids);
    if (empty($recipients)) {
      \AIOEvents\Repositories\ScheduledEmailRepository::create([
        'event_id' => $event->ID,
        'event_title' => $event->post_title,
        'event_date' => $event_date,
        'brevo_template_id' => $template_id,
        'recipient_list_ids' => $brevo_list_ids,
        'scheduled_for' => $scheduled_for,
        'status' => 'failed',
        'error_message' => __('No recipients in configured lists', 'aio-event-solution'),
      ]);
      return new \WP_Error('no_recipients', __('No recipients found in configured lists', 'aio-event-solution'));
    }

    // For scheduled emails, we need to send individual emails per recipient
    // to provide personalized join links with tokens
    // Brevo doesn't support per-recipient params in bulk sends
    $scheduled_at_iso = gmdate('Y-m-d\TH:i:s\Z', strtotime($scheduled_for));
    $success_count = 0;
    $error_count = 0;
    $last_error = null;

    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';

    foreach ($recipients as $recipient) {
      $recipient_email = $recipient['email'] ?? '';
      if (empty($recipient_email)) {
        continue;
      }

      // Get join token for this recipient
      $registration = \AIOEvents\Repositories\RegistrationRepository::get_by_event_and_email($event->ID, $recipient_email);
      $event_join_url = '';

      if (!empty($registration['join_token'])) {
        $rest_url = rest_url('aio-events/v1/join');
        $event_join_url = add_query_arg('token', urlencode($registration['join_token']), $rest_url);
      } else {
        $event_join_url = rest_url('aio-events/v1/join');
      }

      $event_time = get_post_meta($event->ID, '_aio_event_start_time', true);
      $timezone_string = wp_timezone_string();
      $event_time_with_tz = $event_time ? $event_time . ' (' . $timezone_string . ')' : '';

      $event_params = [
        'event_title' => $event->post_title,
        'event_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
        'event_time' => $event_time_with_tz,
        'event_join_url' => $event_join_url,
        'timezone' => $timezone_string,
      ];

      // Send individual email for this recipient
      $result = $brevo->schedule_email(
        $template_id,
        [$recipient],
        $event_params,
        $scheduled_at_iso,
        ['tags' => ['event-notification', 'event-' . $event->ID]]
      );

      if (is_wp_error($result)) {
        $error_count++;
        $last_error = $result;
      } else {
        $success_count++;
      }
    }

    // If all failed, return error
    if ($success_count === 0 && $error_count > 0) {
      $result = $last_error ?: new \WP_Error('all_failed', __('All emails were not sent', 'aio-event-solution'));
    } else {
      // Create success result
      $result = [
        'code' => 200,
        'message_id' => 'multiple-' . $success_count,
        'success_count' => $success_count,
        'error_count' => $error_count,
      ];
    }

    if (is_wp_error($result)) {
      $existing = \AIOEvents\Repositories\ScheduledEmailRepository::get_by_event_and_scheduled($event->ID, $scheduled_for);

      if ($existing) {
        \AIOEvents\Repositories\ScheduledEmailRepository::update($existing['id'], [
          'status' => 'failed',
          'error_message' => $result->get_error_message(),
        ]);
      } else {
        \AIOEvents\Repositories\ScheduledEmailRepository::create([
          'event_id' => $event->ID,
          'event_title' => $event->post_title,
          'event_date' => $event_date,
          'brevo_template_id' => $template_id,
          'recipient_list_ids' => $brevo_list_ids,
          'scheduled_for' => $scheduled_for,
          'status' => 'failed',
          'error_message' => $result->get_error_message(),
        ]);
      }
      return $result;
    }

    $existing = \AIOEvents\Repositories\ScheduledEmailRepository::get_by_event_and_scheduled($event->ID, $scheduled_for);

    if ($existing) {
      $error_msg = $error_count > 0 ? sprintf(__('Sent %d out of %d emails', 'aio-event-solution'), $success_count, $success_count + $error_count) : null;
      \AIOEvents\Repositories\ScheduledEmailRepository::update($existing['id'], [
        'brevo_message_id' => is_array($result) ? ($result['message_id'] ?? null) : null,
        'status' => 'scheduled',
        'error_message' => $error_msg,
      ]);
    } else {
      $error_msg = $error_count > 0 ? sprintf(__('Sent %d out of %d emails', 'aio-event-solution'), $success_count, $success_count + $error_count) : null;
      \AIOEvents\Repositories\ScheduledEmailRepository::create([
        'event_id' => $event->ID,
        'event_title' => $event->post_title,
        'event_date' => $event_date,
        'brevo_template_id' => $template_id,
        'recipient_list_ids' => $brevo_list_ids,
        'scheduled_for' => $scheduled_for,
        'brevo_message_id' => is_array($result) ? ($result['message_id'] ?? null) : null,
        'status' => 'scheduled',
        'error_message' => $error_msg,
      ]);
    }

    return $result;
  }

  /**
   * Get recipients from Brevo lists
   * Note: This fetches contacts from lists and prepares recipient format
   */
  public static function get_recipients_from_lists($list_ids)
  {
    $settings = get_option('aio_events_settings', []);
    $api_key = $settings['brevo_api_key'] ?? '';

    if (empty($api_key)) {
      return [];
    }

    $all_recipients = [];

    foreach ($list_ids as $list_id) {
      $list_contacts = self::get_list_contacts($list_id, $api_key);
      if (!is_wp_error($list_contacts) && is_array($list_contacts)) {
        $all_recipients = array_merge($all_recipients, $list_contacts);
      }
    }

    $unique_recipients = [];
    $seen_emails = [];
    foreach ($all_recipients as $contact) {
      $email = $contact['email'] ?? '';
      if (!empty($email) && !isset($seen_emails[$email])) {
        $seen_emails[$email] = true;
        $name_parts = [];
        if (!empty($contact['attributes']['FIRSTNAME'])) {
          $name_parts[] = $contact['attributes']['FIRSTNAME'];
        }
        if (!empty($contact['attributes']['LASTNAME'])) {
          $name_parts[] = $contact['attributes']['LASTNAME'];
        }
        $full_name = !empty($name_parts) ? implode(' ', $name_parts) : '';

        $unique_recipients[] = [
          'email' => $email,
          'name' => $full_name,
        ];
      }
    }

    return $unique_recipients;
  }

  /**
   * Get contacts from a Brevo list
   */
  private static function get_list_contacts($list_id, $api_key)
  {
    $url = 'https://api.brevo.com/v3/contacts/lists/' . absint($list_id) . '/contacts?limit=1000';
    $page = 1;
    $all_contacts = [];

    do {
      $response = wp_remote_get($url . '&offset=' . (($page - 1) * 1000), [
        'headers' => [
          'api-key' => $api_key,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 15,
      ]);

      if (is_wp_error($response)) {
        return $response;
      }

      $body = wp_remote_retrieve_body($response);
      $data = json_decode($body, true);

      if (wp_remote_retrieve_response_code($response) !== 200) {
        return new \WP_Error('brevo_api_error', $data['message'] ?? __('Failed to fetch contacts', 'aio-event-solution'));
      }

      $contacts = $data['contacts'] ?? [];
      $all_contacts = array_merge($all_contacts, $contacts);
      $page++;
    } while (!empty($contacts) && count($contacts) === 1000);

    return $all_contacts;
  }

  /**
   * Get all scheduled emails
   */
  public static function get_scheduled_emails($args = [])
  {
    require_once AIO_EVENTS_PATH . 'php/Repositories/ScheduledEmailRepository.php';
    return \AIOEvents\Repositories\ScheduledEmailRepository::get_all($args);
  }

  /**
   * Send registration email immediately after registration
   */
  public static function send_registration_email($event_id, $email, $name)
  {
    // Prevent duplicate sends using transient (30 second window)
    $transient_key = 'aio_email_sent_' . md5($event_id . '_' . $email);
    if (get_transient($transient_key)) {
      error_log('[AIO Events] DUPLICATE BLOCKED (transient) - email: ' . $email . ', event: ' . $event_id);
      return true;
    }
    set_transient($transient_key, true, 30); // Block duplicates for 30 seconds
    
    $event_template_id = absint(get_post_meta($event_id, '_aio_event_email_template_after_registration', true));
    $settings = get_option('aio_events_settings', []);
    $global_template_id = absint($settings['email_template_after_registration'] ?? 0);
    $template_id = !empty($event_template_id) ? $event_template_id : $global_template_id;

    if (empty($template_id)) {
      return false;
    }

    $brevo = new BrevoAPI();
    if (!$brevo->is_configured()) {
      return false;
    }

    $event = get_post($event_id);
    if (!$event || $event->post_type !== 'aio_event') {
      return false;
    }

    $event_date = get_post_meta($event_id, '_aio_event_start_date', true);
    $event_time = get_post_meta($event_id, '_aio_event_start_time', true);

    // Get join token from database
    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';
    $registration = \AIOEvents\Repositories\RegistrationRepository::get_by_event_and_email($event_id, $email);

    // Generate join link - always use our endpoint
    $event_join_url = '';
    if (!empty($registration['join_token'])) {
      $rest_url = rest_url('aio-events/v1/join');
      $event_join_url = add_query_arg('token', urlencode($registration['join_token']), $rest_url);
    } else {
      $event_join_url = get_permalink($event->ID);
    }

    $recipients = [[
      'email' => $email,
      'name' => $name,
    ]];

    $timezone_string = wp_timezone_string();
    $event_time_with_tz = $event_time ? $event_time . ' (' . $timezone_string . ')' : '';

    $event_params = [
      'event_title' => $event->post_title,
      'event_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
      'event_time' => $event_time_with_tz,
      'event_join_url' => $event_join_url,
      'recipient_name' => $name,
      'timezone' => $timezone_string,
    ];

    // Generate .ics calendar attachment
    require_once AIO_EVENTS_PATH . 'php/Helpers/IcsGenerator.php';
    $ics_attachment = \AIOEvents\Helpers\IcsGenerator::generate_brevo_attachment($event_id);

    $options = [
      'tags' => ['event-registration', 'event-' . $event->ID],
    ];

    if ($ics_attachment) {
      $options['attachment'] = [$ics_attachment];
    }

    $result = $brevo->schedule_email(
      $template_id,
      $recipients,
      $event_params,
      null,
      $options
    );

    if (is_wp_error($result)) {
      error_log('[AIO Events] Failed to send registration email: ' . $result->get_error_message());
      return false;
    }

    return true;
  }

  /**
   * Get scheduled email count
   */
  public static function get_scheduled_count($status = null)
  {
    require_once AIO_EVENTS_PATH . 'php/Repositories/ScheduledEmailRepository.php';
    return \AIOEvents\Repositories\ScheduledEmailRepository::count_by_status($status);
  }

  /**
   * Retry pending emails that can now be scheduled (within 24 hours)
   * This is called daily by cron to schedule emails that were too far in the future
   */
  private static function retry_pending_emails($template_id = null)
  {
    require_once AIO_EVENTS_PATH . 'php/Repositories/ScheduledEmailRepository.php';
    // Brevo limit: max 24 hours in advance
    $max_schedule_time = strtotime('+24 hours');

    $pending_emails = \AIOEvents\Repositories\ScheduledEmailRepository::get_pending(100);

    if (empty($pending_emails)) {
      return ['scheduled' => 0, 'errors' => 0];
    }

    $scheduled_count = 0;
    $error_count = 0;
    $brevo = new BrevoAPI();

    foreach ($pending_emails as $email) {
      $scheduled_timestamp = strtotime($email['scheduled_for']);

      if ($scheduled_timestamp > $max_schedule_time) {
        continue;
      }

      $event = get_post($email['event_id']);
      if (!$event || $event->post_type !== 'aio_event') {
        continue;
      }

      // Use template_id from email record if not provided
      $email_template_id = $template_id ?: absint($email['brevo_template_id'] ?? 0);
      if (empty($email_template_id)) {
        continue;
      }

      $brevo_list_ids = json_decode($email['recipient_list_ids'], true);
      if (empty($brevo_list_ids) || !is_array($brevo_list_ids)) {
        continue;
      }

      $recipients = self::get_recipients_from_lists($brevo_list_ids);
      if (empty($recipients)) {
        continue;
      }

      // Get join token for each recipient
      require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';

      // Send individual emails per recipient with personalized join links
      $recipient_success = 0;
      $recipient_errors = 0;

      foreach ($recipients as $recipient) {
        $recipient_email = $recipient['email'] ?? '';
        if (empty($recipient_email)) {
          continue;
        }

        // Get join token for this recipient
        $registration = \AIOEvents\Repositories\RegistrationRepository::get_by_event_and_email($event->ID, $recipient_email);
        $event_join_url = '';

        if (!empty($registration['join_token'])) {
          $rest_url = rest_url('aio-events/v1/join');
          $event_join_url = add_query_arg('token', urlencode($registration['join_token']), $rest_url);
        } else {
          $event_join_url = rest_url('aio-events/v1/join');
        }

        $event_time = get_post_meta($event->ID, '_aio_event_start_time', true);
        $timezone_string = wp_timezone_string();
        $event_time_with_tz = $event_time ? $event_time . ' (' . $timezone_string . ')' : '';

        $event_params = [
          'event_title' => $email['event_title'],
          'event_date' => date_i18n(get_option('date_format'), strtotime($email['event_date'])),
          'event_time' => $event_time_with_tz,
          'event_join_url' => $event_join_url,
          'timezone' => $timezone_string,
        ];

        $scheduled_at_iso = gmdate('Y-m-d\TH:i:s\Z', $scheduled_timestamp);

        $recipient_result = $brevo->schedule_email(
          $email_template_id,
          [$recipient],
          $event_params,
          $scheduled_at_iso,
          ['tags' => ['event-notification', 'event-' . $event->ID]]
        );

        if (is_wp_error($recipient_result)) {
          $recipient_errors++;
        } else {
          $recipient_success++;
        }
      }

      // Update status based on results
      if ($recipient_success === 0 && $recipient_errors > 0) {
        $error_count++;
        \AIOEvents\Repositories\ScheduledEmailRepository::update($email['id'], [
          'status' => 'failed',
          'error_message' => sprintf(__('All %d emails were not sent', 'aio-event-solution'), $recipient_errors),
        ]);
      } elseif ($recipient_success > 0) {
        $scheduled_count++;
        $error_msg = $recipient_errors > 0 ? sprintf(__('Sent %d out of %d emails', 'aio-event-solution'), $recipient_success, $recipient_success + $recipient_errors) : null;
        \AIOEvents\Repositories\ScheduledEmailRepository::update($email['id'], [
          'brevo_message_id' => 'multiple-' . $recipient_success,
          'status' => 'scheduled',
          'error_message' => $error_msg,
        ]);
      }
    }

    return [
      'scheduled' => $scheduled_count,
      'errors' => $error_count,
    ];
  }
}

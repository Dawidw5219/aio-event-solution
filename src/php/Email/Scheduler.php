<?php

namespace AIOEvents\Email;

use AIOEvents\Database\RegistrationRepository;
use AIOEvents\Logging\ActivityLogger;

/**
 * Email Scheduler
 * Handles scheduling event notification emails per-registration
 * 
 * Emails are scheduled in Brevo up to 48h in advance
 */
class Scheduler
{
  /**
   * Maximum hours ahead we can schedule in Brevo
   */
  const MAX_SCHEDULE_HOURS = 48;

  /**
   * Minimum hours before event for reminder (registration must be this early)
   */
  const MIN_REGISTRATION_HOURS_FOR_REMINDER = 24;

  /**
   * Run daily scheduler - called by cron
   * Schedules all pending emails for upcoming events (within 48h window)
   */
  public static function run_daily_schedule()
  {
    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';

    $settings = get_option('aio_events_settings', []);
    
    // Get template IDs
    $reminder_template_id = absint($settings['email_template_before_event'] ?? 0);
    $join_template_id = absint($settings['email_template_join_event'] ?? 0);
    $followup_template_id = absint($settings['email_template_after_event'] ?? 0);

    // Get timing settings (in minutes)
    $time_before_event = absint($settings['email_time_before_event'] ?? 1440); // Default: 24h
    $time_join_event = absint($settings['email_time_join_event'] ?? 10); // Default: 10 min
    $time_after_event = absint($settings['email_time_after_event'] ?? 120); // Default: 2h

    $brevo = new BrevoClient();
    if (!$brevo->is_configured()) {
      ActivityLogger::cron('daily_schedule', 'Brevo API not configured', 0, 1);
      return false;
    }

    $scheduled_count = 0;
    $error_count = 0;
    $now = time();
    $max_schedule_time = $now + (self::MAX_SCHEDULE_HOURS * 3600); // 48h from now

    // Get all events within relevant time window
    $events = self::get_events_in_window();

    foreach ($events as $event) {
      // Skip if event is cancelled or emails are disabled
      if (get_post_meta($event->ID, '_aio_event_cancelled', true) === '1') {
        continue;
      }
      if (get_post_meta($event->ID, '_aio_event_emails_cancelled', true) === '1') {
        continue;
      }

      $event_datetime = self::get_event_datetime($event->ID);
      if (!$event_datetime) {
        continue;
      }

      // Calculate email send times
      $reminder_send_time = $event_datetime - ($time_before_event * 60);
      $join_send_time = $event_datetime - ($time_join_event * 60);
      $followup_send_time = $event_datetime + ($time_after_event * 60);

      // Get event-specific template overrides
      $event_reminder_template = absint(get_post_meta($event->ID, '_aio_event_email_template_before_event', true)) ?: $reminder_template_id;
      $event_join_template = absint(get_post_meta($event->ID, '_aio_event_email_template_join_event', true)) ?: $join_template_id;
      $event_followup_template = absint(get_post_meta($event->ID, '_aio_event_email_template_after_event', true)) ?: $followup_template_id;

      // Process REMINDER emails
      // Schedule if: send_time is within 48h window AND before event
      if ($event_reminder_template && $reminder_send_time > $now && $reminder_send_time <= $max_schedule_time && $reminder_send_time < $event_datetime) {
        $result = self::schedule_email_type(
          $event,
          'reminder',
          $event_reminder_template,
          $brevo,
          $reminder_send_time,
          self::MIN_REGISTRATION_HOURS_FOR_REMINDER
        );
        $scheduled_count += $result['scheduled'];
        $error_count += $result['errors'];
      }
      // Also send immediately if time has passed but still before event
      elseif ($event_reminder_template && $reminder_send_time <= $now && $now < $event_datetime) {
        $result = self::schedule_email_type(
          $event,
          'reminder',
          $event_reminder_template,
          $brevo,
          null, // Send immediately
          self::MIN_REGISTRATION_HOURS_FOR_REMINDER
        );
        $scheduled_count += $result['scheduled'];
        $error_count += $result['errors'];
      }

      // Process JOIN emails
      // Schedule if: send_time is within 48h window
      if ($event_join_template && $join_send_time > $now && $join_send_time <= $max_schedule_time) {
        $result = self::schedule_email_type(
          $event,
          'join',
          $event_join_template,
          $brevo,
          $join_send_time
        );
        $scheduled_count += $result['scheduled'];
        $error_count += $result['errors'];
      }
      // Also send immediately if time has passed but within grace period
      elseif ($event_join_template && $join_send_time <= $now && $now < ($event_datetime + 3600)) {
        $result = self::schedule_email_type(
          $event,
          'join',
          $event_join_template,
          $brevo,
          null // Send immediately
        );
        $scheduled_count += $result['scheduled'];
        $error_count += $result['errors'];
      }

      // Process FOLLOW-UP emails
      // Schedule if: send_time is within 48h window
      if ($event_followup_template && $followup_send_time > $now && $followup_send_time <= $max_schedule_time) {
        $result = self::schedule_email_type(
          $event,
          'followup',
          $event_followup_template,
          $brevo,
          $followup_send_time
        );
        $scheduled_count += $result['scheduled'];
        $error_count += $result['errors'];
      }
      // Also send immediately if time has passed but within 7 days
      elseif ($event_followup_template && $followup_send_time <= $now && $now < ($event_datetime + (7 * DAY_IN_SECONDS))) {
        $result = self::schedule_email_type(
          $event,
          'followup',
          $event_followup_template,
          $brevo,
          null // Send immediately
        );
        $scheduled_count += $result['scheduled'];
        $error_count += $result['errors'];
      }
    }

    // Log cron execution
    ActivityLogger::cron(
      'daily_schedule',
      sprintf('Processed emails: %d scheduled/sent, %d errors', $scheduled_count, $error_count),
      $scheduled_count,
      $error_count
    );

    return [
      'scheduled' => $scheduled_count,
      'errors' => $error_count,
    ];
  }

  /**
   * Schedule specific email type to all registrations that need it
   * 
   * @param \WP_Post $event
   * @param string $email_type 'reminder', 'join', or 'followup'
   * @param int $template_id
   * @param BrevoClient $brevo
   * @param int|null $scheduled_at Timestamp when to send (null = immediately)
   * @param int|null $min_registration_hours For reminder: minimum hours before event registration must be
   * @return array ['scheduled' => int, 'errors' => int]
   */
  private static function schedule_email_type($event, $email_type, $template_id, $brevo, $scheduled_at = null, $min_registration_hours = null)
  {
    $registrations = RegistrationRepository::get_needing_email(
      $event->ID,
      $email_type,
      $min_registration_hours
    );

    if (empty($registrations)) {
      return ['scheduled' => 0, 'errors' => 0];
    }

    $scheduled = 0;
    $errors = 0;

    $event_date = get_post_meta($event->ID, '_aio_event_start_date', true);
    $event_time = get_post_meta($event->ID, '_aio_event_start_time', true);
    $timezone_string = wp_timezone_string();
    $event_time_with_tz = $event_time ? $event_time . ' (' . $timezone_string . ')' : '';

    // Convert timestamp to ISO 8601 for Brevo
    $scheduled_at_iso = $scheduled_at ? gmdate('c', $scheduled_at) : null;

    foreach ($registrations as $registration) {
      try {
        $recipient_email = $registration['email'] ?? '';
        $recipient_name = $registration['name'] ?? '';
        $join_token = $registration['join_token'] ?? '';

        // Skip invalid registrations
        if (empty($recipient_email) || !is_email($recipient_email)) {
          $errors++;
          error_log('[AIO Events] Skipping invalid email: ' . $recipient_email);
          continue;
        }

        // Build join URL
        $event_join_url = '';
        if (!empty($join_token)) {
          $rest_url = rest_url('aio-events/v1/join');
          $event_join_url = add_query_arg('token', urlencode($join_token), $rest_url);
        } else {
          $event_join_url = get_permalink($event->ID);
        }

        // Build params for Brevo template
        $params = [
          'event_title' => $event->post_title,
          'event_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
          'event_time' => $event_time_with_tz,
          'event_join_url' => $event_join_url,
          'attendee_name' => $recipient_name,
          'recipient_name' => $recipient_name, // alias for backwards compatibility
          'timezone' => $timezone_string,
        ];

        // Schedule/send email via Brevo
        $result = $brevo->schedule_email(
          $template_id,
          [['email' => $recipient_email, 'name' => $recipient_name]],
          $params,
          $scheduled_at_iso, // Pass scheduled time to Brevo
          ['tags' => ['event-' . $email_type, 'event-' . $event->ID]]
        );

        if (is_wp_error($result)) {
          $errors++;
          ActivityLogger::email_failed(
            $event->ID,
            $recipient_email,
            $template_id,
            $result->get_error_message()
          );
        } else {
          $scheduled++;
          // Mark email as sent/scheduled for this registration
          RegistrationRepository::mark_email_sent($registration['id'], $email_type);
          
          $action = $scheduled_at ? 'scheduled' : 'sent';
          $time_info = $scheduled_at ? ' for ' . date_i18n('d/m/Y H:i', $scheduled_at) : '';
          
          ActivityLogger::log(
            ActivityLogger::TYPE_EMAIL_SENT,
            'email_' . $email_type,
            sprintf('%s %s email to %s%s', ucfirst($action), $email_type, $recipient_email, $time_info),
            ['template_id' => $template_id, 'registration_id' => $registration['id'], 'scheduled_at' => $scheduled_at_iso],
            $event->ID,
            $recipient_email,
            'success'
          );
        }
      } catch (\Exception $e) {
        $errors++;
        error_log('[AIO Events] Exception while processing email: ' . $e->getMessage());
        ActivityLogger::email_failed(
          $event->ID,
          $registration['email'] ?? 'unknown',
          $template_id,
          'Exception: ' . $e->getMessage()
        );
        // Continue to next registration - don't break the loop
        continue;
      }
    }

    return ['scheduled' => $scheduled, 'errors' => $errors];
  }

  /**
   * Get events within scheduling window
   * Returns events from 7 days ago to 7 days in future
   */
  private static function get_events_in_window()
  {
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date = date('Y-m-d', strtotime('+7 days'));

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
   * Get event datetime as timestamp
   */
  private static function get_event_datetime($event_id)
  {
    $event_date = get_post_meta($event_id, '_aio_event_start_date', true);
    $event_time = get_post_meta($event_id, '_aio_event_start_time', true);

    if (empty($event_date)) {
      return null;
    }

    $datetime_str = $event_date . ' ' . ($event_time ?: '00:00');
    return strtotime($datetime_str);
  }

  /**
   * Get email statistics for an event
   * Returns counts of sent/pending for each email type
   */
  public static function get_event_email_stats($event_id)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_registrations';

    $event_id = absint($event_id);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $stats = $wpdb->get_row($wpdb->prepare(
      "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN reminder_email_sent_at IS NOT NULL THEN 1 ELSE 0 END) as reminder_sent,
        SUM(CASE WHEN join_email_sent_at IS NOT NULL THEN 1 ELSE 0 END) as join_sent,
        SUM(CASE WHEN followup_email_sent_at IS NOT NULL THEN 1 ELSE 0 END) as followup_sent
      FROM {$table_name}
      WHERE event_id = %d",
      $event_id
    ), ARRAY_A);

    return $stats ?: [
      'total' => 0,
      'reminder_sent' => 0,
      'join_sent' => 0,
      'followup_sent' => 0,
    ];
  }

}

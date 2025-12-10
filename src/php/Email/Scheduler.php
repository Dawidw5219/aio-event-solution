<?php

namespace AIOEvents\Email;

use AIOEvents\Database\RegistrationRepository;
use AIOEvents\Logging\ActivityLogger;
use AIOEvents\Core\Config;

/**
 * Email Scheduler
 * Handles scheduling event notification emails per-registration
 * 
 * Emails are scheduled in Brevo up to 48h in advance
 */
class Scheduler
{
  /**
   * Run daily scheduler - called by cron
   * Schedules all pending emails for upcoming events (within 48h window)
   */
  public static function run_daily_schedule()
  {
    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
    require_once AIO_EVENTS_PATH . 'php/Email/EmailHelper.php';
    require_once AIO_EVENTS_PATH . 'php/Core/Config.php';

    $settings = get_option('aio_events_settings', []);

    $brevo = new BrevoClient();
    if (!$brevo->is_configured()) {
      ActivityLogger::cron('daily_schedule', 'Brevo API not configured', 0, 1);
      return false;
    }

    $scheduled_count = 0;
    $error_count = 0;
    $now = time();
    $max_schedule_time = $now + Config::get_max_schedule_seconds();

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

      $event_datetime = EmailHelper::get_event_datetime($event->ID);
      if (!$event_datetime) {
        continue;
      }

      // Calculate email send times using helper
      $reminder_send_time = EmailHelper::calculate_send_time('reminder', $event_datetime, $settings);
      $join_send_time = EmailHelper::calculate_send_time('join', $event_datetime, $settings);
      $followup_send_time = EmailHelper::calculate_send_time('followup', $event_datetime, $settings);

      // Get template IDs using helper
      $event_reminder_template = EmailHelper::get_template_id($event->ID, 'reminder', $settings);
      $event_join_template = EmailHelper::get_template_id($event->ID, 'join', $settings);
      $event_followup_template = EmailHelper::get_template_id($event->ID, 'followup', $settings);

      // Process REMINDER emails
      if ($event_reminder_template) {
        if (EmailHelper::is_in_schedule_window($reminder_send_time, $now) && $reminder_send_time < $event_datetime) {
          $result = self::schedule_email_type(
            $event,
            'reminder',
            $event_reminder_template,
            $brevo,
            $reminder_send_time,
            Config::MIN_REGISTRATION_HOURS_FOR_REMINDER
          );
          $scheduled_count += $result['scheduled'];
          $error_count += $result['errors'];
        } elseif (EmailHelper::is_in_grace_period('reminder', $reminder_send_time, $event_datetime, $now)) {
          $result = self::schedule_email_type(
            $event,
            'reminder',
            $event_reminder_template,
            $brevo,
            null,
            Config::MIN_REGISTRATION_HOURS_FOR_REMINDER
          );
          $scheduled_count += $result['scheduled'];
          $error_count += $result['errors'];
        }
      }

      // Process JOIN emails
      if ($event_join_template) {
        if (EmailHelper::is_in_schedule_window($join_send_time, $now)) {
          $result = self::schedule_email_type(
            $event,
            'join',
            $event_join_template,
            $brevo,
            $join_send_time
          );
          $scheduled_count += $result['scheduled'];
          $error_count += $result['errors'];
        } elseif (EmailHelper::is_in_grace_period('join', $join_send_time, $event_datetime, $now)) {
          $result = self::schedule_email_type(
            $event,
            'join',
            $event_join_template,
            $brevo,
            null
          );
          $scheduled_count += $result['scheduled'];
          $error_count += $result['errors'];
        }
      }

      // Process FOLLOW-UP emails
      if ($event_followup_template) {
        if (EmailHelper::is_in_schedule_window($followup_send_time, $now)) {
          $result = self::schedule_email_type(
            $event,
            'followup',
            $event_followup_template,
            $brevo,
            $followup_send_time
          );
          $scheduled_count += $result['scheduled'];
          $error_count += $result['errors'];
        } elseif (EmailHelper::is_in_grace_period('followup', $followup_send_time, $event_datetime, $now)) {
          $result = self::schedule_email_type(
            $event,
            'followup',
            $event_followup_template,
            $brevo,
            null
          );
          $scheduled_count += $result['scheduled'];
          $error_count += $result['errors'];
        }
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

    // Convert timestamp to ISO 8601 for Brevo
    $scheduled_at_iso = $scheduled_at ? EmailHelper::timestamp_to_iso8601($scheduled_at) : null;

    foreach ($registrations as $registration) {
      try {
        $recipient_email = $registration['email'] ?? '';
        $recipient_name = $registration['name'] ?? '';

        // Skip invalid registrations
        if (empty($recipient_email) || !is_email($recipient_email)) {
          $errors++;
          error_log('[AIO Events] Skipping invalid email: ' . $recipient_email);
          continue;
        }

        // Build params using helper
        $params = EmailHelper::build_email_params($event, $registration, $recipient_name);

        // Schedule/send email via Brevo
        $result = $brevo->schedule_email(
          $template_id,
          [['email' => $recipient_email, 'name' => $recipient_name]],
          $params,
          $scheduled_at_iso,
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
          $time_info = $scheduled_at ? ' for ' . gmdate('d/m/Y H:i', $scheduled_at) . ' UTC' : '';
          
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

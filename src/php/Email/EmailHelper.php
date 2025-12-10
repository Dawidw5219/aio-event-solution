<?php

namespace AIOEvents\Email;

use AIOEvents\Core\Config;

/**
 * Helper methods for email operations
 * Centralizes common email building logic used by Registration and Scheduler
 */
class EmailHelper
{
  /**
   * Build email parameters for Brevo template
   *
   * @param \WP_Post|int $event Event post object or ID
   * @param array $registration Registration data array
   * @param string $name Recipient name
   * @return array Parameters for Brevo template
   */
  public static function build_email_params($event, $registration, $name)
  {
    if (is_numeric($event)) {
      $event = get_post($event);
    }

    if (!$event) {
      return [];
    }

    $event_id = $event->ID;
    $event_date = get_post_meta($event_id, '_aio_event_start_date', true);
    $event_time = get_post_meta($event_id, '_aio_event_start_time', true);
    $timezone_string = wp_timezone_string();
    $event_time_with_tz = $event_time ? $event_time . ' (' . $timezone_string . ')' : '';

    $event_join_url = self::build_join_url($registration, $event_id);

    return [
      'event_title' => $event->post_title,
      'event_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
      'event_time' => $event_time_with_tz,
      'event_join_url' => $event_join_url,
      'attendee_name' => $name,
      'recipient_name' => $name, // alias for backwards compatibility
      'timezone' => $timezone_string,
    ];
  }

  /**
   * Build join URL for event
   *
   * @param array $registration Registration data with join_token
   * @param int $event_id Event ID (fallback for permalink)
   * @return string Join URL
   */
  public static function build_join_url($registration, $event_id)
  {
    $join_token = $registration['join_token'] ?? '';

    if (!empty($join_token)) {
      $rest_url = rest_url('aio-events/v1/join');
      return add_query_arg('token', urlencode($join_token), $rest_url);
    }

    return get_permalink($event_id);
  }

  /**
   * Get event datetime as timestamp (properly converted to UTC)
   *
   * @param int $event_id Event ID
   * @return int|null Unix timestamp or null if invalid
   */
  public static function get_event_datetime($event_id)
  {
    $event_date = get_post_meta($event_id, '_aio_event_start_date', true);
    $event_time = get_post_meta($event_id, '_aio_event_start_time', true);

    if (empty($event_date)) {
      return null;
    }

    $datetime_str = $event_date . ' ' . ($event_time ?: '00:00');
    
    return self::local_datetime_to_utc_timestamp($datetime_str);
  }

  /**
   * Convert local datetime string to UTC timestamp
   * Input is in WordPress timezone, output is Unix timestamp (always UTC)
   *
   * @param string $datetime_str Date/time string (e.g. "2025-12-10 13:05")
   * @return int|null Unix timestamp in UTC or null on error
   */
  public static function local_datetime_to_utc_timestamp($datetime_str)
  {
    if (empty($datetime_str)) {
      return null;
    }

    try {
      $wp_timezone = wp_timezone();
      $dt = new \DateTime($datetime_str, $wp_timezone);
      return $dt->getTimestamp();
    } catch (\Exception $e) {
      error_log('[AIO Events] Failed to convert datetime: ' . $datetime_str . ' - ' . $e->getMessage());
      return null;
    }
  }

  /**
   * Get template ID for email type (event-specific or global default)
   *
   * @param int $event_id Event ID
   * @param string $email_type Email type: 'registration', 'reminder', 'join', 'followup'
   * @param array|null $settings Settings array (will be fetched if null)
   * @return int Template ID or 0 if not configured
   */
  public static function get_template_id($event_id, $email_type, $settings = null)
  {
    if ($settings === null) {
      $settings = get_option('aio_events_settings', []);
    }

    // Map email types to meta keys and settings keys
    $type_map = [
      'registration' => [
        'meta' => '_aio_event_email_template_after_registration',
        'setting' => 'email_template_after_registration',
      ],
      'reminder' => [
        'meta' => '_aio_event_email_template_before_event',
        'setting' => 'email_template_before_event',
      ],
      'join' => [
        'meta' => '_aio_event_email_template_join_event',
        'setting' => 'email_template_join_event',
      ],
      'followup' => [
        'meta' => '_aio_event_email_template_after_event',
        'setting' => 'email_template_after_event',
      ],
    ];

    if (!isset($type_map[$email_type])) {
      return 0;
    }

    $map = $type_map[$email_type];

    // Check event-specific template first
    $template_id = absint(get_post_meta($event_id, $map['meta'], true));

    // Fall back to global setting
    if (empty($template_id)) {
      $template_id = absint($settings[$map['setting']] ?? 0);
    }

    return $template_id;
  }

  /**
   * Check if send time is within Brevo's scheduling window (48h)
   *
   * @param int $send_time Unix timestamp when email should be sent
   * @param int|null $now Current time (defaults to time())
   * @return bool True if within scheduling window
   */
  public static function is_in_schedule_window($send_time, $now = null)
  {
    if ($now === null) {
      $now = time();
    }

    $max_schedule_time = $now + Config::get_max_schedule_seconds();

    return $send_time > $now && $send_time <= $max_schedule_time;
  }

  /**
   * Check if email is within grace period (can still be sent immediately)
   *
   * @param string $email_type Email type: 'join' or 'followup'
   * @param int $send_time Planned send time
   * @param int $event_datetime Event start timestamp
   * @param int|null $now Current time (defaults to time())
   * @return bool True if within grace period
   */
  public static function is_in_grace_period($email_type, $send_time, $event_datetime, $now = null)
  {
    if ($now === null) {
      $now = time();
    }

    // Send time must have passed
    if ($send_time > $now) {
      return false;
    }

    switch ($email_type) {
      case 'join':
        // Join email can be sent up to 1 hour after event starts
        return $now < ($event_datetime + Config::get_join_grace_seconds());

      case 'followup':
        // Followup can be sent up to 7 days after event
        return $now < ($event_datetime + Config::get_followup_grace_seconds());

      case 'reminder':
        // Reminder can be sent until event starts
        return $now < $event_datetime;

      default:
        return false;
    }
  }

  /**
   * Get email timing from settings (in minutes)
   *
   * @param string $email_type Email type
   * @param array|null $settings Settings array
   * @return int Time in minutes
   */
  public static function get_email_timing($email_type, $settings = null)
  {
    if ($settings === null) {
      $settings = get_option('aio_events_settings', []);
    }

    switch ($email_type) {
      case 'reminder':
        return absint($settings['email_time_before_event'] ?? Config::DEFAULT_TIME_BEFORE_EVENT);
      case 'join':
        return absint($settings['email_time_join_event'] ?? Config::DEFAULT_TIME_JOIN_EVENT);
      case 'followup':
        return absint($settings['email_time_after_event'] ?? Config::DEFAULT_TIME_AFTER_EVENT);
      default:
        return 0;
    }
  }

  /**
   * Calculate send time for email type
   *
   * @param string $email_type Email type
   * @param int $event_datetime Event timestamp
   * @param array|null $settings Settings array
   * @return int Unix timestamp for send time
   */
  public static function calculate_send_time($email_type, $event_datetime, $settings = null)
  {
    $timing_minutes = self::get_email_timing($email_type, $settings);

    switch ($email_type) {
      case 'reminder':
      case 'join':
        // Before event
        return $event_datetime - ($timing_minutes * 60);
      case 'followup':
        // After event
        return $event_datetime + ($timing_minutes * 60);
      default:
        return $event_datetime;
    }
  }

  /**
   * Convert Unix timestamp to ISO 8601 format for Brevo API
   * Brevo expects UTC time with proper timezone offset
   *
   * @param int $timestamp Unix timestamp
   * @return string ISO 8601 formatted date string
   */
  public static function timestamp_to_iso8601($timestamp)
  {
    try {
      $dt = new \DateTime('@' . $timestamp);
      $dt->setTimezone(new \DateTimeZone('UTC'));
      return $dt->format('c');
    } catch (\Exception $e) {
      return gmdate('c', $timestamp);
    }
  }
}


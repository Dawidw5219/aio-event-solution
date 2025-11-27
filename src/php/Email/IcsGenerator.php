<?php

namespace AIOEvents\Email;

/**
 * ICS Calendar File Generator
 */
class IcsGenerator
{
  /**
   * Generate ICS file content for an event
   *
   * @param array $event_data Event data with keys: title, description, start_date, start_time, url, location
   * @param int $duration_minutes Event duration in minutes (default: 60)
   * @return string ICS file content
   */
  public static function generate($event_data, $duration_minutes = 60)
  {
    $title = $event_data['title'] ?? '';
    $description = $event_data['description'] ?? '';
    $start_date = $event_data['start_date'] ?? '';
    $start_time = $event_data['start_time'] ?? '00:00';
    $location = $event_data['location'] ?? '';

    if (empty($start_date)) {
      return '';
    }

    // Parse date and time
    $datetime_str = $start_date . ' ' . $start_time;
    $start_timestamp = strtotime($datetime_str);
    $end_timestamp = $start_timestamp + ($duration_minutes * 60);

    // Format dates for ICS (UTC)
    $start_utc = gmdate('Ymd\THis\Z', $start_timestamp);
    $end_utc = gmdate('Ymd\THis\Z', $end_timestamp);
    $created_utc = gmdate('Ymd\THis\Z');

    // Escape special characters
    $title = self::escape_ics_text($title);
    $description = self::escape_ics_text($description);
    $location = self::escape_ics_text($location);

    // Generate unique ID
    $uid = uniqid('aio-events-', true) . '@' . wp_parse_url(home_url(), PHP_URL_HOST);

    // Get site name for organizer
    $site_name = self::escape_ics_text(get_bloginfo('name'));
    $site_url = home_url();

    $ics_lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//AIO Events//Event Registration//EN',
      'CALSCALE:GREGORIAN',
      'METHOD:PUBLISH',
      'BEGIN:VEVENT',
      'DTSTART:' . $start_utc,
      'DTEND:' . $end_utc,
      'DTSTAMP:' . $created_utc,
      'UID:' . $uid,
      'ORGANIZER;CN=' . $site_name . ':' . $site_url,
      'SUMMARY:' . $title,
    ];

    if (!empty($description)) {
      $ics_lines[] = 'DESCRIPTION:' . $description;
    }

    if (!empty($location)) {
      $ics_lines[] = 'LOCATION:' . $location;
    }

    $ics_lines[] = 'STATUS:CONFIRMED';
    $ics_lines[] = 'SEQUENCE:0';
    $ics_lines[] = 'END:VEVENT';
    $ics_lines[] = 'END:VCALENDAR';

    return implode("\r\n", $ics_lines);
  }

  /**
   * Generate ICS for a WordPress event post
   *
   * @param int|\WP_Post $event Event post ID or object
   * @param int $duration_minutes Event duration in minutes
   * @return string ICS file content
   */
  public static function generate_for_event($event, $duration_minutes = 60)
  {
    $post = get_post($event);
    if (!$post) {
      return '';
    }

    $subtitle = get_post_meta($post->ID, '_aio_event_subtitle', true);
    $event_time = get_post_meta($post->ID, '_aio_event_start_time', true);
    $timezone_string = wp_timezone_string();

    // Build description - NO stream URL here (sent separately in email)
    $description_parts = [];
    if (!empty($subtitle)) {
      $description_parts[] = $subtitle;
    }
    if (!empty($event_time)) {
      $description_parts[] = 'Time: ' . $event_time . ' (' . $timezone_string . ')';
    }
    $description = implode("\n\n", $description_parts);

    return self::generate([
      'title' => $post->post_title,
      'description' => $description,
      'start_date' => get_post_meta($post->ID, '_aio_event_start_date', true),
      'start_time' => $event_time,
      'location' => '', // No location - join link sent via email only
    ], $duration_minutes);
  }

  /**
   * Generate base64-encoded ICS for Brevo attachment
   *
   * @param int|\WP_Post $event Event post ID or object
   * @param int $duration_minutes Event duration in minutes
   * @return array Brevo attachment array ['name' => 'event.ics', 'content' => 'base64...']
   */
  public static function generate_brevo_attachment($event, $duration_minutes = 60)
  {
    $post = get_post($event);
    if (!$post) {
      return null;
    }

    $ics_content = self::generate_for_event($event, $duration_minutes);
    if (empty($ics_content)) {
      return null;
    }

    return [
      'name' => 'event.ics',
      'content' => base64_encode($ics_content),
    ];
  }

  /**
   * Escape text for ICS format
   *
   * @param string $text Text to escape
   * @return string Escaped text
   */
  private static function escape_ics_text($text)
  {
    // Replace newlines with \n
    $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);
    // Escape commas, semicolons, and backslashes
    $text = str_replace(['\\', ',', ';'], ['\\\\', '\,', '\;'], $text);
    return $text;
  }
}


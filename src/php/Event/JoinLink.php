<?php

namespace AIOEvents\Event;

/**
 * Event Join Link Handler
 * Handles token generation, decoding, and join link creation for event registrations
 */
class JoinLink
{
  /**
   * Generate a unique token for event registration
   * Token is based on event_id, email, and random data
   * 
   * @param int $event_id Event ID
   * @param string $email User email
   * @return string Unique token
   */
  public static function generate_token($event_id, $email)
  {
    $data = [
      'event_id' => (int) $event_id,
      'email' => sanitize_email($email),
      'random' => wp_generate_password(32, false),
      'timestamp' => time(),
    ];

    $payload = json_encode($data);
    $token = base64_encode($payload);
    
    // Make it URL-safe and longer
    $token = str_replace(['+', '/', '='], ['-', '_', ''], $token);
    
    // Add more entropy
    $token .= wp_generate_password(16, false);
    
    return $token;
  }

  /**
   * Decode token to get event_id and email
   * 
   * @param string $token Token to decode
   * @return array|false Array with event_id and email, or false on failure
   */
  public static function decode_token($token)
  {
    if (empty($token) || strlen($token) < 50) {
      return false;
    }

    // Remove the extra entropy (last 16 chars)
    $encoded_part = substr($token, 0, -16);
    
    // Restore base64 characters
    $encoded_part = str_replace(['-', '_'], ['+', '/'], $encoded_part);
    
    // Add padding if needed
    $padding = strlen($encoded_part) % 4;
    if ($padding > 0) {
      $encoded_part .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($encoded_part, true);
    if ($decoded === false) {
      return false;
    }

    $data = json_decode($decoded, true);
    if (!is_array($data) || !isset($data['event_id']) || !isset($data['email'])) {
      return false;
    }

    return [
      'event_id' => (int) $data['event_id'],
      'email' => sanitize_email($data['email']),
    ];
  }

  /**
   * Verify token matches event_id and email
   * 
   * @param string $token Token to verify
   * @param int $event_id Event ID to verify against
   * @param string $email Email to verify against
   * @return bool True if token is valid
   */
  public static function verify_token($token, $event_id, $email)
  {
    $decoded = self::decode_token($token);
    if ($decoded === false) {
      return false;
    }

    return $decoded['event_id'] === (int) $event_id 
      && strtolower($decoded['email']) === strtolower($email);
  }

  /**
   * Get join link for current user's email if registered for event
   * Always uses our endpoint with token
   * 
   * @param int $event_id Event ID
   * @param string|null $email User email (if null, tries to get from current user or session)
   * @return string|null Join link or null if not registered
   */
  public static function get_join_link($event_id, $email = null)
  {
    if (empty($email)) {
      // Try to get email from current user
      $current_user = wp_get_current_user();
      if ($current_user && $current_user->ID > 0) {
        $email = $current_user->user_email;
      }
    }

    if (empty($email)) {
      return null;
    }

    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    $registration = \AIOEvents\Database\RegistrationRepository::get_by_event_and_email($event_id, $email);

    if (empty($registration) || empty($registration['join_token'])) {
      return null;
    }

    // Always use our endpoint
    $rest_url = rest_url('aio-events/v1/join');
    return add_query_arg('token', urlencode($registration['join_token']), $rest_url);
  }

  /**
   * Check if user is registered for event
   * 
   * @param int $event_id Event ID
   * @param string|null $email User email
   * @return bool
   */
  public static function is_registered($event_id, $email = null)
  {
    if (empty($email)) {
      $current_user = wp_get_current_user();
      if ($current_user && $current_user->ID > 0) {
        $email = $current_user->user_email;
      }
    }

    if (empty($email)) {
      return false;
    }

    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    return \AIOEvents\Database\RegistrationRepository::is_registered($event_id, $email);
  }
}


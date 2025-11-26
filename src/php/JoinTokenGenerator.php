<?php

namespace AIOEvents;

/**
 * Join Token Generator
 * Generates unique tokens for users to join events
 */
class JoinTokenGenerator
{
  /**
   * Generate a unique token for event registration
   * Token is based on event_id, email, and random data
   * 
   * @param int $event_id Event ID
   * @param string $email User email
   * @return string Unique token
   */
  public static function generate($event_id, $email)
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
  public static function decode($token)
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
  public static function verify($token, $event_id, $email)
  {
    $decoded = self::decode($token);
    if ($decoded === false) {
      return false;
    }

    return $decoded['event_id'] === (int) $event_id 
      && strtolower($decoded['email']) === strtolower($email);
  }
}


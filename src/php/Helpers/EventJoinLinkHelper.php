<?php

namespace AIOEvents\Helpers;

/**
 * Event Join Link Helper
 * Provides helper functions for generating join links in templates
 */
class EventJoinLinkHelper
{
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

    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';
    $registration = \AIOEvents\Repositories\RegistrationRepository::get_by_event_and_email($event_id, $email);

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

    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';
    return \AIOEvents\Repositories\RegistrationRepository::is_registered($event_id, $email);
  }
}


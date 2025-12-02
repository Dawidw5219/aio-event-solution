<?php

namespace AIOEvents\Event;

use AIOEvents\Database\RegistrationRepository;
use AIOEvents\Email\BrevoClient;

/**
 * Service handling event registration logic and Brevo synchronization
 */
class Registration
{
  /**
   * Debug info storage (for API response)
   */
  public static $debug_info = [];

  /**
   * Save registration data to database and sync with Brevo
   * 
   * @param int $event_id Event ID
   * @param string $email User email
   * @param string $name User name
   * @param string $phone User phone (optional)
   * @param array $extra_attributes Additional Brevo attributes from form
   * @param array $form_fields Form fields to store in database
   * @return array|\WP_Error Registration result
   */
  public static function register($event_id, $email, $name, $phone = '', $extra_attributes = [], $form_fields = [])
  {
    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    
    if (!RegistrationRepository::table_exists()) {
      return new \WP_Error('no_table', __('Registrations table does not exist', 'aio-event-solution'));
    }

    if (RegistrationRepository::is_registered($event_id, $email)) {
      return self::handle_existing_registration($event_id, $email, $name);
    }

    $join_token = self::generate_join_token($event_id, $email);
    $result = RegistrationRepository::create($event_id, $email, $name, $phone, false, $join_token, $form_fields);

    if (is_wp_error($result)) {
      return $result;
    }

    $insert_id = $result['insert_id'];

    self::sync_to_brevo($event_id, $email, $name, $phone, $extra_attributes, $insert_id);
    
    try {
      self::send_confirmation_email($event_id, $email, $name, $insert_id);
    } catch (\Exception $e) {
      error_log('[AIO Events] Failed to send confirmation email: ' . $e->getMessage());
    }

    return ['success' => true, 'insert_id' => $insert_id];
  }

  /**
   * Handle registration for already registered user
   */
  private static function handle_existing_registration($event_id, $email, $name)
  {
    $registration = RegistrationRepository::get_by_event_and_email($event_id, $email);
    if ($registration) {
      try {
        self::send_confirmation_email($event_id, $email, $name, $registration['id']);
      } catch (\Exception $e) {
        error_log('[AIO Events] Failed to resend confirmation email: ' . $e->getMessage());
      }
    }
    return ['success' => true, 'already_registered' => true];
  }

  /**
   * Generate join token for registration
   */
  private static function generate_join_token($event_id, $email)
  {
    require_once AIO_EVENTS_PATH . 'php/Event/JoinLink.php';
    return JoinLink::generate_token($event_id, $email);
  }

  /**
   * Send confirmation email - smart logic:
   * - If within join link window: send join link email instead
   * - Otherwise: send registration confirmation
   */
  private static function send_confirmation_email($event_id, $email, $name, $registration_id)
  {
    require_once AIO_EVENTS_PATH . 'php/Email/BrevoClient.php';
    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';

    // Prevent duplicate sends using transient (30 second window)
    $transient_key = 'aio_email_sent_' . md5($event_id . '_' . $email);
    if (get_transient($transient_key)) {
      return true;
    }
    set_transient($transient_key, true, 30);

    $brevo = new BrevoClient();
    if (!$brevo->is_configured()) {
      return false;
    }

    $event = get_post($event_id);
    if (!$event || $event->post_type !== 'aio_event') {
      return false;
    }

    $settings = get_option('aio_events_settings', []);
    $now = time();

    // Get event datetime
    $event_date = get_post_meta($event_id, '_aio_event_start_date', true);
    $event_time = get_post_meta($event_id, '_aio_event_start_time', true);
    $event_datetime = strtotime($event_date . ' ' . ($event_time ?: '00:00'));

    // Get timing settings
    $time_join_event = absint($settings['email_time_join_event'] ?? 10); // minutes
    $join_send_time = $event_datetime - ($time_join_event * 60);

    // Determine which email to send
    $is_join_window = ($now >= $join_send_time && $now < $event_datetime);
    
    if ($is_join_window) {
      // Within join window - send join link email
      $template_id = absint(get_post_meta($event_id, '_aio_event_email_template_join_event', true)) 
        ?: absint($settings['email_template_join_event'] ?? 0);
      $email_type = 'join';
    } else {
      // Normal registration - send confirmation email
      $template_id = absint(get_post_meta($event_id, '_aio_event_email_template_after_registration', true)) 
        ?: absint($settings['email_template_after_registration'] ?? 0);
      $email_type = 'registration';
    }

    if (empty($template_id)) {
      return false;
    }

    // Get registration for join token
    $registration = RegistrationRepository::get_by_id($registration_id);
    
    // Generate join link
    $event_join_url = '';
    if (!empty($registration['join_token'])) {
      $rest_url = rest_url('aio-events/v1/join');
      $event_join_url = add_query_arg('token', urlencode($registration['join_token']), $rest_url);
    } else {
      $event_join_url = get_permalink($event->ID);
    }

    $timezone_string = wp_timezone_string();
    $event_time_with_tz = $event_time ? $event_time . ' (' . $timezone_string . ')' : '';

    $params = [
      'event_title' => $event->post_title,
      'event_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
      'event_time' => $event_time_with_tz,
      'event_join_url' => $event_join_url,
      'attendee_name' => $name,
      'recipient_name' => $name,
      'timezone' => $timezone_string,
    ];

    $options = [
      'tags' => ['event-' . $email_type, 'event-' . $event->ID],
    ];

    // Add .ics attachment for registration emails
    if ($email_type === 'registration') {
      require_once AIO_EVENTS_PATH . 'php/Email/IcsGenerator.php';
      $ics_attachment = \AIOEvents\Email\IcsGenerator::generate_brevo_attachment($event_id);
      if ($ics_attachment) {
        $options['attachment'] = [$ics_attachment];
      }
    }

    $result = $brevo->schedule_email(
      $template_id,
      [['email' => $email, 'name' => $name]],
      $params,
      null, // Send immediately
      $options
    );

    if (is_wp_error($result)) {
      require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
      \AIOEvents\Logging\ActivityLogger::email_failed($event_id, $email, $template_id, $result->get_error_message());
      return false;
    }

    // Mark email as sent
    RegistrationRepository::mark_email_sent($registration_id, $email_type);

    // Log success
    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
    \AIOEvents\Logging\ActivityLogger::log(
      'email_sent',
      'instant_' . $email_type,
      sprintf('Sent %s email to %s', $email_type, $email),
      ['template_id' => $template_id, 'is_join_window' => $is_join_window],
      $event_id,
      $email,
      'success'
    );

    return true;
  }

  /**
   * Sync registration to Brevo
   */
  private static function sync_to_brevo($event_id, $email, $name, $phone, $extra_attributes, $insert_id)
  {
    require_once AIO_EVENTS_PATH . 'php/Email/BrevoClient.php';
    
    $brevo = new BrevoClient();
    if (!$brevo->is_configured()) {
      self::$debug_info['brevo_error'] = 'API not configured';
      return;
    }

    $list_id = self::get_brevo_list_id($event_id);
    $attributes = self::build_brevo_attributes($brevo, $email, $name, $phone, $extra_attributes, $event_id);

    self::$debug_info['brevo_list_id'] = $list_id;
    self::$debug_info['brevo_attributes_final'] = $attributes;
    self::$debug_info['extra_attributes_received'] = $extra_attributes;

    $result = !empty($list_id)
      ? $brevo->add_contact_to_list($email, $list_id, $attributes)
      : $brevo->create_contact($email, $attributes);

    self::$debug_info['brevo_result'] = is_wp_error($result) ? $result->get_error_message() : 'SUCCESS';

    if (!is_wp_error($result)) {
      RegistrationRepository::update($insert_id, ['brevo_added' => true]);
    }
  }

  /**
   * Get Brevo list ID for event (event-specific or global default)
   */
  private static function get_brevo_list_id($event_id)
  {
    $settings = get_option('aio_events_settings', []);
    $global_list_id = $settings['default_brevo_list_id'] ?? '';
    
    $event_list_id = get_post_meta($event_id, '_aio_event_brevo_list_id', true);
    
    if (!empty($event_list_id)) {
      return $event_list_id;
    }

    // Migration: check old meta key
    $old_list_ids = get_post_meta($event_id, '_aio_event_brevo_list_ids', true);
    if (is_array($old_list_ids) && !empty($old_list_ids)) {
      return absint($old_list_ids[0]);
    }

    return $global_list_id;
  }

  /**
   * Build Brevo attributes array with merged existing data
   */
  private static function build_brevo_attributes($brevo, $email, $name, $phone, $extra_attributes, $event_id)
  {
    $existing_contact = $brevo->get_contact($email);
    $existing_attributes = (!is_wp_error($existing_contact) && $existing_contact !== null)
      ? ($existing_contact['attributes'] ?? [])
      : [];

    $attributes = is_array($extra_attributes) ? $extra_attributes : [];

    // Parse name
    $name_parts = explode(' ', $name, 2);
    if (!empty($name_parts[0])) {
      $attributes['FIRSTNAME'] = $name_parts[0];
    }
    if (!empty($name_parts[1])) {
      $attributes['LASTNAME'] = $name_parts[1];
    }
    
    if (!empty($phone)) {
      $attributes['SMS'] = $phone;
    }

    // Accumulate EVENTS (never overwrite)
    $attributes['EVENTS'] = self::accumulate_events_attribute(
      $existing_attributes['EVENTS'] ?? '',
      $event_id
    );

    // Accumulate EVENT_CATEGORY (never overwrite, unique slugs only)
    $event_category_slugs = self::get_event_category_slugs($event_id);
    error_log('[AIO Events] Event categories for event ' . $event_id . ': ' . wp_json_encode($event_category_slugs));
    
    $event_categories_result = self::accumulate_event_categories_attribute(
      $existing_attributes['EVENT_CATEGORY'] ?? '',
      $event_category_slugs
    );
    
    error_log('[AIO Events] EVENT_CATEGORY result: ' . $event_categories_result);
    
    if (!empty($event_categories_result)) {
      $attributes['EVENT_CATEGORY'] = $event_categories_result;
    }

    return $attributes;
  }

  /**
   * Append event ID to EVENTS attribute string
   * Handles 40,000 character limit by removing oldest IDs if needed
   */
  private static function accumulate_events_attribute($existing_events, $event_id)
  {
    $event_ids = [];
    
    if (!empty($existing_events)) {
      $event_ids = array_map('trim', explode(',', $existing_events));
      $event_ids = array_filter($event_ids);
      $event_ids = array_values($event_ids);
    }
    
    $event_id_str = (string) $event_id;
    if (!in_array($event_id_str, $event_ids, true)) {
      $event_ids[] = $event_id_str;
    }
    
    $result = implode(', ', $event_ids);
    $max_length = 40000;
    
    while (strlen($result) > $max_length && count($event_ids) > 1) {
      array_shift($event_ids);
      $result = implode(', ', $event_ids);
    }
    
    return $result;
  }

  /**
   * Get category slugs for event
   */
  private static function get_event_category_slugs($event_id)
  {
    $terms = wp_get_post_terms($event_id, 'aio_event_category', ['fields' => 'all']);
    
    if (is_wp_error($terms) || empty($terms)) {
      return [];
    }
    
    return array_map(function($term) {
      return $term->slug;
    }, $terms);
  }

  /**
   * Append event category slugs to EVENT_CATEGORY attribute string
   * Handles 40,000 character limit by removing oldest slugs if needed
   * Ensures no duplicates (set behavior)
   */
  private static function accumulate_event_categories_attribute($existing_categories, $new_category_slugs)
  {
    $category_slugs = [];
    
    if (!empty($existing_categories)) {
      $category_slugs = array_map('trim', explode(',', $existing_categories));
      $category_slugs = array_filter($category_slugs);
      $category_slugs = array_values($category_slugs);
    }
    
    foreach ($new_category_slugs as $slug) {
      $slug = trim($slug);
      if (!empty($slug) && !in_array($slug, $category_slugs, true)) {
        $category_slugs[] = $slug;
      }
    }
    
    if (empty($category_slugs)) {
      return '';
    }
    
    $result = implode(', ', $category_slugs);
    $max_length = 40000;
    
    while (strlen($result) > $max_length && count($category_slugs) > 1) {
      array_shift($category_slugs);
      $result = implode(', ', $category_slugs);
    }
    
    return $result;
  }
}

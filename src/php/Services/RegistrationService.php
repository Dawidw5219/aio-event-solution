<?php

namespace AIOEvents\Services;

use AIOEvents\Repositories\RegistrationRepository;
use AIOEvents\Integrations\BrevoAPI;
use AIOEvents\Scheduler\EmailScheduler;

/**
 * Service handling event registration logic and Brevo synchronization
 */
class RegistrationService
{
  /**
   * Save registration data to database and sync with Brevo
   * 
   * @param int $event_id Event ID
   * @param string $email User email
   * @param string $name User name
   * @param string $phone User phone (optional)
   * @param array $extra_attributes Additional Brevo attributes from form
   * @return array|\WP_Error Registration result
   */
  public static function register($event_id, $email, $name, $phone = '', $extra_attributes = [])
  {
    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';
    
    if (!RegistrationRepository::table_exists()) {
      return new \WP_Error('no_table', __('Registrations table does not exist', 'aio-event-solution'));
    }

    if (RegistrationRepository::is_registered($event_id, $email)) {
      return self::handle_existing_registration($event_id, $email, $name);
    }

    $join_token = self::generate_join_token($event_id, $email);
    $result = RegistrationRepository::create($event_id, $email, $name, $phone, false, $join_token);

    if (is_wp_error($result)) {
      return $result;
    }

    $insert_id = $result['insert_id'];

    self::sync_to_brevo($event_id, $email, $name, $phone, $extra_attributes, $insert_id);
    self::send_confirmation($event_id, $email, $name);

    return ['success' => true, 'insert_id' => $insert_id];
  }

  /**
   * Handle registration for already registered user
   */
  private static function handle_existing_registration($event_id, $email, $name)
  {
    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    EmailScheduler::send_registration_email($event_id, $email, $name);
    return ['success' => true, 'already_registered' => true];
  }

  /**
   * Generate join token for registration
   */
  private static function generate_join_token($event_id, $email)
  {
    require_once AIO_EVENTS_PATH . 'php/JoinTokenGenerator.php';
    return \AIOEvents\JoinTokenGenerator::generate($event_id, $email);
  }

  /**
   * Debug info storage (for API response)
   */
  public static $debug_info = [];

  /**
   * Sync registration to Brevo
   */
  private static function sync_to_brevo($event_id, $email, $name, $phone, $extra_attributes, $insert_id)
  {
    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    
    $brevo = new BrevoAPI();
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

    return $attributes;
  }

  /**
   * Append event ID to EVENTS attribute string
   */
  private static function accumulate_events_attribute($existing_events, $event_id)
  {
    $event_ids = [];
    
    if (!empty($existing_events)) {
      $event_ids = array_map('trim', explode(',', $existing_events));
      $event_ids = array_filter($event_ids);
    }
    
    $event_id_str = (string) $event_id;
    if (!in_array($event_id_str, $event_ids, true)) {
      $event_ids[] = $event_id_str;
    }
    
    return implode(',', $event_ids);
  }

  /**
   * Send confirmation email
   */
  private static function send_confirmation($event_id, $email, $name)
  {
    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    EmailScheduler::send_registration_email($event_id, $email, $name);
  }
}


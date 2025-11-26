<?php

namespace AIOEvents\API;

/**
 * Brevo Webhook Controller
 * Handles registration form submissions as proxy to Brevo
 */
class BrevoWebhookController
{
  /**
   * Register REST API routes
   */
  public static function register()
  {
    add_action('rest_api_init', [self::class, 'register_routes']);
  }

  /**
   * Register REST API routes
   */
  public static function register_routes()
  {
    register_rest_route('aio-events/v1', '/register', [
      'methods' => 'POST',
      'callback' => [self::class, 'handle_request'],
      'permission_callback' => [self::class, 'verify_request'],
    ]);
  }

  /**
   * Verify request (simple token check)
   */
  public static function verify_request($request)
  {
    $token = $request->get_header('X-Brevo-Webhook-Token');
    $settings = get_option('aio_events_settings', []);
    $webhook_token = $settings['brevo_webhook_token'] ?? '';

    // If token is not set, allow requests (for development)
    if (empty($webhook_token)) {
      return true;
    }

    return !empty($token) && hash_equals($webhook_token, $token);
  }

  /**
   * Handle Brevo webhook - acts as proxy to Brevo, then saves registration
   * Handles both JSON (from our JS) and form-data (direct form submission)
   */
  public static function handle_request($request)
  {
    // Check if request is JSON or form-data
    $content_type = $request->get_header('Content-Type') ?? '';
    $is_json = strpos($content_type, 'application/json') !== false;
    
    if ($is_json) {
      // JSON request from our JavaScript
    $data = $request->get_json_params();
    $form_data = $data['form_data'] ?? [];
      $event_id = absint($data['event_id'] ?? $request->get_param('event_id') ?? 0);
    } else {
      // Form-data request (direct form submission)
      $form_data = $request->get_body_params();
      $event_id = absint($request->get_param('event_id') ?? 0);
    }

    // Get event_id from query param if not in body
    if (empty($event_id)) {
    $event_id = absint($request->get_param('event_id') ?? 0);
    }
    
    if (empty($event_id)) {
      return new \WP_Error('invalid_data', __('Missing event_id', 'aio-event-solution'), ['status' => 400]);
    }

    // Get Brevo form action URL from event meta
    $brevo_form_embed = get_post_meta($event_id, '_aio_event_brevo_form_embed', true);
    if (empty($brevo_form_embed)) {
      $settings = get_option('aio_events_settings', []);
      $brevo_form_embed = $settings['global_brevo_form_html'] ?? '';
    }

    if (empty($brevo_form_embed)) {
      return new \WP_Error('no_form', __('Brevo form has not been configured', 'aio-event-solution'), ['status' => 400]);
    }

    // Extract user data FIRST to check if already registered
    // Normalize form_data keys to lowercase for case-insensitive lookup
    // Trim all values first to avoid errors from trailing/leading spaces
    $form_data_lower = [];
    foreach ($form_data as $key => $value) {
      $trimmed_value = is_string($value) ? trim($value) : $value;
      $form_data_lower[strtolower($key)] = $trimmed_value;
    }

    // Find email (case-insensitive) - already trimmed
    $email = '';
    foreach (['email', 'e-mail', 'mail'] as $key) {
      if (!empty($form_data_lower[$key]) && is_email($form_data_lower[$key])) {
        $email = $form_data_lower[$key];
        break;
      }
    }

    if (empty($email) || !is_email($email)) {
      return new \WP_Error('invalid_email', __('Invalid email address', 'aio-event-solution'), ['status' => 400]);
    }

    // Check if already registered BEFORE sending to Brevo
    require_once AIO_EVENTS_PATH . 'php/Repositories/RegistrationRepository.php';
    $is_already_registered = \AIOEvents\Repositories\RegistrationRepository::is_registered($event_id, $email);

    // If already registered, skip Brevo submission and database save
    // Only resend email
    if ($is_already_registered) {
      // Find name for email
      $first_name = '';
      $last_name = '';
      
      foreach (['firstname', 'first_name', 'fname', 'name'] as $key) {
        if (!empty($form_data_lower[$key])) {
          $first_name = $form_data_lower[$key];
          break;
        }
      }
      
      foreach (['lastname', 'last_name', 'lname', 'surname'] as $key) {
        if (!empty($form_data_lower[$key])) {
          $last_name = $form_data_lower[$key];
          break;
        }
      }
      
      $name = trim($first_name . ' ' . $last_name);
      if (empty($name)) {
        $name = $email;
      }
      
      require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
      \AIOEvents\Scheduler\EmailScheduler::send_registration_email($event_id, $email, $name);
      
      // If form-data submission (direct form submit), return HTML redirect instead of JSON
      if (!$is_json) {
        $event_permalink = get_permalink($event_id);
        wp_redirect(add_query_arg('registered', '1', $event_permalink));
        exit;
      }
      
      return new \WP_REST_Response([
        'success' => true,
        'already_registered' => true,
        'message' => __('Email has been resent', 'aio-event-solution'),
        'email' => $email,
      ], 200);
    }

    // Find first name (case-insensitive) - already trimmed
    $first_name = '';
    foreach (['firstname', 'first_name', 'fname', 'name'] as $key) {
      if (!empty($form_data_lower[$key])) {
        $first_name = $form_data_lower[$key];
        break;
      }
    }

    // Find last name (case-insensitive) - already trimmed
    $last_name = '';
    foreach (['lastname', 'last_name', 'lname', 'surname'] as $key) {
      if (!empty($form_data_lower[$key])) {
        $last_name = $form_data_lower[$key];
        break;
      }
    }

    // Reconstruct full name
    $name = trim($first_name . ' ' . $last_name);
    if (empty($name)) {
      $name = $email;
    }

    // Build Brevo attributes from form data
    // Map form fields to Brevo attribute names (uppercase)
    $brevo_attributes = [];
    foreach ($form_data as $key => $value) {
      // Skip internal fields
      if (in_array(strtolower($key), ['email', 'e-mail', 'mail', 'email_address_check', 'locale'], true)) {
        continue;
      }
      
      // Convert key to uppercase for Brevo (remove [] suffix)
      $brevo_key = strtoupper(str_replace(['[]', '-'], ['', '_'], $key));
      
      // Handle arrays (checkboxes) - join with comma
      if (is_array($value)) {
        $filtered = array_filter(array_map('trim', $value));
        if (!empty($filtered)) {
          $brevo_attributes[$brevo_key] = implode(', ', $filtered);
        }
      } else {
        $trimmed = is_string($value) ? trim($value) : $value;
        if (!empty($trimmed)) {
          // If key ends with [] it's a single-value array field - still add it
          $brevo_attributes[$brevo_key] = $trimmed;
        }
      }
    }
    
    error_log('[AIO Events] Brevo attributes built: ' . wp_json_encode($brevo_attributes));

    // Save registration to database and add to Brevo list via API
    require_once AIO_EVENTS_PATH . 'php/Services/RegistrationService.php';
    $result = \AIOEvents\Services\RegistrationService::register($event_id, $email, $name, '', $brevo_attributes);

    if (is_wp_error($result)) {
      return new \WP_Error('db_error', $result->get_error_message(), ['status' => 500]);
    }

    // Return success response
    $message = __('Registration completed successfully!', 'aio-event-solution');

    // If form-data submission (direct form submit), return HTML redirect instead of JSON
    if (!$is_json) {
      // Redirect back to event page with success parameter
      $event_permalink = get_permalink($event_id);
      wp_redirect(add_query_arg('registered', '1', $event_permalink));
      exit;
    }

    return new \WP_REST_Response([
      'success' => true,
      'message' => $message,
      'email' => $email,
    ], 200);
  }
}

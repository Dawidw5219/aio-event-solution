<?php

namespace AIOEvents\API;

/**
 * Join Event Controller
 * Handles joining events via unique token links
 */
class JoinEventController
{
  /**
   * Register REST API routes
   */
  public static function register()
  {
    add_action('rest_api_init', [self::class, 'register_routes']);
    // Also intercept early to handle redirects before WordPress auth kicks in
    add_action('template_redirect', [self::class, 'handle_early_redirect'], 1);
  }

  /**
   * Handle early redirect for join links (before WordPress auth)
   */
  public static function handle_early_redirect()
  {
    // Check if this is our join endpoint (REST API or direct)
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_rest_api_join = strpos($request_uri, '/wp-json/aio-events/v1/join') !== false;
    $has_token_param = isset($_GET['token']);
    
    if (!$is_rest_api_join && !$has_token_param) {
      return;
    }

    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    
    if (empty($token)) {
      return;
    }

    // Use the same logic as REST API handler
    require_once AIO_EVENTS_PATH . 'php/JoinTokenGenerator.php';
    $decoded = \AIOEvents\JoinTokenGenerator::decode($token);

    if ($decoded === false) {
      wp_die(__('Invalid join token', 'aio-event-solution'), __('Error', 'aio-event-solution'), ['response' => 400]);
      return;
    }

    $event_id = $decoded['event_id'];
    $email = $decoded['email'];

    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    
    if (!\AIOEvents\Database\RegistrationRepository::table_exists()) {
      wp_die(__('Registrations table does not exist', 'aio-event-solution'), __('Error', 'aio-event-solution'), ['response' => 500]);
      return;
    }

    $registration = \AIOEvents\Database\RegistrationRepository::get_by_token($token);

    if (empty($registration)) {
      wp_die(__('Registration not found', 'aio-event-solution'), __('Error', 'aio-event-solution'), ['response' => 404]);
      return;
    }

    if ($registration['event_id'] != $event_id || strtolower($registration['email']) !== strtolower($email)) {
      wp_die(__('Token does not match registration', 'aio-event-solution'), __('Error', 'aio-event-solution'), ['response' => 400]);
      return;
    }

    $event = get_post($event_id);
    if (!$event || $event->post_type !== 'aio_event') {
      wp_die(__('Event not found', 'aio-event-solution'), __('Error', 'aio-event-solution'), ['response' => 404]);
      return;
    }

    // Mark that user clicked join link
    \AIOEvents\Database\RegistrationRepository::update($registration['id'], [
      'clicked_join_link' => true,
    ]);

    $stream_url = get_post_meta($event_id, '_aio_event_stream_url', true);
    
    if (empty($stream_url)) {
      $stream_url = get_permalink($event_id);
    }
    
    wp_redirect($stream_url, 302);
    exit;
  }

  /**
   * Register REST API routes
   */
  public static function register_routes()
  {
    register_rest_route('aio-events/v1', '/join', [
      'methods' => 'GET',
      'callback' => [self::class, 'handle_join'],
      'permission_callback' => '__return_true',
      'args' => [
        'token' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
      ],
    ]);
  }

  /**
   * Handle join request
   */
  public static function handle_join($request)
  {
    $token = $request->get_param('token');
    
    if (empty($token)) {
      return new \WP_Error('missing_token', __('Missing join token', 'aio-event-solution'), ['status' => 400]);
    }

    require_once AIO_EVENTS_PATH . 'php/JoinTokenGenerator.php';
    $decoded = \AIOEvents\JoinTokenGenerator::decode($token);

    if ($decoded === false) {
      return new \WP_Error('invalid_token', __('Invalid join token', 'aio-event-solution'), ['status' => 400]);
    }

    $event_id = $decoded['event_id'];
    $email = $decoded['email'];

    // Verify token matches registration in database
    require_once AIO_EVENTS_PATH . 'php/Database/RegistrationRepository.php';
    
    if (!\AIOEvents\Database\RegistrationRepository::table_exists()) {
      return new \WP_Error('no_table', __('Registrations table does not exist', 'aio-event-solution'), ['status' => 500]);
    }

    $registration = \AIOEvents\Database\RegistrationRepository::get_by_token($token);

    if (empty($registration)) {
      return new \WP_Error('registration_not_found', __('Registration not found', 'aio-event-solution'), ['status' => 404]);
    }

    // Verify token matches event_id and email from decoded data
    if ($registration['event_id'] != $event_id || strtolower($registration['email']) !== strtolower($email)) {
      return new \WP_Error('token_mismatch', __('Token does not match registration', 'aio-event-solution'), ['status' => 400]);
    }

    // Get event
    $event = get_post($event_id);
    if (!$event || $event->post_type !== 'aio_event') {
      return new \WP_Error('event_not_found', __('Event not found', 'aio-event-solution'), ['status' => 404]);
    }

    // Mark that user clicked join link
    \AIOEvents\Database\RegistrationRepository::update($registration['id'], [
      'clicked_join_link' => true,
    ]);

    // Get stream URL from event meta
    $stream_url = get_post_meta($event_id, '_aio_event_stream_url', true);
    
    // If no stream URL, redirect to event page
    if (empty($stream_url)) {
      $stream_url = get_permalink($event_id);
    }
    
    // If redirect is requested via AJAX or API, return JSON
    if (wp_is_json_request() || $request->get_param('format') === 'json') {
      return new \WP_REST_Response([
        'success' => true,
        'message' => __('Successfully joined the event', 'aio-event-solution'),
        'event_id' => $event_id,
        'stream_url' => $stream_url,
        'event_title' => $event->post_title,
      ], 200);
    }

    // Redirect to stream URL
    wp_safe_redirect($stream_url);
    exit;
  }
}


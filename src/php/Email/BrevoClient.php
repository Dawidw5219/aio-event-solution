<?php

namespace AIOEvents\Email;

/**
 * Brevo API Client
 */
class BrevoClient
{
  private $api_key;
  private $api_url = 'https://api.brevo.com/v3';

  /**
   * Constructor
   */
  public function __construct($api_key = null)
  {
    if ($api_key) {
      $this->api_key = $api_key;
    } else {
      $settings = get_option('aio_events_settings', []);
      $this->api_key = $settings['brevo_api_key'] ?? '';
    }
  }

  /**
   * Check if API is configured
   */
  public function is_configured()
  {
    return !empty($this->api_key);
  }

  /**
   * Get contact by email
   * 
   * @param string $email Contact email
   * @return array|\WP_Error Contact data or error
   */
  public function get_contact($email)
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    $email = urlencode($email);
    $response = wp_remote_get($this->api_url . '/contacts/' . $email, [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
      ],
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // 404 = contact not found (not an error, just doesn't exist yet)
    if ($response_code === 404) {
      return null;
    }

    if ($response_code !== 200) {
      return new \WP_Error(
        'brevo_api_error',
        $data['message'] ?? __('Failed to fetch contact from Brevo', 'aio-event-solution')
      );
    }

    return $data;
  }

  /**
   * Get all contact lists
   */
  public function get_lists()
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    $response = wp_remote_get($this->api_url . '/contacts/lists', [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
      ],
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (wp_remote_retrieve_response_code($response) !== 200) {
      return new \WP_Error(
        'brevo_api_error',
        $data['message'] ?? __('Failed to fetch lists from Brevo', 'aio-event-solution')
      );
    }

    return $data['lists'] ?? [];
  }

  /**
   * Add contact to list
   */
  public function add_contact_to_list($email, $list_id, $attributes = [])
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    $body = [
      'email' => $email,
      'listIds' => [(int) $list_id],
      'updateEnabled' => true,
    ];

    if (!empty($attributes)) {
      $body['attributes'] = $attributes;
    }

    error_log('[AIO Events] Brevo API Request body: ' . wp_json_encode($body));

    $response = wp_remote_post($this->api_url . '/contacts', [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($body),
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      error_log('[AIO Events] Brevo API WP Error: ' . $response->get_error_message());
      return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    error_log('[AIO Events] Brevo API Response: code=' . $response_code . ', body=' . $response_body);

    // 201 = created, 204 = updated
    if (!in_array($response_code, [201, 204])) {
      return new \WP_Error(
        'brevo_api_error',
        $data['message'] ?? __('Failed to add contact to Brevo list', 'aio-event-solution')
      );
    }

    return true;
  }

  /**
   * Create a new contact (without list assignment)
   */
  public function create_contact($email, $attributes = [])
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    $body = [
      'email' => $email,
      'updateEnabled' => true,
    ];

    if (!empty($attributes)) {
      $body['attributes'] = $attributes;
    }

    $response = wp_remote_post($this->api_url . '/contacts', [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($body),
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if (!in_array($response_code, [201, 204])) {
      return new \WP_Error(
        'brevo_api_error',
        $data['message'] ?? __('Failed to create contact in Brevo', 'aio-event-solution')
      );
    }

    return $data['id'] ?? true;
  }

  /**
   * Test API connection
   */
  public function test_connection()
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    $response = wp_remote_get($this->api_url . '/account', [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
      ],
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
      return new \WP_Error('brevo_connection_failed', __('Failed to connect to Brevo API', 'aio-event-solution'));
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return $data;
  }

  /**
   * Send custom event to Brevo (/v3/events)
   *
   * @param string $event_name
   * @param string $email Email identifier for the contact
   * @param array $properties Arbitrary event_properties
   * @param array $identifiers Additional identifiers (e.g., ext_id)
   * @return true|\WP_Error
   */
  public function send_event($event_name, $email, $properties = [], $identifiers = [], $contact_properties = [])
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    if (empty($event_name) || empty($email)) {
      return new \WP_Error('invalid_event', __('Event name and email are required', 'aio-event-solution'));
    }

    $body = [
      'event_name' => $event_name,
      'identifiers' => array_merge([
        'email_id' => $email,
      ], is_array($identifiers) ? $identifiers : []),
      'event_properties' => is_array($properties) ? $properties : [],
    ];
    if (!empty($contact_properties) && is_array($contact_properties)) {
      $body['contact_properties'] = $contact_properties;
    }

    $response = wp_remote_post($this->api_url . '/events', [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'body' => wp_json_encode($body),
      'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $data = json_decode($resp_body, true);
    if ($code < 200 || $code >= 300) {
      return new \WP_Error('brevo_event_failed', $data['message'] ?? __('Failed to send Brevo event', 'aio-event-solution'));
    }

    return [
      'code' => $code,
      'body' => $data,
    ];
  }

  /**
   * Schedule transactional email via Brevo Transactional Email API
   *
   * @param int $template_id Brevo template ID
   * @param array $recipients Array of recipients [['email' => 'user@example.com', 'name' => 'User Name']]
   * @param array $params Template parameters for personalization
   * @param string $scheduled_at ISO 8601 datetime (max 3 days in future)
   * @param array $options Additional options (tags, attachment, etc.)
   * @return array|\WP_Error Response with message_id or error
   */
  public function schedule_email($template_id, $recipients, $params = [], $scheduled_at = null, $options = [])
  {
    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
    
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    if (empty($template_id) || empty($recipients)) {
      return new \WP_Error('invalid_params', __('Template ID and recipients are required', 'aio-event-solution'));
    }

    $body = [
      'templateId' => (int) $template_id,
      'to' => $recipients,
    ];

    if (!empty($params)) {
      $body['params'] = $params;
    }

    if (!empty($scheduled_at)) {
      $body['scheduledAt'] = $scheduled_at;
    }

    if (!empty($options['tags']) && is_array($options['tags'])) {
      $body['tags'] = $options['tags'];
    }

    // Add attachments (for .ics calendar files, etc.)
    if (!empty($options['attachment']) && is_array($options['attachment'])) {
      $body['attachment'] = $options['attachment'];
    }

    // Get event_id from tags for logging
    $event_id = null;
    if (!empty($options['tags'])) {
      foreach ($options['tags'] as $tag) {
        if (strpos($tag, 'event-') === 0 && $tag !== 'event-notification' && $tag !== 'event-registration') {
          $event_id = absint(str_replace('event-', '', $tag));
          break;
        }
      }
    }
    
    // Get recipient email for logging
    $recipient_email = $recipients[0]['email'] ?? null;

    // Log API request
    \AIOEvents\Logging\ActivityLogger::api_request(
      '/smtp/email',
      'POST',
      [
        'templateId' => $template_id,
        'to' => $recipients,
        'params' => $params,
        'scheduledAt' => $scheduled_at,
      ],
      $event_id
    );

    $response = wp_remote_post($this->api_url . '/smtp/email', [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'body' => wp_json_encode($body),
      'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
      // Log error
      \AIOEvents\Logging\ActivityLogger::email_failed(
        $event_id,
        $recipient_email,
        $template_id,
        $response->get_error_message(),
        $body
      );
      return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $data = json_decode($resp_body, true);

    // Log API response
    \AIOEvents\Logging\ActivityLogger::api_response('/smtp/email', $code, $data, $event_id);

    if ($code < 200 || $code >= 300) {
      // Log failure
      \AIOEvents\Logging\ActivityLogger::email_failed(
        $event_id,
        $recipient_email,
        $template_id,
        $data['message'] ?? 'HTTP ' . $code,
        $body
      );
      return new \WP_Error(
        'brevo_schedule_failed',
        $data['message'] ?? __('Failed to schedule email in Brevo', 'aio-event-solution')
      );
    }

    // Log success
    if ($scheduled_at) {
      \AIOEvents\Logging\ActivityLogger::email_scheduled(
        $event_id,
        $recipient_email,
        $template_id,
        $scheduled_at,
        $data
      );
    } else {
      \AIOEvents\Logging\ActivityLogger::email_sent(
        $event_id,
        $recipient_email,
        $template_id,
        $data
      );
    }

    return [
      'code' => $code,
      'message_id' => $data['messageId'] ?? null,
      'body' => $data,
    ];
  }

  /**
   * Get contacts from a specific list
   *
   * @param int $list_id Brevo list ID
   * @return array|\WP_Error Array of contacts or error
   */
  public function get_contacts_from_list($list_id)
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    $url = $this->api_url . '/contacts/lists/' . absint($list_id) . '/contacts?limit=500';
    $all_contacts = [];
    $offset = 0;

    do {
      $response = wp_remote_get($url . '&offset=' . $offset, [
        'headers' => [
          'api-key' => $this->api_key,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 15,
      ]);

      if (is_wp_error($response)) {
        return $response;
      }

      $code = wp_remote_retrieve_response_code($response);
      $body = wp_remote_retrieve_body($response);
      $data = json_decode($body, true);

      if ($code !== 200) {
        return new \WP_Error('brevo_api_error', $data['message'] ?? __('Failed to fetch contacts', 'aio-event-solution'));
      }

      $contacts = $data['contacts'] ?? [];
      $all_contacts = array_merge($all_contacts, $contacts);
      $offset += 500;
    } while (count($contacts) === 500);

    return $all_contacts;
  }

  /**
   * Get recipients from multiple lists (deduplicated)
   *
   * @param array $list_ids Array of Brevo list IDs
   * @return array Array of unique recipients [['email' => '', 'name' => '']]
   */
  public function get_recipients_from_lists(array $list_ids)
  {
    $all_contacts = [];

    foreach ($list_ids as $list_id) {
      $contacts = $this->get_contacts_from_list($list_id);
      if (!is_wp_error($contacts) && is_array($contacts)) {
        $all_contacts = array_merge($all_contacts, $contacts);
      }
    }

    $unique = [];
    $seen = [];

    foreach ($all_contacts as $contact) {
      $email = $contact['email'] ?? '';
      if (empty($email) || isset($seen[$email])) {
        continue;
      }
      $seen[$email] = true;

      $name_parts = array_filter([
        $contact['attributes']['FIRSTNAME'] ?? '',
        $contact['attributes']['LASTNAME'] ?? '',
      ]);

      $unique[] = [
        'email' => $email,
        'name' => implode(' ', $name_parts),
      ];
    }

    return $unique;
  }

  /**
   * Get list of transactional email templates
   *
   * @return array|\WP_Error Array of templates or error
   */
  public function get_email_templates()
  {
    if (!$this->is_configured()) {
      return new \WP_Error('no_api_key', __('Brevo API key not configured', 'aio-event-solution'));
    }

    $response = wp_remote_get($this->api_url . '/smtp/templates', [
      'headers' => [
        'api-key' => $this->api_key,
        'Content-Type' => 'application/json',
      ],
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (wp_remote_retrieve_response_code($response) !== 200) {
      return new \WP_Error(
        'brevo_api_error',
        $data['message'] ?? __('Failed to fetch email templates from Brevo', 'aio-event-solution')
      );
    }

    $templates = $data['templates'] ?? [];
    
    // Log for debugging
    if (empty($templates)) {
      error_log('[AIO Events] Brevo API returned empty templates array. Response: ' . wp_json_encode($data));
      return [];
    }
    
    // Filter only active templates (if status field exists)
    // Brevo may return templates with 'status' field or without it
    $active_templates = array_filter($templates, function($template) {
      // If status field exists, filter only 'active' ones
      // If status field doesn't exist, include all templates (backward compatibility)
      if (isset($template['status'])) {
        // Check for various possible status values
        $status = strtolower($template['status']);
        return in_array($status, ['active', 'published', 'enabled'], true);
      }
      // If no status field, assume template is active (include it)
      return true;
    });
    
    // Log if all templates were filtered out
    if (empty($active_templates) && !empty($templates)) {
      error_log('[AIO Events] All templates filtered out. Total templates: ' . count($templates) . ', Sample template structure: ' . wp_json_encode($templates[0] ?? []));
      // Return all templates as fallback if filtering removed everything
      return array_values($templates);
    }
    
    return array_values($active_templates);
  }
}


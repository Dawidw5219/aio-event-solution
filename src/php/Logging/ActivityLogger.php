<?php

namespace AIOEvents\Logging;

/**
 * Activity Logger
 * Logs all plugin activities: API calls, email scheduling, etc.
 */
class ActivityLogger
{
  private static $table_name = null;

  /**
   * Activity types
   */
  const TYPE_EMAIL_SCHEDULED = 'email_scheduled';
  const TYPE_EMAIL_SENT = 'email_sent';
  const TYPE_EMAIL_FAILED = 'email_failed';
  const TYPE_API_REQUEST = 'api_request';
  const TYPE_API_RESPONSE = 'api_response';
  const TYPE_REGISTRATION = 'registration';
  const TYPE_CRON = 'cron';
  const TYPE_DEBUG = 'debug';

  /**
   * Get table name
   */
  public static function get_table_name()
  {
    if (self::$table_name === null) {
      global $wpdb;
      self::$table_name = $wpdb->prefix . 'aio_activity_logs';
    }
    return self::$table_name;
  }

  /**
   * Create activity logs table
   */
  public static function create_table()
  {
    global $wpdb;
    $table_name = self::get_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      type varchar(50) NOT NULL,
      action varchar(100) NOT NULL,
      message text NOT NULL,
      context longtext DEFAULT NULL,
      event_id bigint(20) unsigned DEFAULT NULL,
      email varchar(255) DEFAULT NULL,
      status varchar(20) DEFAULT 'info',
      created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      PRIMARY KEY  (id),
      KEY type (type),
      KEY event_id (event_id),
      KEY status (status),
      KEY created_at (created_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
  }

  /**
   * Log activity
   */
  public static function log($type, $action, $message, $context = [], $event_id = null, $email = null, $status = 'info')
  {
    global $wpdb;
    $table_name = self::get_table_name();

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
      self::create_table();
    }

    // Check if logging is enabled
    $settings = get_option('aio_events_settings', []);
    $logging_enabled = $settings['enable_activity_logging'] ?? true;
    
    if (!$logging_enabled && $status !== 'error') {
      return false;
    }

    $wpdb->insert(
      $table_name,
      [
        'type' => sanitize_text_field($type),
        'action' => sanitize_text_field($action),
        'message' => sanitize_textarea_field($message),
        'context' => wp_json_encode($context),
        'event_id' => $event_id ? absint($event_id) : null,
        'email' => $email ? sanitize_email($email) : null,
        'status' => sanitize_text_field($status),
      ],
      ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
    );

    // Also log to error_log for debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log(sprintf(
        '[AIO Events %s] %s: %s | %s',
        strtoupper($status),
        $action,
        $message,
        wp_json_encode($context)
      ));
    }

    // Cleanup old logs (keep last 500)
    self::cleanup_old_logs(500);

    return $wpdb->insert_id;
  }

  /**
   * Log email scheduled
   */
  public static function email_scheduled($event_id, $email, $template_id, $scheduled_for, $response = [])
  {
    return self::log(
      self::TYPE_EMAIL_SCHEDULED,
      'brevo_schedule_email',
      sprintf('Email scheduled for %s to %s', $scheduled_for, $email),
      [
        'template_id' => $template_id,
        'scheduled_for' => $scheduled_for,
        'response' => $response,
      ],
      $event_id,
      $email,
      'success'
    );
  }

  /**
   * Log email sent immediately
   */
  public static function email_sent($event_id, $email, $template_id, $response = [])
  {
    return self::log(
      self::TYPE_EMAIL_SENT,
      'brevo_send_email',
      sprintf('Email sent to %s', $email),
      [
        'template_id' => $template_id,
        'response' => $response,
      ],
      $event_id,
      $email,
      'success'
    );
  }

  /**
   * Log email failed
   */
  public static function email_failed($event_id, $email, $template_id, $error, $request = [])
  {
    return self::log(
      self::TYPE_EMAIL_FAILED,
      'brevo_email_error',
      sprintf('Failed to send email to %s: %s', $email, $error),
      [
        'template_id' => $template_id,
        'error' => $error,
        'request' => $request,
      ],
      $event_id,
      $email,
      'error'
    );
  }

  /**
   * Log API request
   */
  public static function api_request($endpoint, $method, $payload = [], $event_id = null)
  {
    // Mask sensitive data
    $safe_payload = self::mask_sensitive_data($payload);
    
    return self::log(
      self::TYPE_API_REQUEST,
      'brevo_api_' . strtolower($method),
      sprintf('API %s request to %s', $method, $endpoint),
      [
        'endpoint' => $endpoint,
        'method' => $method,
        'payload' => $safe_payload,
      ],
      $event_id,
      null,
      'info'
    );
  }

  /**
   * Log API response
   */
  public static function api_response($endpoint, $status_code, $response = [], $event_id = null)
  {
    $status = ($status_code >= 200 && $status_code < 300) ? 'success' : 'error';
    
    return self::log(
      self::TYPE_API_RESPONSE,
      'brevo_api_response',
      sprintf('API response from %s: HTTP %d', $endpoint, $status_code),
      [
        'endpoint' => $endpoint,
        'status_code' => $status_code,
        'response' => $response,
      ],
      $event_id,
      null,
      $status
    );
  }

  /**
   * Log registration
   */
  public static function registration($event_id, $email, $name, $success = true, $error = null)
  {
    return self::log(
      self::TYPE_REGISTRATION,
      $success ? 'registration_success' : 'registration_failed',
      $success 
        ? sprintf('Registration for %s (%s)', $name, $email)
        : sprintf('Registration failed for %s: %s', $email, $error),
      [
        'name' => $name,
        'success' => $success,
        'error' => $error,
      ],
      $event_id,
      $email,
      $success ? 'success' : 'error'
    );
  }

  /**
   * Log cron execution
   */
  public static function cron($action, $message, $scheduled_count = 0, $error_count = 0)
  {
    $status = $error_count > 0 ? 'warning' : 'success';
    
    return self::log(
      self::TYPE_CRON,
      $action,
      $message,
      [
        'scheduled_count' => $scheduled_count,
        'error_count' => $error_count,
      ],
      null,
      null,
      $status
    );
  }

  /**
   * Log debug message
   */
  public static function debug($message, $context = [], $event_id = null)
  {
    $settings = get_option('aio_events_settings', []);
    $debug_enabled = $settings['enable_debug_logging'] ?? false;
    
    if (!$debug_enabled) {
      return false;
    }

    return self::log(
      self::TYPE_DEBUG,
      'debug',
      $message,
      $context,
      $event_id,
      null,
      'info'
    );
  }

  /**
   * Get logs with filters
   */
  public static function get_logs($args = [])
  {
    global $wpdb;
    $table_name = self::get_table_name();

    $defaults = [
      'type' => null,
      'status' => null,
      'event_id' => null,
      'email' => null,
      'limit' => 100,
      'offset' => 0,
      'orderby' => 'created_at',
      'order' => 'DESC',
    ];

    $args = wp_parse_args($args, $defaults);

    $where = ['1=1'];
    $where_values = [];

    if (!empty($args['type'])) {
      $where[] = 'type = %s';
      $where_values[] = $args['type'];
    }

    if (!empty($args['status'])) {
      $where[] = 'status = %s';
      $where_values[] = $args['status'];
    }

    if (!empty($args['event_id'])) {
      $where[] = 'event_id = %d';
      $where_values[] = $args['event_id'];
    }

    if (!empty($args['email'])) {
      $where[] = 'email LIKE %s';
      $where_values[] = '%' . $wpdb->esc_like($args['email']) . '%';
    }

    $where_clause = implode(' AND ', $where);
    $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);

    if (empty($where_values)) {
      $query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
      $query = $wpdb->prepare($query, absint($args['limit']), absint($args['offset']));
    } else {
      $query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
      $where_values[] = absint($args['limit']);
      $where_values[] = absint($args['offset']);
      $query = $wpdb->prepare($query, ...$where_values);
    }

    return $wpdb->get_results($query, ARRAY_A) ?: [];
  }

  /**
   * Get log count
   */
  public static function get_count($type = null, $status = null)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    $where = ['1=1'];
    $where_values = [];

    if ($type) {
      $where[] = 'type = %s';
      $where_values[] = $type;
    }

    if ($status) {
      $where[] = 'status = %s';
      $where_values[] = $status;
    }

    $where_clause = implode(' AND ', $where);

    if (empty($where_values)) {
      return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}");
    }

    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}",
      ...$where_values
    ));
  }

  /**
   * Clear all logs
   */
  public static function clear_logs()
  {
    global $wpdb;
    $table_name = self::get_table_name();
    return $wpdb->query("TRUNCATE TABLE {$table_name}");
  }

  /**
   * Cleanup old logs
   */
  private static function cleanup_old_logs($keep = 500)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    
    if ($count <= $keep) {
      return;
    }

    $delete_count = $count - $keep;
    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$table_name} ORDER BY created_at ASC LIMIT %d",
      $delete_count
    ));
  }

  /**
   * Mask sensitive data in payload
   */
  private static function mask_sensitive_data($data)
  {
    if (!is_array($data)) {
      return $data;
    }

    $sensitive_keys = ['api-key', 'apiKey', 'password', 'secret', 'token'];
    
    foreach ($data as $key => $value) {
      if (in_array(strtolower($key), array_map('strtolower', $sensitive_keys), true)) {
        $data[$key] = '***MASKED***';
      } elseif (is_array($value)) {
        $data[$key] = self::mask_sensitive_data($value);
      }
    }

    return $data;
  }
}


<?php

namespace AIOEvents\Database;

/**
 * Registration Repository
 * Handles database operations for event registrations using WordPress standards
 */
class RegistrationRepository
{
  /**
   * Get table name
   */
  private static function get_table_name()
  {
    global $wpdb;
    return $wpdb->prefix . 'aio_event_registrations';
  }

  /**
   * Check if table exists
   */
  public static function table_exists()
  {
    global $wpdb;
    $table_name = self::get_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
  }

  /**
   * Get registration by event ID and email
   */
  public static function get_by_event_and_email($event_id, $email)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return null;
    }

    $event_id = absint($event_id);
    $email = sanitize_email($email);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE event_id = %d AND email = %s LIMIT 1",
        $event_id,
        $email
      ),
      ARRAY_A
    );
  }

  /**
   * Get registration by join token
   */
  public static function get_by_token($token)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return null;
    }

    $token = sanitize_text_field($token);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE join_token = %s LIMIT 1",
        $token
      ),
      ARRAY_A
    );
  }

  /**
   * Get registration by ID
   */
  public static function get_by_id($id)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return null;
    }

    $id = absint($id);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
        $id
      ),
      ARRAY_A
    );
  }

  /**
   * Get registrations by event ID
   */
  public static function get_by_event_id($event_id, $limit = null, $offset = 0)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return [];
    }

    $event_id = absint($event_id);
    $limit = $limit ? absint($limit) : null;
    $offset = absint($offset);

    $query = "SELECT * FROM {$table_name} WHERE event_id = %d ORDER BY id DESC";
    $query_args = [$event_id];

    if ($limit) {
      $query .= " LIMIT %d OFFSET %d";
      $query_args[] = $limit;
      $query_args[] = $offset;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results(
      $wpdb->prepare($query, ...$query_args),
      ARRAY_A
    );

    return $results ? $results : [];
  }

  /**
   * Check if user is already registered
   */
  public static function is_registered($event_id, $email)
  {
    $registration = self::get_by_event_and_email($event_id, $email);
    return !empty($registration);
  }

  /**
   * Create new registration
   */
  public static function create($event_id, $email, $name, $phone = '', $brevo_added = false, $join_token = null)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return new \WP_Error('no_table', __('Registrations table does not exist', 'aio-event-solution'));
    }

    $event_id = absint($event_id);
    $email = sanitize_email($email);
    $name = sanitize_text_field($name);
    $phone = sanitize_text_field($phone);
    $brevo_added = (bool) $brevo_added;
    $join_token = $join_token ? sanitize_text_field($join_token) : null;

    // Check if already registered
    if (self::is_registered($event_id, $email)) {
      return new \WP_Error('already_registered', __('User is already registered', 'aio-event-solution'));
    }

    // Prepare data and format arrays
    $data = [
      'event_id' => $event_id,
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'brevo_added' => $brevo_added ? 1 : 0,
    ];
    $formats = ['%d', '%s', '%s', '%s', '%d'];
    
    // Add join_token (always add it, even if empty)
    // Truncate if too long (safety check for varchar(255))
    if ($join_token && strlen($join_token) > 255) {
      $join_token = substr($join_token, 0, 255);
    }
    $data['join_token'] = $join_token ? $join_token : '';
    $formats[] = '%s';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $inserted = $wpdb->insert(
      $table_name,
      $data,
      $formats
    );

    if (!$inserted) {
      global $wpdb;
      $error_message = $wpdb->last_error ? $wpdb->last_error : __('Failed to save registration', 'aio-event-solution');
      error_log('[AIO Events] Registration insert failed: ' . $error_message);
      error_log('[AIO Events] Last query: ' . $wpdb->last_query);
      return new \WP_Error('db_error', $error_message, ['wpdb_error' => $wpdb->last_error, 'wpdb_query' => $wpdb->last_query]);
    }

    return [
      'success' => true,
      'insert_id' => $wpdb->insert_id,
    ];
  }

  /**
   * Update registration
   */
  public static function update($id, $data)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return new \WP_Error('no_table', __('Registrations table does not exist', 'aio-event-solution'));
    }

    $id = absint($id);
    $update_data = [];
    $format = [];

    if (isset($data['brevo_added'])) {
      $update_data['brevo_added'] = (bool) $data['brevo_added'] ? 1 : 0;
      $format[] = '%d';
    }

    if (isset($data['join_token'])) {
      $update_data['join_token'] = sanitize_text_field($data['join_token']);
      $format[] = '%s';
    }

    if (isset($data['clicked_join_link'])) {
      $update_data['clicked_join_link'] = (bool) $data['clicked_join_link'] ? 1 : 0;
      $format[] = '%d';
    }

    // Email tracking fields
    $email_fields = ['registration_email_sent_at', 'reminder_email_sent_at', 'join_email_sent_at', 'followup_email_sent_at'];
    foreach ($email_fields as $field) {
      if (isset($data[$field])) {
        $update_data[$field] = $data[$field] ? sanitize_text_field($data[$field]) : null;
        $format[] = '%s';
      }
    }

    if (empty($update_data)) {
      return new \WP_Error('no_data', __('No data to update', 'aio-event-solution'));
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $updated = $wpdb->update(
      $table_name,
      $update_data,
      ['id' => $id],
      $format,
      ['%d']
    );

    if ($updated === false) {
      return new \WP_Error('db_error', __('Failed to update registration', 'aio-event-solution'));
    }

    return [
      'success' => true,
      'updated' => $updated,
    ];
  }

  /**
   * Get registration count for event
   */
  public static function count_by_event_id($event_id)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return 0;
    }

    $event_id = absint($event_id);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $count = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE event_id = %d",
        $event_id
      )
    );

    return (int) $count;
  }

  /**
   * Get registrations that need a specific email type
   * 
   * @param int $event_id Event ID
   * @param string $email_type Email type: 'reminder', 'join', 'followup'
   * @param int|null $min_registration_hours Minimum hours before event the registration must have been made (for reminder)
   * @return array
   */
  public static function get_needing_email($event_id, $email_type, $min_registration_hours = null)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return [];
    }

    $event_id = absint($event_id);
    $column = $email_type . '_email_sent_at';
    
    // Base query: email not sent yet
    $query = "SELECT * FROM {$table_name} WHERE event_id = %d AND {$column} IS NULL";
    $params = [$event_id];

    // For reminder emails, check if registration was early enough
    if ($min_registration_hours !== null && $email_type === 'reminder') {
      // Get event datetime
      $event_date = get_post_meta($event_id, '_aio_event_start_date', true);
      $event_time = get_post_meta($event_id, '_aio_event_start_time', true);
      
      if ($event_date) {
        $event_datetime = $event_date . ' ' . ($event_time ?: '00:00:00');
        $cutoff_time = date('Y-m-d H:i:s', strtotime($event_datetime) - ($min_registration_hours * 3600));
        
        // Only include registrations made before the cutoff time
        $query .= " AND registered_at <= %s";
        $params[] = $cutoff_time;
      }
    }

    $query .= " ORDER BY id ASC";

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results(
      $wpdb->prepare($query, ...$params),
      ARRAY_A
    );

    return $results ?: [];
  }

  /**
   * Mark email as sent for a registration
   * 
   * @param int $registration_id
   * @param string $email_type Email type: 'registration', 'reminder', 'join', 'followup'
   * @return bool
   */
  public static function mark_email_sent($registration_id, $email_type)
  {
    $column = $email_type . '_email_sent_at';
    $result = self::update($registration_id, [
      $column => current_time('mysql'),
    ]);
    return !is_wp_error($result);
  }

  /**
   * Get all registrations for multiple events that need emails
   * 
   * @param string $email_type Email type
   * @param array $event_ids Array of event IDs (or empty for all)
   * @return array Grouped by event_id
   */
  public static function get_all_needing_email($email_type, $event_ids = [])
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return [];
    }

    $column = $email_type . '_email_sent_at';
    
    $query = "SELECT * FROM {$table_name} WHERE {$column} IS NULL";
    
    if (!empty($event_ids)) {
      $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
      $query .= " AND event_id IN ($placeholders)";
      $query = $wpdb->prepare($query, ...$event_ids);
    }
    
    $query .= " ORDER BY event_id, id ASC";

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results($query, ARRAY_A);

    // Group by event_id
    $grouped = [];
    foreach ($results ?: [] as $row) {
      $grouped[$row['event_id']][] = $row;
    }

    return $grouped;
  }
}


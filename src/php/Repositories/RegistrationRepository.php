<?php

namespace AIOEvents\Repositories;

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
}

<?php

namespace AIOEvents\Database;

/**
 * Scheduled Email Repository
 * Handles database operations for scheduled emails using WordPress standards
 */
class ScheduledEmailRepository
{
  /**
   * Get table name
   */
  private static function get_table_name()
  {
    global $wpdb;
    return $wpdb->prefix . 'aio_event_scheduled_emails';
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
   * Create scheduled email record
   */
  public static function create($data)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return new \WP_Error('no_table', __('Scheduled emails table does not exist', 'aio-event-solution'));
    }

    $insert_data = [
      'event_id' => absint($data['event_id'] ?? 0),
      'event_title' => sanitize_text_field($data['event_title'] ?? ''),
      'event_date' => sanitize_text_field($data['event_date'] ?? ''),
      'email_type' => sanitize_text_field($data['email_type'] ?? 'before'),
      'brevo_template_id' => isset($data['brevo_template_id']) ? absint($data['brevo_template_id']) : null,
      'recipient_count' => isset($data['recipient_count']) ? absint($data['recipient_count']) : 0,
      'scheduled_for' => sanitize_text_field($data['scheduled_for'] ?? ''),
      'status' => sanitize_text_field($data['status'] ?? 'pending'),
      'error_message' => isset($data['error_message']) ? sanitize_textarea_field($data['error_message']) : null,
      'brevo_message_id' => isset($data['brevo_message_id']) ? sanitize_text_field($data['brevo_message_id']) : null,
    ];

    $format = ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $inserted = $wpdb->insert($table_name, $insert_data, $format);

    if (!$inserted) {
      return new \WP_Error('db_error', __('Failed to save scheduled email', 'aio-event-solution'));
    }

    return [
      'success' => true,
      'insert_id' => $wpdb->insert_id,
    ];
  }

  /**
   * Update scheduled email record
   */
  public static function update($id, $data)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return new \WP_Error('no_table', __('Scheduled emails table does not exist', 'aio-event-solution'));
    }

    $id = absint($id);
    $update_data = [];
    $format = [];

    if (isset($data['status'])) {
      $update_data['status'] = sanitize_text_field($data['status']);
      $format[] = '%s';
    }

    if (isset($data['error_message'])) {
      $update_data['error_message'] = sanitize_textarea_field($data['error_message']);
      $format[] = '%s';
    }

    if (isset($data['brevo_message_id'])) {
      $update_data['brevo_message_id'] = sanitize_text_field($data['brevo_message_id']);
      $format[] = '%s';
    }

    if (isset($data['recipient_count'])) {
      $update_data['recipient_count'] = absint($data['recipient_count']);
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
      return new \WP_Error('db_error', __('Failed to update scheduled email', 'aio-event-solution'));
    }

    return [
      'success' => true,
      'updated' => $updated,
    ];
  }

  /**
   * Get scheduled email by ID
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
      $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d LIMIT 1", $id),
      ARRAY_A
    );
  }

  /**
   * Get scheduled email by event ID, email type and scheduled_for
   */
  public static function get_by_event_type_and_scheduled($event_id, $email_type, $scheduled_for)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return null;
    }

    $event_id = absint($event_id);
    $email_type = sanitize_text_field($email_type);
    $scheduled_for = sanitize_text_field($scheduled_for);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE event_id = %d AND email_type = %s AND scheduled_for = %s LIMIT 1",
        $event_id,
        $email_type,
        $scheduled_for
      ),
      ARRAY_A
    );
  }

  /**
   * Get scheduled email by event ID and scheduled_for (legacy, for backwards compatibility)
   * @deprecated Use get_by_event_type_and_scheduled instead
   */
  public static function get_by_event_and_scheduled($event_id, $scheduled_for)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return null;
    }

    $event_id = absint($event_id);
    $scheduled_for = sanitize_text_field($scheduled_for);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE event_id = %d AND scheduled_for = %s LIMIT 1",
        $event_id,
        $scheduled_for
      ),
      ARRAY_A
    );
  }

  /**
   * Get scheduled emails with filters
   */
  public static function get_all($args = [])
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return [];
    }

    $defaults = [
      'status' => null,
      'event_id' => null,
      'limit' => 50,
      'offset' => 0,
      'orderby' => 'scheduled_for',
      'order' => 'DESC',
    ];

    $args = wp_parse_args($args, $defaults);

    $where = ['1=1'];
    $where_values = [];

    if (!empty($args['status'])) {
      $where[] = 'status = %s';
      $where_values[] = sanitize_text_field($args['status']);
    }

    if (!empty($args['event_id'])) {
      $where[] = 'event_id = %d';
      $where_values[] = absint($args['event_id']);
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

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results($query, ARRAY_A);

    return $results ? $results : [];
  }

  /**
   * Get pending scheduled emails
   */
  public static function get_pending($limit = null)
  {
    return self::get_all([
      'status' => 'pending',
      'limit' => $limit ?: 50,
      'orderby' => 'scheduled_for',
      'order' => 'ASC',
    ]);
  }

  /**
   * Get count by status
   */
  public static function count_by_status($status = null)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    if (!self::table_exists()) {
      return 0;
    }

    if ($status) {
      $status = sanitize_text_field($status);
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $count = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", $status)
      );
    } else {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }

    return (int) $count;
  }
}


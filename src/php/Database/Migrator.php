<?php

namespace AIOEvents\Database;

/**
 * Database migrations handler
 */
class Migrator
{
  /**
   * Run all migrations
   */
  public static function run()
  {
    self::migrate_registrations_table();
  }

  /**
   * Migrate registrations table - add missing columns
   */
  private static function migrate_registrations_table()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_registrations';

    if (!self::table_exists($table_name)) {
      return;
    }

    self::add_column_if_missing($table_name, 'join_token', 'varchar(255) DEFAULT NULL');
    self::add_index_if_missing($table_name, 'join_token');
    self::add_column_if_missing($table_name, 'clicked_join_link', 'tinyint(1) DEFAULT 0 NOT NULL');
  }

  /**
   * Check if table exists
   */
  private static function table_exists($table_name)
  {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
  }

  /**
   * Add column if it doesn't exist
   */
  private static function add_column_if_missing($table_name, $column_name, $column_definition)
  {
    global $wpdb;
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $column_exists = $wpdb->get_results($wpdb->prepare(
      "SHOW COLUMNS FROM $table_name LIKE %s",
      $column_name
    ));

    if (empty($column_exists)) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column_name $column_definition");
      error_log("[AIO Events] Added $column_name column to $table_name");
    }
  }

  /**
   * Add index if it doesn't exist
   */
  private static function add_index_if_missing($table_name, $index_name)
  {
    global $wpdb;
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $index_exists = $wpdb->get_results($wpdb->prepare(
      "SHOW INDEX FROM $table_name WHERE Key_name = %s",
      $index_name
    ));

    if (empty($index_exists)) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $wpdb->query("ALTER TABLE $table_name ADD INDEX $index_name ($index_name)");
      error_log("[AIO Events] Added $index_name index to $table_name");
    }
  }
}


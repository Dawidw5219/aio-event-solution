<?php

namespace AIOEvents\Scheduler;

/**
 * Cron Logger
 * Logs cron execution and provides status checking
 */
class CronLogger
{
  private static $table_name = null;

  /**
   * Get cron logs table name
   */
  public static function get_table_name()
  {
    if (self::$table_name === null) {
      global $wpdb;
      self::$table_name = $wpdb->prefix . 'aio_cron_logs';
    }
    return self::$table_name;
  }

  /**
   * Create cron logs table
   */
  public static function create_table()
  {
    global $wpdb;
    $table_name = self::get_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      hook_name varchar(255) NOT NULL,
      execution_time datetime NOT NULL,
      status varchar(20) NOT NULL DEFAULT 'success',
      message text DEFAULT NULL,
      execution_duration decimal(10,3) DEFAULT NULL,
      scheduled_count int(11) DEFAULT 0,
      error_count int(11) DEFAULT 0,
      created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      PRIMARY KEY  (id),
      KEY hook_name (hook_name),
      KEY execution_time (execution_time),
      KEY status (status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
  }

  /**
   * Log cron execution
   */
  public static function log($hook_name, $status, $message = null, $execution_duration = null, $scheduled_count = 0, $error_count = 0)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    $wpdb->insert(
      $table_name,
      [
        'hook_name' => $hook_name,
        'execution_time' => current_time('mysql'),
        'status' => $status, // 'success', 'error', 'warning'
        'message' => $message,
        'execution_duration' => $execution_duration,
        'scheduled_count' => $scheduled_count,
        'error_count' => $error_count,
      ],
      ['%s', '%s', '%s', '%s', '%f', '%d', '%d']
    );

    // Also log to error_log for debugging
    error_log(sprintf(
      '[AIO Events Cron] %s - Hook: %s, Status: %s, Duration: %s, Scheduled: %d, Errors: %d, Message: %s',
      current_time('mysql'),
      $hook_name,
      $status,
      $execution_duration !== null ? number_format($execution_duration, 3) . 's' : 'N/A',
      $scheduled_count,
      $error_count,
      $message ?? 'N/A'
    ));

    // Log errors using ErrorLogger if status is error
    if ($status === 'error') {
      require_once AIO_EVENTS_PATH . 'php/Logger/ErrorLogger.php';
      \AIOEvents\Logger\ErrorLogger::error(
        $message ?? 'Cron execution failed',
        \AIOEvents\Logger\ErrorLogger::TYPE_CRON,
        [
          'hook_name' => $hook_name,
          'execution_duration' => $execution_duration,
          'scheduled_count' => $scheduled_count,
          'error_count' => $error_count,
        ]
      );
    }

    // Clean old logs (keep last 100)
    self::cleanup_old_logs(100);
  }

  /**
   * Get latest cron execution log
   */
  public static function get_latest_log($hook_name = 'aio_events_schedule_daily_emails')
  {
    global $wpdb;
    $table_name = self::get_table_name();

    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM $table_name WHERE hook_name = %s ORDER BY execution_time DESC LIMIT 1",
        $hook_name
      ),
      OBJECT
    );
  }

  /**
   * Get cron execution logs
   */
  public static function get_logs($hook_name = 'aio_events_schedule_daily_emails', $limit = 50)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM $table_name WHERE hook_name = %s ORDER BY execution_time DESC LIMIT %d",
        $hook_name,
        $limit
      ),
      OBJECT
    );
  }

  /**
   * Check if cron should have run (within last 25 hours for daily cron)
   */
  public static function should_have_run($hook_name = 'aio_events_schedule_daily_emails', $hours_threshold = 25)
  {
    $latest = self::get_latest_log($hook_name);
    if (!$latest) {
      return ['status' => 'never_run', 'message' => __('Cron has never been executed', 'aio-event-solution')];
    }

    $last_execution = strtotime($latest->execution_time);
    $hours_since = (time() - $last_execution) / 3600;

    if ($hours_since > $hours_threshold) {
      return [
        'status' => 'missed',
        'message' => sprintf(__('Cron should have run within last %d hours. Last execution: %s ago', 'aio-event-solution'), $hours_threshold, human_time_diff($last_execution)),
        'hours_since' => $hours_since,
        'last_execution' => $latest->execution_time,
      ];
    }

    return [
      'status' => 'ok',
      'message' => sprintf(__('Last execution: %s ago', 'aio-event-solution'), human_time_diff($last_execution)),
      'hours_since' => $hours_since,
      'last_execution' => $latest->execution_time,
    ];
  }

  /**
   * Get cron statistics
   */
  public static function get_statistics($hook_name = 'aio_events_schedule_daily_emails', $days = 30)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    $since_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $stats = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT 
          COUNT(*) as total_executions,
          SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
          SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
          SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_count,
          AVG(execution_duration) as avg_duration,
          SUM(scheduled_count) as total_scheduled,
          SUM(error_count) as total_errors
        FROM $table_name 
        WHERE hook_name = %s AND execution_time >= %s",
        $hook_name,
        $since_date
      ),
      OBJECT
    );

    return $stats ? (array) $stats : [
      'total_executions' => 0,
      'success_count' => 0,
      'error_count' => 0,
      'warning_count' => 0,
      'avg_duration' => 0,
      'total_scheduled' => 0,
      'total_errors' => 0,
    ];
  }

  /**
   * Cleanup old logs
   */
  private static function cleanup_old_logs($keep = 100)
  {
    global $wpdb;
    $table_name = self::get_table_name();

    // Get IDs to keep
    $ids_to_keep = $wpdb->get_col(
      "SELECT id FROM $table_name ORDER BY execution_time DESC LIMIT " . absint($keep)
    );

    if (empty($ids_to_keep)) {
      return;
    }

    $placeholders = implode(',', array_fill(0, count($ids_to_keep), '%d'));
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM $table_name WHERE id NOT IN ($placeholders)",
        ...$ids_to_keep
      )
    );
  }

  /**
   * Check WordPress cron status
   */
  public static function check_wp_cron_status()
  {
    $hook = 'aio_events_schedule_daily_emails';
    $next_scheduled = wp_next_scheduled($hook);

    if (!$next_scheduled) {
      return [
        'status' => 'not_scheduled',
        'message' => __('Cron is not scheduled', 'aio-event-solution'),
        'next_run' => null,
      ];
    }

    $time_until = $next_scheduled - time();
    $is_overdue = $time_until < -3600; // More than 1 hour past due

    return [
      'status' => $is_overdue ? 'overdue' : 'scheduled',
      'message' => $is_overdue
        ? sprintf(__('Next run is overdue by %s', 'aio-event-solution'), human_time_diff(time(), $next_scheduled))
        : sprintf(__('Next run in %s', 'aio-event-solution'), human_time_diff($next_scheduled, time())),
      'next_run' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled),
      'next_run_timestamp' => $next_scheduled,
      'time_until' => $time_until,
    ];
  }
}


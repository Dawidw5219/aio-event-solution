<?php

namespace AIOEvents\Scheduler;

/**
 * Manages cron scheduling for email dispatch
 */
class CronManager
{
  const HOOK_NAME = 'aio_events_schedule_daily_emails';

  /**
   * Initialize cron scheduling
   */
  public static function init()
  {
    add_action(self::HOOK_NAME, [self::class, 'run_scheduler']);
    self::schedule();
  }

  /**
   * Schedule daily cron job at 23:30
   */
  public static function schedule()
  {
    if (!wp_next_scheduled(self::HOOK_NAME)) {
      $timestamp = strtotime("today 23:30");
      if ($timestamp < time()) {
        $timestamp = strtotime("tomorrow 23:30");
      }
      wp_schedule_event($timestamp, 'daily', self::HOOK_NAME);
    }
  }

  /**
   * Unschedule cron job (for plugin deactivation)
   */
  public static function unschedule()
  {
    $timestamp = wp_next_scheduled(self::HOOK_NAME);
    if ($timestamp) {
      wp_unschedule_event($timestamp, self::HOOK_NAME);
    }
  }

  /**
   * Run email scheduler
   */
  public static function run_scheduler()
  {
    $start_time = microtime(true);

    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
    require_once AIO_EVENTS_PATH . 'php/Scheduler/CronLogger.php';

    EmailScheduler::create_table();
    CronLogger::create_table();

    try {
      $result = EmailScheduler::run_daily_schedule();
      $execution_duration = microtime(true) - $start_time;

      $scheduled_count = is_array($result) ? ($result['scheduled'] ?? 0) : 0;
      $error_count = is_array($result) ? ($result['errors'] ?? 0) : 0;

      $status = ($result === false || is_wp_error($result)) ? 'error' : 'success';
      $message = self::build_log_message($result, $status, $scheduled_count);

      CronLogger::log(
        self::HOOK_NAME,
        $status,
        $message,
        $execution_duration,
        $scheduled_count,
        $error_count
      );

      return $result;
    } catch (\Exception $e) {
      $execution_duration = microtime(true) - $start_time;
      
      CronLogger::log(
        self::HOOK_NAME,
        'error',
        sprintf(__('Exception: %s', 'aio-event-solution'), $e->getMessage()),
        $execution_duration,
        0,
        1
      );
      
      error_log('[AIO Events Cron] Exception: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * Build log message based on result
   */
  private static function build_log_message($result, $status, $scheduled_count)
  {
    if ($status === 'error') {
      return is_wp_error($result) 
        ? $result->get_error_message() 
        : 'Email scheduler returned false';
    }

    return sprintf(__('Scheduled %d emails', 'aio-event-solution'), $scheduled_count);
  }
}


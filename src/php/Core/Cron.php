<?php

namespace AIOEvents\Core;

/**
 * Manages cron scheduling for email dispatch
 */
class Cron
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

    require_once AIO_EVENTS_PATH . 'php/Email/Scheduler.php';
    require_once AIO_EVENTS_PATH . 'php/Logging/CronLogger.php';

    \AIOEvents\Email\Scheduler::create_table();
    \AIOEvents\Logging\CronLogger::create_table();

    try {
      $result = \AIOEvents\Email\Scheduler::run_daily_schedule();
      $execution_duration = microtime(true) - $start_time;

      $sent_count = is_array($result) ? ($result['sent'] ?? 0) : 0;
      $error_count = is_array($result) ? ($result['errors'] ?? 0) : 0;

      $status = ($result === false || is_wp_error($result)) ? 'error' : 'success';
      $message = self::build_log_message($result, $status, $sent_count);

      \AIOEvents\Logging\CronLogger::log(
        self::HOOK_NAME,
        $status,
        $message,
        $execution_duration,
        $sent_count,
        $error_count
      );

      return $result;
    } catch (\Exception $e) {
      $execution_duration = microtime(true) - $start_time;
      
      \AIOEvents\Logging\CronLogger::log(
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
  private static function build_log_message($result, $status, $sent_count)
  {
    if ($status === 'error') {
      return is_wp_error($result) 
        ? $result->get_error_message() 
        : 'Email scheduler returned false';
    }

    if ($sent_count === 0) {
      return __('No emails to send at this time', 'aio-event-solution');
    }

    return sprintf(__('Sent %d emails to individual recipients', 'aio-event-solution'), $sent_count);
  }
}


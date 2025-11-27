<?php

namespace AIOEvents\Admin;

use Timber\Timber;

/**
 * Cron Status Admin Page
 * Displays cron execution status and logs
 */
class CronStatusPage
{
  /**
   * Register admin page
   */
  public static function register()
  {
    add_submenu_page(
      'edit.php?post_type=aio_event',
      __('Cron Status', 'aio-event-solution'),
      __('Cron Status', 'aio-event-solution'),
      'manage_options',
      'aio-events-cron-status',
      [self::class, 'render']
    );
  }

  /**
   * Render cron status page
   */
  public static function render()
  {
    require_once AIO_EVENTS_PATH . 'php/Logging/CronLogger.php';

    $notice = null;

    // Handle manual trigger
    if (isset($_POST['trigger_cron']) && check_admin_referer('aio_trigger_cron')) {
      $hook = 'aio_events_schedule_daily_emails';
      do_action($hook);
      $notice = __('Cron manually triggered. Check logs below.', 'aio-event-solution');
    }

    // Get status
    $cron_status = \AIOEvents\Logging\CronLogger::check_wp_cron_status();
    $should_run = \AIOEvents\Logging\CronLogger::should_have_run();
    $latest_log = \AIOEvents\Logging\CronLogger::get_latest_log();
    $logs = \AIOEvents\Logging\CronLogger::get_logs('aio_events_schedule_daily_emails', 20);
    $stats = \AIOEvents\Logging\CronLogger::get_statistics('aio_events_schedule_daily_emails', 30);

    $context = [
      'notice' => $notice,
      'cron_status' => $cron_status,
      'should_run' => $should_run,
      'latest_log' => $latest_log,
      'logs' => $logs,
      'stats' => $stats,
      'cron_url' => home_url('wp-cron.php?doing_wp_cron'),
    ];

    Timber::render('admin/pages/cron-status.twig', $context);
  }
}

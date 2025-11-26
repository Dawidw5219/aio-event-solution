<?php

namespace AIOEvents\Admin;

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
    require_once AIO_EVENTS_PATH . 'php/Scheduler/CronLogger.php';
    
    // Handle manual trigger
    if (isset($_POST['trigger_cron']) && check_admin_referer('aio_trigger_cron')) {
      $hook = 'aio_events_schedule_daily_emails';
      do_action($hook);
      echo '<div class="notice notice-success is-dismissible"><p>' . __('Cron manually triggered. Check logs below.', 'aio-event-solution') . '</p></div>';
    }

    // Get status
    $cron_status = \AIOEvents\Scheduler\CronLogger::check_wp_cron_status();
    $should_run = \AIOEvents\Scheduler\CronLogger::should_have_run();
    $latest_log = \AIOEvents\Scheduler\CronLogger::get_latest_log();
    $logs = \AIOEvents\Scheduler\CronLogger::get_logs('aio_events_schedule_daily_emails', 20);
    $stats = \AIOEvents\Scheduler\CronLogger::get_statistics('aio_events_schedule_daily_emails', 30);

    ?>
    <div class="wrap">
      <h1><?php _e('Cron Status', 'aio-event-solution'); ?></h1>
      
      <div style="margin: 20px 0;">
        <form method="post" style="display: inline-block;">
          <?php wp_nonce_field('aio_trigger_cron'); ?>
          <button type="submit" name="trigger_cron" class="button button-primary">
            <?php _e('▶ Run Cron Now', 'aio-event-solution'); ?>
          </button>
        </form>
        <span class="description" style="margin-left: 10px;">
          <?php _e('Manually trigger the email scheduler cron job', 'aio-event-solution'); ?>
        </span>
      </div>

      <div class="card" style="max-width: 800px;">
        <h2><?php _e('WordPress Cron Status', 'aio-event-solution'); ?></h2>
        
        <?php if ($cron_status['status'] === 'not_scheduled') : ?>
          <div class="notice notice-error inline">
            <p><strong><?php _e('⚠️ Warning:', 'aio-event-solution'); ?></strong> <?php echo esc_html($cron_status['message']); ?></p>
          </div>
        <?php elseif ($cron_status['status'] === 'overdue') : ?>
          <div class="notice notice-warning inline">
            <p><strong><?php _e('⚠️ Warning:', 'aio-event-solution'); ?></strong> <?php echo esc_html($cron_status['message']); ?></p>
          </div>
        <?php else : ?>
          <div class="notice notice-success inline">
            <p><strong>✓ <?php _e('Scheduled:', 'aio-event-solution'); ?></strong> <?php echo esc_html($cron_status['message']); ?></p>
            <?php if (!empty($cron_status['next_run'])) : ?>
              <p><?php _e('Next run:', 'aio-event-solution'); ?> <strong><?php echo esc_html($cron_status['next_run']); ?></strong></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2><?php _e('Execution Status', 'aio-event-solution'); ?></h2>
        
        <?php if ($should_run['status'] === 'never_run') : ?>
          <div class="notice notice-error inline">
            <p><strong><?php _e('⚠️ Warning:', 'aio-event-solution'); ?></strong> <?php echo esc_html($should_run['message']); ?></p>
          </div>
        <?php elseif ($should_run['status'] === 'missed') : ?>
          <div class="notice notice-error inline">
            <p><strong><?php _e('⚠️ Critical:', 'aio-event-solution'); ?></strong> <?php echo esc_html($should_run['message']); ?></p>
            <p><?php _e('The cron job may not be executing. Check server cron configuration or consider using a real cron job instead of WordPress cron.', 'aio-event-solution'); ?></p>
          </div>
        <?php else : ?>
          <div class="notice notice-success inline">
            <p><strong>✓ <?php _e('OK:', 'aio-event-solution'); ?></strong> <?php echo esc_html($should_run['message']); ?></p>
          </div>
        <?php endif; ?>

        <?php if ($latest_log) : ?>
          <table class="widefat" style="margin-top: 15px;">
            <tr>
              <th style="width: 150px;"><?php _e('Last Execution:', 'aio-event-solution'); ?></th>
              <td><?php echo esc_html($latest_log->execution_time); ?></td>
            </tr>
            <tr>
              <th><?php _e('Status:', 'aio-event-solution'); ?></th>
              <td>
                <?php if ($latest_log->status === 'success') : ?>
                  <span style="color: #46b450;">✓ <?php _e('Success', 'aio-event-solution'); ?></span>
                <?php elseif ($latest_log->status === 'error') : ?>
                  <span style="color: #dc3232;">✗ <?php _e('Error', 'aio-event-solution'); ?></span>
                <?php else : ?>
                  <span style="color: #ffb900;">⚠ <?php _e('Warning', 'aio-event-solution'); ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <th><?php _e('Duration:', 'aio-event-solution'); ?></th>
              <td><?php echo $latest_log->execution_duration !== null ? number_format($latest_log->execution_duration, 3) . 's' : 'N/A'; ?></td>
            </tr>
            <tr>
              <th><?php _e('Scheduled Emails:', 'aio-event-solution'); ?></th>
              <td><?php echo esc_html($latest_log->scheduled_count); ?></td>
            </tr>
            <tr>
              <th><?php _e('Errors:', 'aio-event-solution'); ?></th>
              <td><?php echo esc_html($latest_log->error_count); ?></td>
            </tr>
            <?php if (!empty($latest_log->message)) : ?>
              <tr>
                <th><?php _e('Message:', 'aio-event-solution'); ?></th>
                <td><?php echo esc_html($latest_log->message); ?></td>
              </tr>
            <?php endif; ?>
          </table>
        <?php endif; ?>
      </div>

      <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2><?php _e('Statistics (Last 30 Days)', 'aio-event-solution'); ?></h2>
        <table class="widefat">
          <tr>
            <th style="width: 200px;"><?php _e('Total Executions:', 'aio-event-solution'); ?></th>
            <td><?php echo esc_html($stats['total_executions']); ?></td>
          </tr>
          <tr>
            <th><?php _e('Successful:', 'aio-event-solution'); ?></th>
            <td style="color: #46b450;"><?php echo esc_html($stats['success_count']); ?></td>
          </tr>
          <tr>
            <th><?php _e('Errors:', 'aio-event-solution'); ?></th>
            <td style="color: #dc3232;"><?php echo esc_html($stats['error_count']); ?></td>
          </tr>
          <tr>
            <th><?php _e('Warnings:', 'aio-event-solution'); ?></th>
            <td style="color: #ffb900;"><?php echo esc_html($stats['warning_count']); ?></td>
          </tr>
          <tr>
            <th><?php _e('Avg Duration:', 'aio-event-solution'); ?></th>
            <td><?php echo $stats['avg_duration'] > 0 ? number_format($stats['avg_duration'], 3) . 's' : 'N/A'; ?></td>
          </tr>
          <tr>
            <th><?php _e('Total Scheduled:', 'aio-event-solution'); ?></th>
            <td><?php echo esc_html($stats['total_scheduled']); ?></td>
          </tr>
          <tr>
            <th><?php _e('Total Errors:', 'aio-event-solution'); ?></th>
            <td style="color: #dc3232;"><?php echo esc_html($stats['total_errors']); ?></td>
          </tr>
        </table>
      </div>

      <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2><?php _e('Recent Execution Logs', 'aio-event-solution'); ?></h2>
        
        <?php if (empty($logs)) : ?>
          <p><?php _e('No logs available yet.', 'aio-event-solution'); ?></p>
        <?php else : ?>
          <table class="widefat striped">
            <thead>
              <tr>
                <th><?php _e('Execution Time', 'aio-event-solution'); ?></th>
                <th><?php _e('Status', 'aio-event-solution'); ?></th>
                <th><?php _e('Duration', 'aio-event-solution'); ?></th>
                <th><?php _e('Scheduled', 'aio-event-solution'); ?></th>
                <th><?php _e('Errors', 'aio-event-solution'); ?></th>
                <th><?php _e('Message', 'aio-event-solution'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log) : ?>
                <tr>
                  <td><?php echo esc_html($log->execution_time); ?></td>
                  <td>
                    <?php if ($log->status === 'success') : ?>
                      <span style="color: #46b450;">✓ <?php _e('Success', 'aio-event-solution'); ?></span>
                    <?php elseif ($log->status === 'error') : ?>
                      <span style="color: #dc3232;">✗ <?php _e('Error', 'aio-event-solution'); ?></span>
                    <?php else : ?>
                      <span style="color: #ffb900;">⚠ <?php _e('Warning', 'aio-event-solution'); ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo $log->execution_duration !== null ? number_format($log->execution_duration, 3) . 's' : 'N/A'; ?></td>
                  <td><?php echo esc_html($log->scheduled_count); ?></td>
                  <td style="color: #dc3232;"><?php echo esc_html($log->error_count); ?></td>
                  <td><?php echo esc_html($log->message ?: '—'); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="card" style="max-width: 800px; margin-top: 20px; background: #f0f6fc; border-left: 4px solid #2271b1;">
        <h3 style="margin-top: 0;"><?php _e('💡 Tips for Reliable Cron Execution', 'aio-event-solution'); ?></h3>
        <ul style="margin: 0; padding-left: 20px;">
          <li><?php _e('WordPress cron relies on site traffic. If your site has low traffic, cron may not execute on time.', 'aio-event-solution'); ?></li>
          <li><?php _e('For production sites, consider disabling WordPress cron (define DISABLE_WP_CRON) and set up a real server cron job:', 'aio-event-solution'); ?>
            <code style="display: block; margin-top: 5px; padding: 5px; background: #fff;">*/5 * * * * wget -q -O - <?php echo esc_url(home_url('wp-cron.php?doing_wp_cron')); ?> >/dev/null 2>&1</code>
          </li>
          <li><?php _e('Monitor this page regularly to ensure cron is executing as expected.', 'aio-event-solution'); ?></li>
          <li><?php _e('If cron is consistently missing, check your server error logs for WordPress cron issues.', 'aio-event-solution'); ?></li>
        </ul>
      </div>
    </div>
    <?php
  }
}


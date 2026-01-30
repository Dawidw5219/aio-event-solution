<?php

namespace AIOEvents\Logging;

/**
 * Error Logger
 * Global error logging system with email notifications
 */
class ErrorLogger
{
  /**
   * Log levels
   */
  const LEVEL_ERROR = 'error';
  const LEVEL_WARNING = 'warning';
  const LEVEL_INFO = 'info';
  const LEVEL_DEBUG = 'debug';

  /**
   * Error types
   */
  const TYPE_CRON = 'cron';
  const TYPE_DATABASE = 'database';
  const TYPE_API = 'api';
  const TYPE_EMAIL = 'email';
  const TYPE_VALIDATION = 'validation';
  const TYPE_GENERAL = 'general';

  /**
   * Log error
   * 
   * @param string $message Error message
   * @param string $type Error type (TYPE_* constants)
   * @param string $level Log level (LEVEL_* constants)
   * @param array $context Additional context data
   * @param \Exception|\WP_Error|null $exception Exception or WP_Error object
   */
  public static function log($message, $type = self::TYPE_GENERAL, $level = self::LEVEL_ERROR, $context = [], $exception = null)
  {
    // Get error details
    $error_details = self::prepare_error_details($message, $type, $level, $context, $exception);

    // Log to WordPress error log
    self::log_to_file($error_details);

    // Send email notification if error level
    if ($level === self::LEVEL_ERROR) {
      self::send_email_notification($error_details);
    }

    return $error_details;
  }

  /**
   * Log error (convenience method)
   */
  public static function error($message, $type = self::TYPE_GENERAL, $context = [], $exception = null)
  {
    return self::log($message, $type, self::LEVEL_ERROR, $context, $exception);
  }

  /**
   * Log warning (convenience method)
   */
  public static function warning($message, $type = self::TYPE_GENERAL, $context = [], $exception = null)
  {
    return self::log($message, $type, self::LEVEL_WARNING, $context, $exception);
  }

  /**
   * Log info (convenience method)
   */
  public static function info($message, $type = self::TYPE_GENERAL, $context = [])
  {
    return self::log($message, $type, self::LEVEL_INFO, $context);
  }

  /**
   * Log debug (convenience method)
   */
  public static function debug($message, $type = self::TYPE_GENERAL, $context = [])
  {
    return self::log($message, $type, self::LEVEL_DEBUG, $context);
  }

  /**
   * Prepare error details array
   */
  private static function prepare_error_details($message, $type, $level, $context, $exception)
  {
    $details = [
      'timestamp' => current_time('mysql'),
      'level' => $level,
      'type' => $type,
      'message' => $message,
      'context' => $context,
      'site_url' => home_url(),
      'wp_version' => get_bloginfo('version'),
    ];

    // Add exception details
    if ($exception instanceof \Exception) {
      $details['exception'] = [
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
        'code' => $exception->getCode(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
      ];
    } elseif ($exception instanceof \WP_Error) {
      $details['wp_error'] = [
        'code' => $exception->get_error_code(),
        'message' => $exception->get_error_message(),
        'data' => $exception->get_error_data(),
      ];
    }

    // Add request details if available
    if (isset($_SERVER['REQUEST_URI'])) {
      $details['request'] = [
        'uri' => $_SERVER['REQUEST_URI'],
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
      ];
    }

    return $details;
  }

  /**
   * Log to WordPress error log
   */
  private static function log_to_file($error_details)
  {
    $log_message = sprintf(
      '[AIO Events %s] %s - %s',
      strtoupper($error_details['level']),
      $error_details['type'],
      $error_details['message']
    );

    if (!empty($error_details['exception'])) {
      $log_message .= sprintf(
        ' | Exception: %s in %s:%d',
        $error_details['exception']['message'],
        basename($error_details['exception']['file']),
        $error_details['exception']['line']
      );
    } elseif (!empty($error_details['wp_error'])) {
      $log_message .= sprintf(
        ' | WP_Error: %s (%s)',
        $error_details['wp_error']['message'],
        $error_details['wp_error']['code']
      );
    }

    if (!empty($error_details['context'])) {
      $log_message .= ' | Context: ' . wp_json_encode($error_details['context']);
    }

    error_log($log_message);
  }

  /**
   * Send email notification
   */
  private static function send_email_notification($error_details)
  {
    $settings = get_option('aio_events_settings', []);
    $debug_email = $settings['debug_email'] ?? '';

    if (empty($debug_email) || !is_email($debug_email)) {
      return false;
    }

    $subject = sprintf(
      '[%s] AIO Events Error: %s',
      get_bloginfo('name'),
      $error_details['type']
    );

    $body = self::format_email_body($error_details);

    $headers = [
      'Content-Type: text/html; charset=UTF-8',
      'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
    ];

    return wp_mail($debug_email, $subject, $body, $headers);
  }

  /**
   * Format email body
   */
  private static function format_email_body($error_details)
  {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="UTF-8">
      <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #d63638; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .section { margin-bottom: 20px; }
        .section-title { font-weight: bold; color: #d63638; margin-bottom: 10px; }
        .detail { background: white; padding: 10px; margin: 5px 0; border-left: 3px solid #2271b1; }
        .code { background: #f0f0f0; padding: 10px; font-family: monospace; font-size: 12px; overflow-x: auto; }
        .footer { text-align: center; padding: 15px; color: #666; font-size: 12px; }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="header">
          <h2 style="margin: 0;"><?php echo esc_html__('AIO Events Error Notification', 'aio-event-solution'); ?></h2>
        </div>
        <div class="content">
          <div class="section">
            <div class="section-title"><?php echo esc_html__('Error Details', 'aio-event-solution'); ?></div>
            <div class="detail">
              <strong><?php echo esc_html__('Level:', 'aio-event-solution'); ?></strong> <?php echo esc_html(strtoupper($error_details['level'])); ?><br>
              <strong><?php echo esc_html__('Type:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['type']); ?><br>
              <strong><?php echo esc_html__('Message:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['message']); ?><br>
              <strong><?php echo esc_html__('Timestamp:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['timestamp']); ?>
            </div>
          </div>

          <?php if (!empty($error_details['exception'])): ?>
          <div class="section">
            <div class="section-title"><?php echo esc_html__('Exception Details', 'aio-event-solution'); ?></div>
            <div class="detail">
              <strong><?php echo esc_html__('Class:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['exception']['class']); ?><br>
              <strong><?php echo esc_html__('Message:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['exception']['message']); ?><br>
              <strong><?php echo esc_html__('Code:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['exception']['code']); ?><br>
              <strong><?php echo esc_html__('File:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['exception']['file']); ?><br>
              <strong><?php echo esc_html__('Line:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['exception']['line']); ?>
            </div>
            <?php if (!empty($error_details['exception']['trace'])): ?>
            <div class="code">
              <?php echo esc_html($error_details['exception']['trace']); ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($error_details['wp_error'])): ?>
          <div class="section">
            <div class="section-title"><?php echo esc_html__('WordPress Error', 'aio-event-solution'); ?></div>
            <div class="detail">
              <strong><?php echo esc_html__('Code:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['wp_error']['code']); ?><br>
              <strong><?php echo esc_html__('Message:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['wp_error']['message']); ?>
              <?php if (!empty($error_details['wp_error']['data'])): ?>
              <br><strong><?php echo esc_html__('Data:', 'aio-event-solution'); ?></strong> <?php echo esc_html(wp_json_encode($error_details['wp_error']['data'])); ?>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($error_details['context'])): ?>
          <div class="section">
            <div class="section-title"><?php echo esc_html__('Context', 'aio-event-solution'); ?></div>
            <div class="code">
              <?php echo esc_html(wp_json_encode($error_details['context'], JSON_PRETTY_PRINT)); ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($error_details['request'])): ?>
          <div class="section">
            <div class="section-title"><?php echo esc_html__('Request Details', 'aio-event-solution'); ?></div>
            <div class="detail">
              <strong><?php echo esc_html__('URI:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['request']['uri']); ?><br>
              <strong><?php echo esc_html__('Method:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['request']['method']); ?><br>
              <strong><?php echo esc_html__('IP:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['request']['ip']); ?>
            </div>
          </div>
          <?php endif; ?>

          <div class="section">
            <div class="section-title"><?php echo esc_html__('System Information', 'aio-event-solution'); ?></div>
            <div class="detail">
              <strong><?php echo esc_html__('Site URL:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['site_url']); ?><br>
              <strong><?php echo esc_html__('WordPress Version:', 'aio-event-solution'); ?></strong> <?php echo esc_html($error_details['wp_version']); ?>
            </div>
          </div>
        </div>
        <div class="footer">
          <?php echo esc_html__('This is an automated error notification from AIO Events plugin.', 'aio-event-solution'); ?>
        </div>
      </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
  }
}


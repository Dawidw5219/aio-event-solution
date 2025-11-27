<?php

namespace AIOEvents\Admin;

use Timber\Timber;
use AIOEvents\Logging\ActivityLogger;

/**
 * Activity Logs Admin Page
 */
class LogsPage
{
  /**
   * Register admin page
   */
  public static function register()
  {
    add_submenu_page(
      'edit.php?post_type=aio_event',
      __('Activity Logs', 'aio-event-solution'),
      __('Logs', 'aio-event-solution'),
      'manage_options',
      'aio-events-logs',
      [self::class, 'render']
    );

    // Handle AJAX for clearing logs
    add_action('wp_ajax_aio_events_clear_logs', [self::class, 'ajax_clear_logs']);
  }

  /**
   * Render logs page
   */
  public static function render()
  {
    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';

    // Ensure table exists
    ActivityLogger::create_table();

    // Get filters
    $type_filter = sanitize_text_field($_GET['type'] ?? '');
    $status_filter = sanitize_text_field($_GET['status'] ?? '');
    $current_page = absint($_GET['paged'] ?? 1);
    $per_page = 50;
    $offset = ($current_page - 1) * $per_page;

    $args = [
      'limit' => $per_page,
      'offset' => $offset,
      'orderby' => 'created_at',
      'order' => 'DESC',
    ];

    if (!empty($type_filter)) {
      $args['type'] = $type_filter;
    }

    if (!empty($status_filter)) {
      $args['status'] = $status_filter;
    }

    $logs = ActivityLogger::get_logs($args);
    $total_count = ActivityLogger::get_count($type_filter ?: null, $status_filter ?: null);
    $total_pages = ceil($total_count / $per_page);

    $pagination = paginate_links([
      'base' => add_query_arg('paged', '%#%'),
      'format' => '',
      'prev_text' => __('&laquo;'),
      'next_text' => __('&raquo;'),
      'total' => $total_pages,
      'current' => $current_page,
    ]);

    // Get stats
    $stats = [
      'total' => ActivityLogger::get_count(),
      'errors' => ActivityLogger::get_count(null, 'error'),
      'success' => ActivityLogger::get_count(null, 'success'),
    ];

    $context = [
      'logs' => $logs,
      'type_filter' => $type_filter,
      'status_filter' => $status_filter,
      'total_count' => $total_count,
      'total_pages' => $total_pages,
      'current_page' => $current_page,
      'pagination' => $pagination,
      'stats' => $stats,
      'datetime_format' => get_option('date_format') . ' ' . get_option('time_format'),
      'nonce' => wp_create_nonce('aio-events-admin'),
      'types' => [
        ActivityLogger::TYPE_EMAIL_SCHEDULED => __('Email Scheduled', 'aio-event-solution'),
        ActivityLogger::TYPE_EMAIL_SENT => __('Email Sent', 'aio-event-solution'),
        ActivityLogger::TYPE_EMAIL_FAILED => __('Email Failed', 'aio-event-solution'),
        ActivityLogger::TYPE_API_REQUEST => __('API Request', 'aio-event-solution'),
        ActivityLogger::TYPE_API_RESPONSE => __('API Response', 'aio-event-solution'),
        ActivityLogger::TYPE_REGISTRATION => __('Registration', 'aio-event-solution'),
        ActivityLogger::TYPE_CRON => __('Cron', 'aio-event-solution'),
        ActivityLogger::TYPE_DEBUG => __('Debug', 'aio-event-solution'),
      ],
    ];

    Timber::render('admin/pages/logs.twig', $context);
  }

  /**
   * AJAX handler for clearing logs
   */
  public static function ajax_clear_logs()
  {
    check_ajax_referer('aio-events-admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied', 'aio-event-solution')]);
    }

    require_once AIO_EVENTS_PATH . 'php/Logging/ActivityLogger.php';
    ActivityLogger::clear_logs();

    wp_send_json_success(['message' => __('Logs cleared successfully', 'aio-event-solution')]);
  }
}


<?php

namespace AIOEvents\Admin;

use Timber\Timber;

/**
 * Scheduled Emails Admin Page
 */
class ScheduledEmailsPage
{
  /**
   * Register admin page
   */
  public static function register()
  {
    add_submenu_page(
      'edit.php?post_type=aio_event',
      __('Scheduled Emails', 'aio-event-solution'),
      __('Scheduled Emails', 'aio-event-solution'),
      'manage_options',
      'aio-events-scheduled-emails',
      [self::class, 'render']
    );
  }

  /**
   * Render scheduled emails page
   */
  public static function render()
  {
    require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';

    $status_filter = sanitize_text_field($_GET['status'] ?? '');
    $current_page = absint($_GET['paged'] ?? 1);
    $per_page = 20;
    $offset = ($current_page - 1) * $per_page;

    $args = [
      'status' => !empty($status_filter) ? $status_filter : null,
      'limit' => $per_page,
      'offset' => $offset,
      'orderby' => 'scheduled_for',
      'order' => 'DESC',
    ];

    $scheduled_emails = \AIOEvents\Scheduler\EmailScheduler::get_scheduled_emails($args);
    $total_count = \AIOEvents\Scheduler\EmailScheduler::get_scheduled_count($status_filter);
    $total_pages = ceil($total_count / $per_page);

    $pagination = paginate_links([
      'base' => add_query_arg('paged', '%#%'),
      'format' => '',
      'prev_text' => __('&laquo;'),
      'next_text' => __('&raquo;'),
      'total' => $total_pages,
      'current' => $current_page,
    ]);

    $context = [
      'scheduled_emails' => $scheduled_emails,
      'status_filter' => $status_filter,
      'total_count' => $total_count,
      'total_pages' => $total_pages,
      'current_page' => $current_page,
      'pagination' => $pagination,
      'date_format' => get_option('date_format'),
      'datetime_format' => get_option('date_format') . ' ' . get_option('time_format'),
    ];

    Timber::render('admin/pages/scheduled-emails.twig', $context);
  }
}

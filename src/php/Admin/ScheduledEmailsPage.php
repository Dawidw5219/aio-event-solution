<?php

namespace AIOEvents\Admin;

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

?>
    <div class="wrap">
      <h1><?php _e('Scheduled Emails', 'aio-event-solution'); ?></h1>

      <div class="tablenav top">
        <div class="alignleft actions">
          <label for="status-filter" class="screen-reader-text"><?php _e('Filter by status', 'aio-event-solution'); ?></label>
          <select name="status" id="status-filter">
            <option value=""><?php _e('All statuses', 'aio-event-solution'); ?></option>
            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'aio-event-solution'); ?></option>
            <option value="scheduled" <?php selected($status_filter, 'scheduled'); ?>><?php _e('Scheduled', 'aio-event-solution'); ?></option>
            <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>><?php _e('Cancelled', 'aio-event-solution'); ?></option>
            <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('Error', 'aio-event-solution'); ?></option>
          </select>
          <button type="button" class="button" onclick="window.location.href = '?page=aio-events-scheduled-emails&status=' + document.getElementById('status-filter').value">
            <?php _e('Filter', 'aio-event-solution'); ?>
          </button>
        </div>

        <?php if ($total_pages > 1) : ?>
          <div class="tablenav-pages">
            <span class="displaying-num"><?php printf(__('%d items', 'aio-event-solution'), $total_count); ?></span>
            <?php
            echo paginate_links([
              'base' => add_query_arg('paged', '%#%'),
              'format' => '',
              'prev_text' => __('&laquo;'),
              'next_text' => __('&raquo;'),
              'total' => $total_pages,
              'current' => $current_page,
            ]);
            ?>
          </div>
        <?php endif; ?>
      </div>

      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th scope="col" class="manage-column column-event"><?php _e('Event', 'aio-event-solution'); ?></th>
            <th scope="col" class="manage-column column-date"><?php _e('Event Date', 'aio-event-solution'); ?></th>
            <th scope="col" class="manage-column column-scheduled"><?php _e('Scheduled For', 'aio-event-solution'); ?></th>
            <th scope="col" class="manage-column column-status"><?php _e('Status', 'aio-event-solution'); ?></th>
            <th scope="col" class="manage-column column-message-id"><?php _e('Brevo Message ID', 'aio-event-solution'); ?></th>
            <th scope="col" class="manage-column column-created"><?php _e('Created', 'aio-event-solution'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($scheduled_emails)) : ?>
            <tr>
              <td colspan="6" style="text-align: center; padding: 20px;">
                <?php _e('No scheduled emails', 'aio-event-solution'); ?>
              </td>
            </tr>
          <?php else : ?>
            <?php foreach ($scheduled_emails as $email) : ?>
              <tr>
                <td>
                  <strong>
                    <a href="<?php echo esc_url(get_edit_post_link($email['event_id'])); ?>">
                      <?php echo esc_html($email['event_title']); ?>
                    </a>
                  </strong>
                </td>
                <td><?php echo esc_html($email['event_date'] ? date_i18n(get_option('date_format'), strtotime($email['event_date'])) : '—'); ?></td>
                <td><?php echo esc_html($email['scheduled_for'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($email['scheduled_for'])) : '—'); ?></td>
                <td>
                  <?php
                  $status_class = 'status-' . esc_attr($email['status']);
                  $status_labels = [
                    'pending' => __('Pending', 'aio-event-solution'),
                    'scheduled' => __('Scheduled', 'aio-event-solution'),
                    'cancelled' => __('Cancelled', 'aio-event-solution'),
                    'failed' => __('Error', 'aio-event-solution'),
                    'sent' => __('Sent', 'aio-event-solution'),
                  ];
                  $status_label = $status_labels[$email['status']] ?? $email['status'];
                  ?>
                  <span class="<?php echo esc_attr($status_class); ?>">
                    <?php echo esc_html($status_label); ?>
                  </span>
                  <?php if ($email['status'] === 'failed' && !empty($email['error_message'])) : ?>
                    <br>
                    <small style="color: #d63638;">
                      <?php echo esc_html($email['error_message']); ?>
                    </small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($email['brevo_message_id'])) : ?>
                    <code><?php echo esc_html($email['brevo_message_id']); ?></code>
                  <?php else : ?>
                    —
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($email['created_at'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($email['created_at'])) : '—'); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <style>
        .status-pending {
          color: #dba617;
        }

        .status-scheduled {
          color: #00a32a;
        }

        .status-cancelled {
          color: #646970;
          text-decoration: line-through;
        }

        .status-failed {
          color: #d63638;
          font-weight: bold;
        }

        .status-sent {
          color: #2271b1;
        }
      </style>

    </div>
<?php
  }
}

<?php

namespace AIOEvents\Helpers;

/**
 * Brevo Variables Helper
 * Provides list of available variables for Brevo email templates
 */
class BrevoVariablesHelper
{
  /**
   * Get list of available variables for Brevo email templates
   * 
   * @return array List of variable names
   */
  public static function get_available_variables()
  {
    return [
      'event_title',
      'event_date',
      'event_time',
      'event_join_url',
      'recipient_name',
    ];
  }

  /**
   * Render available variables HTML
   * 
   * @return string HTML output
   */
  public static function render_available_variables()
  {
    $variables = self::get_available_variables();
    
    ob_start();
    ?>
    <div style="margin-top: 10px; padding: 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; margin-bottom: 15px;">
      <p style="margin: 0 0 8px 0; font-weight: 600; font-size: 12px; color: #1d2327;">
        <?php echo esc_html__('Available variables in email content:', 'aio-event-solution'); ?>
      </p>
      <p style="margin: 0 0 8px 0; font-size: 11px; color: #646970; line-height: 1.5;">
        <?php echo esc_html__('You can use these variables in Brevo email template content. Insert them in the format:', 'aio-event-solution'); ?>
      </p>
      <div style="font-size: 11px; line-height: 1.6; margin-top: 8px;">
        <?php foreach ($variables as $var) : ?>
          <code style="background: #fff; padding: 2px 6px; border: 1px solid #dcdcde; border-radius: 3px; font-size: 10px; color: #2271b1; margin-right: 4px; margin-bottom: 4px; white-space: nowrap; display: inline-block;">
            <?php echo esc_html('{{ params.' . $var . ' }}'); ?>
          </code>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }
}


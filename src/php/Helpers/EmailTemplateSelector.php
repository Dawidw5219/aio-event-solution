<?php

namespace AIOEvents\Helpers;

/**
 * Email Template Selector Helper
 * Reusable component for rendering email template selectors
 */
class EmailTemplateSelector
{
  /**
   * Render email template selector field
   * 
   * @param array $args {
   *   @type string $id Field ID
   *   @type string $name Field name (for form)
   *   @type string|int $value Current selected value
   *   @type string $description Field description
   *   @type string $empty_option_text Text for empty option (default: "Wybierz szablon")
   *   @type bool $show_global_option Show "Use global" option (default: false)
   *   @type string $global_value Global template value to show
   *   @type string $global_label Label for global option
   *   @type bool $show_variables Show available variables below field (default: false)
   * }
   * @return void
   */
  public static function render($args)
  {
    $id = $args['id'] ?? '';
    $name = $args['name'] ?? '';
    $value = $args['value'] ?? '';
    $description = $args['description'] ?? '';
    $empty_option_text = $args['empty_option_text'] ?? __('Select template', 'aio-event-solution');
    $show_global_option = $args['show_global_option'] ?? false;
    $global_value = $args['global_value'] ?? '';
    $global_label = $args['global_label'] ?? __('Use global', 'aio-event-solution');
    $show_variables = $args['show_variables'] ?? false;
    
    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new \AIOEvents\Integrations\BrevoAPI();
    $templates = $brevo->is_configured() ? $brevo->get_email_templates() : [];
    $templates_error = is_wp_error($templates);
    $template_selector_id = 'template-selector-' . $id;
    
    ?>
    <div style="max-width: 800px;">
      <?php if ($brevo->is_configured() && !$templates_error && !empty($templates)) : ?>
        <select
          id="<?php echo esc_attr($template_selector_id); ?>"
          name="<?php echo esc_attr($name); ?>"
          style="min-width: 400px; width: 100%;">
          <?php if ($show_global_option) : ?>
            <option value="" <?php selected($value, ''); ?>>
              — <?php echo esc_html($global_label); ?> —
            </option>
          <?php else : ?>
            <option value="">— <?php echo esc_html($empty_option_text); ?> —</option>
          <?php endif; ?>
          <?php foreach ($templates as $template) : ?>
            <option value="<?php echo esc_attr($template['id']); ?>" <?php selected($value, $template['id']); ?>>
              <?php echo esc_html($template['name']); ?> (ID: <?php echo esc_html($template['id']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <p class="description"><?php echo wp_kses_post($description); ?></p>
        <?php if ($show_variables) : ?>
          <?php
          require_once AIO_EVENTS_PATH . 'php/Helpers/BrevoVariablesHelper.php';
          echo \AIOEvents\Helpers\BrevoVariablesHelper::render_available_variables();
          ?>
        <?php endif; ?>
      <?php elseif ($templates_error) : ?>
        <input type="number"
          id="<?php echo esc_attr($id); ?>"
          name="<?php echo esc_attr($name); ?>"
          value="<?php echo esc_attr($value); ?>"
          class="regular-text"
          min="1"
          placeholder="123">
        <p class="description"><?php echo wp_kses_post($description); ?></p>
        <p style="color: #d63638; margin-top: 10px;">
          <?php echo esc_html($templates->get_error_message()); ?>
        </p>
        <?php if ($show_variables) : ?>
          <?php
          require_once AIO_EVENTS_PATH . 'php/Helpers/BrevoVariablesHelper.php';
          echo \AIOEvents\Helpers\BrevoVariablesHelper::render_available_variables();
          ?>
        <?php endif; ?>
      <?php else : ?>
        <input type="number"
          id="<?php echo esc_attr($id); ?>"
          name="<?php echo esc_attr($name); ?>"
          value="<?php echo esc_attr($value); ?>"
          class="regular-text"
          min="1"
          placeholder="123">
        <p class="description"><?php echo wp_kses_post($description); ?></p>
        <p style="color: #dba617; margin-top: 10px;">
          <?php _e('Configure Brevo API key to see list of templates', 'aio-event-solution'); ?>
        </p>
        <?php if ($show_variables) : ?>
          <?php
          require_once AIO_EVENTS_PATH . 'php/Helpers/BrevoVariablesHelper.php';
          echo \AIOEvents\Helpers\BrevoVariablesHelper::render_available_variables();
          ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
  }
}


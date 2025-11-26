<?php

namespace AIOEvents\Admin;

use Timber\Timber;

/**
 * Settings Page Handler
 */
class SettingsPage
{
  /**
   * Register admin menu
   */
  public static function register()
  {
    // Dodaj Settings jako submenu do natywnego menu Events
    add_submenu_page(
      'edit.php?post_type=aio_event',
      __('Settings', 'aio-event-solution'),
      __('Settings', 'aio-event-solution'),
      'manage_options',
      'aio-events-settings',
      [self::class, 'render_settings']
    );

    // Register settings
    add_action('admin_init', [self::class, 'register_settings']);
  }

  /**
   * Register settings using WordPress Settings API
   */
  public static function register_settings()
  {
    register_setting('aio_events_settings_group', 'aio_events_settings', [
      'sanitize_callback' => [self::class, 'sanitize_settings'],
    ]);

    // ============================================
    // SEKCJA 1: Ustawienia obowiązkowe (Brevo)
    // ============================================

    // Brevo Integration Section
    add_settings_section(
      'aio_events_brevo_section',
      __('Brevo Email Marketing Integration', 'aio-event-solution'),
      [self::class, 'render_brevo_section_description'],
      'aio-events-settings'
    );

    // Brevo API Key
    add_settings_field(
      'brevo_api_key',
      __('Brevo API Key', 'aio-event-solution'),
      [self::class, 'render_brevo_api_key_field'],
      'aio-events-settings',
      'aio_events_brevo_section',
      [
        'id' => 'brevo_api_key',
        'label_for' => 'brevo_api_key',
        'description' => __('Enter your Brevo API key to enable email marketing integration', 'aio-event-solution'),
      ]
    );

    // Default Brevo List
    add_settings_field(
      'default_brevo_list_id',
      __('Default Brevo List', 'aio-event-solution'),
      [self::class, 'render_brevo_list_field'],
      'aio-events-settings',
      'aio_events_brevo_section',
      [
        'id' => 'default_brevo_list_id',
        'label_for' => 'default_brevo_list_id',
        'description' => __('Default list to which users from events will be added. You can override this for each event individually.', 'aio-event-solution'),
      ]
    );

    // Email Scheduling Section
    add_settings_section(
      'aio_events_email_scheduling_section',
      __('Email Templates', 'aio-event-solution'),
      [self::class, 'render_email_scheduling_section_description'],
      'aio-events-settings'
    );

    // Email Template after registration
    add_settings_field(
      'email_template_after_registration',
      __('Template After Registration', 'aio-event-solution'),
      [self::class, 'render_email_template_field'],
      'aio-events-settings',
      'aio_events_email_scheduling_section',
      [
        'id' => 'email_template_after_registration',
        'label_for' => 'email_template_after_registration',
        'description' => __('Template sent immediately after event registration', 'aio-event-solution'),
      ]
    );

    // Email Template before event
    add_settings_field(
      'email_template_before_event',
      __('Event Reminder Template', 'aio-event-solution'),
      [self::class, 'render_email_template_field'],
      'aio-events-settings',
      'aio_events_email_scheduling_section',
      [
        'id' => 'email_template_before_event',
        'label_for' => 'email_template_before_event',
        'description' => __('Reminder template sent before the event', 'aio-event-solution'),
      ]
    );

    // Email Template join event
    add_settings_field(
      'email_template_join_event',
      __('Event Join Template', 'aio-event-solution'),
      [self::class, 'render_email_template_field'],
      'aio-events-settings',
      'aio_events_email_scheduling_section',
      [
        'id' => 'email_template_join_event',
        'label_for' => 'email_template_join_event',
        'description' => __('Template sent before event start with join link', 'aio-event-solution'),
      ]
    );

    // Email Template after event
    add_settings_field(
      'email_template_after_event',
      __('Template After Event', 'aio-event-solution'),
      [self::class, 'render_email_template_field'],
      'aio-events-settings',
      'aio_events_email_scheduling_section',
      [
        'id' => 'email_template_after_event',
        'label_for' => 'email_template_after_event',
        'description' => __('Template sent after event ends', 'aio-event-solution'),
      ]
    );

    // Email timing settings section
    add_settings_section(
      'aio_events_email_timing_section',
      __('Email Timing', 'aio-event-solution'),
      [self::class, 'render_email_timing_section_description'],
      'aio-events-settings'
    );

    // Email time before event (in minutes)
    add_settings_field(
      'email_time_before_event',
      __('Time Before Event (minutes)', 'aio-event-solution'),
      [self::class, 'render_number_field'],
      'aio-events-settings',
      'aio_events_email_timing_section',
      [
        'id' => 'email_time_before_event',
        'label_for' => 'email_time_before_event',
        'description' => __('How many minutes before event start to send reminder (default: 1440 = 24h)', 'aio-event-solution'),
        'default' => 1440,
        'min' => 1,
      ]
    );

    // Email time join event (in minutes)
    add_settings_field(
      'email_time_join_event',
      __('Time Before Join (minutes)', 'aio-event-solution'),
      [self::class, 'render_number_field'],
      'aio-events-settings',
      'aio_events_email_timing_section',
      [
        'id' => 'email_time_join_event',
        'label_for' => 'email_time_join_event',
        'description' => __('How many minutes before event start to send email with join link (default: 10)', 'aio-event-solution'),
        'default' => 10,
        'min' => 1,
      ]
    );

    // Email time after event (in minutes)
    add_settings_field(
      'email_time_after_event',
      __('Time After Event (minutes)', 'aio-event-solution'),
      [self::class, 'render_number_field'],
      'aio-events-settings',
      'aio_events_email_timing_section',
      [
        'id' => 'email_time_after_event',
        'label_for' => 'email_time_after_event',
        'description' => __('How many minutes after event ends to send email (default: 120 = 2h)', 'aio-event-solution'),
        'default' => 120,
        'min' => 1,
      ]
    );
    
    // Add a dummy field to display variables after all template fields
    add_settings_field(
      'email_template_variables_info',
      '', // Empty label
      [self::class, 'render_email_template_variables_info'],
      'aio-events-settings',
      'aio_events_email_scheduling_section',
      []
    );

    // ============================================
    // SEKCJA 2: Domyślny formularz rejestracji
    // ============================================
    
    // Global Brevo Form Section
    add_settings_section(
      'aio_events_brevo_form_section',
      __('Default Registration Form', 'aio-event-solution'),
      null,
      'aio-events-settings'
    );

    // Global Brevo Form HTML
    add_settings_field(
      'global_brevo_form_html',
      __('Global Registration Form (HTML)', 'aio-event-solution'),
      [self::class, 'render_textarea_field'],
      'aio-events-settings',
      'aio_events_brevo_form_section',
      [
        'id' => 'global_brevo_form_html',
        'label_for' => 'global_brevo_form_html',
        'description' => __('Paste Brevo form HTML code. Will be used for all events unless an event has its own form.', 'aio-event-solution'),
        'rows' => 10,
      ]
    );

    // ============================================
    // SEKCJA 3: Komunikat po zakończeniu eventu
    // ============================================
    
    // Global Post-Event Message Section
    add_settings_section(
      'aio_events_post_event_message_section',
      __('Post-Event Message', 'aio-event-solution'),
      null,
      'aio-events-settings'
    );

    // Global Post-Event Message
    add_settings_field(
      'global_post_event_message',
      __('Global Post-Event Message', 'aio-event-solution'),
      [self::class, 'render_post_event_message_field'],
      'aio-events-settings',
      'aio_events_post_event_message_section',
      [
        'id' => 'global_post_event_message',
        'label_for' => 'global_post_event_message',
        'description' => __('Default message displayed after event ends. Can be overridden for each event individually.', 'aio-event-solution'),
      ]
    );

    // ============================================
    // SEKCJA 4: Ustawienia wizualne
    // ============================================
    
    // General Settings Section
    add_settings_section(
      'aio_events_general_section',
      __('Visual Settings', 'aio-event-solution'),
      null,
      'aio-events-settings'
    );

    // Primary Color
    add_settings_field(
      'primary_color',
      __('Primary Color', 'aio-event-solution'),
      [self::class, 'render_color_field'],
      'aio-events-settings',
      'aio_events_general_section',
      [
        'id' => 'primary_color',
        'label_for' => 'primary_color',
        'description' => __('Main brand color used for buttons, links, and highlights', 'aio-event-solution'),
      ]
    );

    // Secondary Color
    add_settings_field(
      'secondary_color',
      __('Secondary Color', 'aio-event-solution'),
      [self::class, 'render_color_field'],
      'aio-events-settings',
      'aio_events_general_section',
      [
        'id' => 'secondary_color',
        'label_for' => 'secondary_color',
        'description' => __('Secondary color for backgrounds and accents', 'aio-event-solution'),
      ]
    );

    // Content Box Background
    add_settings_field(
      'content_box_background',
      __('Content Box Background', 'aio-event-solution'),
      [self::class, 'render_color_field'],
      'aio-events-settings',
      'aio_events_general_section',
      [
        'id' => 'content_box_background',
        'label_for' => 'content_box_background',
        'description' => __('Background color for event content box', 'aio-event-solution'),
      ]
    );

    // Date Format
    add_settings_field(
      'date_format',
      __('Date Format', 'aio-event-solution'),
      [self::class, 'render_date_format_field'],
      'aio-events-settings',
      'aio_events_general_section',
      [
        'id' => 'date_format',
        'label_for' => 'date_format',
        'description' => __('Choose how dates should be displayed', 'aio-event-solution'),
      ]
    );

    // ============================================
    // SEKCJA 5: Features (przełączniki)
    // ============================================

    // Features Section
    add_settings_section(
      'aio_events_features_section',
      __('Features', 'aio-event-solution'),
      null,
      'aio-events-settings'
    );

    // Events URL Slug
    add_settings_field(
      'events_slug',
      __('Events URL Slug', 'aio-event-solution'),
      [self::class, 'render_slug_field'],
      'aio-events-settings',
      'aio_events_features_section',
      [
        'id' => 'events_slug',
        'label_for' => 'events_slug',
        'description' => __('URL slug for events (e.g., "events" creates /events/event-name). After changing, go to Settings → Permalinks and click Save.', 'aio-event-solution'),
        'default' => 'events',
      ]
    );

    // ============================================
    // SEKCJA 6: Debug & Logging (na końcu)
    // ============================================
    
    // Debug & Logging Section
    add_settings_section(
      'aio_events_debug_section',
      __('Debug & Logging', 'aio-event-solution'),
      [self::class, 'render_debug_section_description'],
      'aio-events-settings'
    );

    // Debug Email
    add_settings_field(
      'debug_email',
      __('Debug Email', 'aio-event-solution'),
      [self::class, 'render_email_field'],
      'aio-events-settings',
      'aio_events_debug_section',
      [
        'id' => 'debug_email',
        'label_for' => 'debug_email',
        'description' => __('Email address to receive error notifications from cron jobs and system errors', 'aio-event-solution'),
      ]
    );

  }

  /**
   * Sanitize settings
   */
  public static function sanitize_settings($input)
  {
    $sanitized = [];

    if (isset($input['brevo_api_key'])) {
      $sanitized['brevo_api_key'] = sanitize_text_field($input['brevo_api_key']);
    }

    if (isset($input['events_slug'])) {
      $new_slug = sanitize_title($input['events_slug']);
      $sanitized['events_slug'] = !empty($new_slug) ? $new_slug : 'events';
      
      // Check if slug changed - need to flush rewrite rules
      $old_settings = get_option('aio_events_settings', []);
      $old_slug = $old_settings['events_slug'] ?? 'events';
      if ($new_slug !== $old_slug) {
        // Schedule rewrite flush on next page load
        update_option('aio_events_flush_rewrite', true);
      }
    }

    if (isset($input['date_format'])) {
      $sanitized['date_format'] = sanitize_text_field($input['date_format']);
    }

    if (isset($input['primary_color'])) {
      $sanitized['primary_color'] = sanitize_hex_color($input['primary_color']);
    }

    if (isset($input['secondary_color'])) {
      $sanitized['secondary_color'] = sanitize_hex_color($input['secondary_color']);
    }

    if (isset($input['content_box_background'])) {
      $sanitized['content_box_background'] = sanitize_hex_color($input['content_box_background']);
    }

    // Email scheduling settings
    if (isset($input['email_template_after_registration'])) {
      $sanitized['email_template_after_registration'] = absint($input['email_template_after_registration']);
    }
    if (isset($input['email_template_before_event'])) {
      $sanitized['email_template_before_event'] = absint($input['email_template_before_event']);
    }
    if (isset($input['email_template_join_event'])) {
      $sanitized['email_template_join_event'] = absint($input['email_template_join_event']);
    }
    if (isset($input['email_template_after_event'])) {
      $sanitized['email_template_after_event'] = absint($input['email_template_after_event']);
    }

    // Email timing settings (in minutes)
    if (isset($input['email_time_before_event'])) {
      $sanitized['email_time_before_event'] = absint($input['email_time_before_event']);
      if ($sanitized['email_time_before_event'] < 1) {
        $sanitized['email_time_before_event'] = 1440; // Default: 24h
      }
    }
    if (isset($input['email_time_join_event'])) {
      $sanitized['email_time_join_event'] = absint($input['email_time_join_event']);
      if ($sanitized['email_time_join_event'] < 1) {
        $sanitized['email_time_join_event'] = 10; // Default: 10 minutes
      }
    }
    if (isset($input['email_time_after_event'])) {
      $sanitized['email_time_after_event'] = absint($input['email_time_after_event']);
      if ($sanitized['email_time_after_event'] < 1) {
        $sanitized['email_time_after_event'] = 120; // Default: 2h
      }
    }

    // Post-event message
    if (isset($input['global_post_event_message'])) {
      $sanitized['global_post_event_message'] = wp_kses_post($input['global_post_event_message']);
    }

    // Global Brevo form HTML - allow full HTML for forms
    if (isset($input['global_brevo_form_html'])) {
      $sanitized['global_brevo_form_html'] = self::sanitize_brevo_form_html($input['global_brevo_form_html']);
    } else {
      // If field is not set, set empty string (will be caught by required validation)
      $sanitized['global_brevo_form_html'] = '';
    }
    
    // Default Brevo list ID
    if (isset($input['default_brevo_list_id'])) {
      $sanitized['default_brevo_list_id'] = absint($input['default_brevo_list_id']);
    }

    // Check required fields AFTER sanitization - prevent saving empty values
    $required_fields = [
      'brevo_api_key' => __('Brevo API Key', 'aio-event-solution'),
      'default_brevo_list_id' => __('Default Brevo List', 'aio-event-solution'),
      'email_template_after_registration' => __('Template After Registration', 'aio-event-solution'),
      'email_template_before_event' => __('Event Reminder Template', 'aio-event-solution'),
      'email_template_after_event' => __('Template After Event', 'aio-event-solution'),
      'global_post_event_message' => __('Global Post-Event Message', 'aio-event-solution'),
      'global_brevo_form_html' => __('Global Registration Form (HTML)', 'aio-event-solution'),
    ];
    
    $errors = [];
    foreach ($required_fields as $field_key => $field_label) {
      $value = isset($sanitized[$field_key]) ? $sanitized[$field_key] : '';
      // For numeric fields (templates, list ID), check if > 0
      if (in_array($field_key, ['email_template_after_registration', 'email_template_before_event', 'email_template_after_event', 'default_brevo_list_id'])) {
        if (empty($value) || $value <= 0) {
          $errors[] = sprintf(__('Field "%s" is required and cannot be empty.', 'aio-event-solution'), $field_label);
        }
      } else {
        // For text/html fields, check if empty after trim
        $value = is_string($value) ? trim($value) : $value;
        if (empty($value)) {
          $errors[] = sprintf(__('Field "%s" is required and cannot be empty.', 'aio-event-solution'), $field_label);
        }
      }
    }
    
    // If there are errors, show them and prevent save
    if (!empty($errors)) {
      add_settings_error(
        'aio_events_settings',
        'required_fields_empty',
        implode('<br>', $errors),
        'error'
      );
      // Return current settings instead of sanitized input
      return get_option('aio_events_settings', []);
    }

    // Debug email
    if (isset($input['debug_email'])) {
      $debug_email = sanitize_email($input['debug_email']);
      if (!empty($debug_email) && is_email($debug_email)) {
        $sanitized['debug_email'] = $debug_email;
      } else {
        $sanitized['debug_email'] = '';
      }
    }

    // Brevo forms library
    if (isset($input['brevo_forms']) && is_array($input['brevo_forms'])) {
      $forms = [];
      foreach ($input['brevo_forms'] as $form) {
        $name = isset($form['name']) ? sanitize_text_field($form['name']) : '';
        $id = isset($form['id']) ? sanitize_text_field($form['id']) : '';
        $url = isset($form['url']) ? esc_url_raw($form['url']) : '';
        $embed = isset($form['embed']) ? wp_kses_post($form['embed']) : '';
        if (empty($name) && empty($url) && empty($embed)) {
          continue;
        }
        if (empty($id)) {
          $id = substr(md5($name . '|' . $url . '|' . $embed . '|' . microtime(true)), 0, 10);
        }
        $forms[] = [
          'id' => $id,
          'name' => $name,
          'url' => $url,
          'embed' => $embed,
        ];
      }
      $sanitized['brevo_forms'] = $forms;
    }

    return $sanitized;
  }

  /**
   * Render email scheduling section description
   */
  public static function render_email_timing_section_description()
  {
    echo '<p>' . esc_html__('Configure email sending times in minutes before/after event. These settings can be overridden for each event individually.', 'aio-event-solution') . '</p>';
  }

  public static function render_email_scheduling_section_description()
  {
    require_once AIO_EVENTS_PATH . 'php/Helpers/BrevoVariablesHelper.php';
    
    echo '<p>';
    echo esc_html__('Email templates are ready-made email message patterns created in Brevo. Select templates that will be used for automatic email sending to event participants.', 'aio-event-solution');
    echo '</p>';
    echo '<p>';
    echo sprintf(
      '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
      esc_url('https://app.brevo.com/templates/listing/email'),
      esc_html__('Create or manage templates in Brevo', 'aio-event-solution')
    );
    echo '</p>';
    
    echo '<p><a href="' . esc_url(admin_url('edit.php?post_type=aio_event&page=aio-events-scheduled-emails')) . '" class="button">' . esc_html__('View Scheduled Emails', 'aio-event-solution') . '</a></p>';
  }
  
  /**
   * Render email template variables info (displayed after all template fields)
   */
  public static function render_email_template_variables_info()
  {
    require_once AIO_EVENTS_PATH . 'php/Helpers/BrevoVariablesHelper.php';
    echo \AIOEvents\Helpers\BrevoVariablesHelper::render_available_variables();
  }

  /**
   * Render post-event message field
   */
  public static function render_post_event_message_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
?>
    <div style="max-width: 800px;">
      <?php
      wp_editor($value, $args['id'], [
        'textarea_name' => 'aio_events_settings[' . $args['id'] . ']',
        'textarea_rows' => 8,
        'media_buttons' => true,
        'teeny' => false,
        'tinymce' => [
          'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,blockquote,alignleft,aligncenter,alignright,undo,redo',
        ],
      ]);
      ?>
      <p class="description">
        <?php echo esc_html($args['description']); ?>
      </p>
    </div>
  <?php
  }

  /**
   * Render email template field with template selector
   */
  public static function render_email_template_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';

    require_once AIO_EVENTS_PATH . 'php/Helpers/EmailTemplateSelector.php';
    
    \AIOEvents\Helpers\EmailTemplateSelector::render([
      'id' => $args['id'],
      'name' => 'aio_events_settings[' . $args['id'] . ']',
      'value' => $value,
      'description' => $args['description'] ?? '',
      'show_variables' => false, // Variables shown once at the end of section
    ]);
  }

  /**
   * Render Brevo section description
   */
  public static function render_brevo_section_description()
  {
    echo '<p>' . esc_html__('Connect your Brevo account to automatically add event registrants to your email lists.', 'aio-event-solution') . '</p>';
    echo '<p>' . sprintf(
      esc_html__('Get your API key from %s', 'aio-event-solution'),
      '<a href="https://app.brevo.com/settings/keys/api" target="_blank">Brevo Dashboard</a>'
    ) . '</p>';

    // Button to create registrations table
    global $wpdb;
    $table_name = $wpdb->prefix . 'aio_event_registrations';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;

    if (!$table_exists) {
      echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; margin-top: 10px;">';
      echo '<p style="margin: 0 0 10px 0;"><strong>⚠️ ' . esc_html__('Registrations table not found!', 'aio-event-solution') . '</strong></p>';
      echo '<p style="margin: 0 0 10px 0;">' . esc_html__('Click the button below to create the required database table.', 'aio-event-solution') . '</p>';
      echo '<button type="button" id="aio-create-table-btn" class="button button-secondary">' . esc_html__('Create Registrations Table', 'aio-event-solution') . '</button>';
      echo '<span id="aio-create-table-result" style="margin-left: 10px;"></span>';
      echo '</div>';
    }
  }

  /**
   * Render Brevo list field
   */
  public static function render_brevo_list_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    $api_key = $settings['brevo_api_key'] ?? '';

    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new \AIOEvents\Integrations\BrevoAPI();
    $lists = $brevo->is_configured() ? $brevo->get_lists() : [];
    $lists_error = is_wp_error($lists);
  ?>
    <div style="max-width: 600px;">
      <?php if ($brevo->is_configured() && !$lists_error && !empty($lists)) : ?>
        <select
          id="<?php echo esc_attr($args['id']); ?>"
          name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
          style="min-width: 400px; width: 100%;">
          <option value="">— <?php _e('Select list', 'aio-event-solution'); ?> —</option>
          <?php foreach ($lists as $list) : ?>
            <option value="<?php echo esc_attr($list['id']); ?>" <?php selected($value, $list['id']); ?>>
              <?php echo esc_html($list['name']); ?> (<?php echo esc_html($list['totalSubscribers'] ?? 0); ?> <?php _e('subscribers', 'aio-event-solution'); ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html($args['description']); ?></p>
      <?php elseif ($lists_error) : ?>
        <input type="number"
          id="<?php echo esc_attr($args['id']); ?>"
          name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
          value="<?php echo esc_attr($value); ?>"
          class="regular-text"
          min="1"
          placeholder="123">
        <p class="description"><?php echo esc_html($args['description']); ?></p>
        <p style="color: #d63638; margin-top: 10px;">
          <?php echo esc_html($lists->get_error_message()); ?>
        </p>
      <?php else : ?>
        <input type="number"
          id="<?php echo esc_attr($args['id']); ?>"
          name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
          value="<?php echo esc_attr($value); ?>"
          class="regular-text"
          min="1"
          placeholder="123">
        <p class="description"><?php echo esc_html($args['description']); ?></p>
        <p style="color: #dba617; margin-top: 10px;">
          <?php _e('Configure Brevo API key to see list of lists', 'aio-event-solution'); ?>
        </p>
      <?php endif; ?>
    </div>
  <?php
  }

  /**
   * Render Brevo API key field with test button
   */
  public static function render_brevo_api_key_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
  ?>
    <div style="max-width: 600px;">
      <input type="text"
        id="<?php echo esc_attr($args['id']); ?>"
        name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
        value="<?php echo esc_attr($value); ?>"
        class="large-text"
        placeholder="xkeysib-xxxxxxxxxxxx">
      <p class="description"><?php echo esc_html($args['description']); ?></p>

      <?php if (!empty($value)) : ?>
        <button type="button" id="aio-test-brevo-btn" class="button button-secondary" style="margin-top: 10px;">
          <?php _e('Test Connection', 'aio-event-solution'); ?>
        </button>
        <span id="aio-test-brevo-result" style="margin-left: 10px;"></span>
      <?php endif; ?>
    </div>

    <script>
      jQuery(document).ready(function($) {
        // Test Brevo connection
        $('#aio-test-brevo-btn').on('click', function() {
          const $btn = $(this);
          const $result = $('#aio-test-brevo-result');
          const apiKey = $('#brevo_api_key').val();

          $btn.prop('disabled', true).text('<?php _e('Testing...', 'aio-event-solution'); ?>');
          $result.html('');

          $.post(ajaxurl, {
            action: 'aio_test_brevo_connection',
            api_key: apiKey,
            nonce: '<?php echo wp_create_nonce('aio_test_brevo'); ?>'
          }, function(response) {
            if (response.success) {
              $result.html('<span style="color: #28a745;">✅ ' + response.data.message + '</span>');
            } else {
              $result.html('<span style="color: #d63638;">❌ ' + response.data.message + '</span>');
            }
          }).always(function() {
            $btn.prop('disabled', false).text('<?php _e('Test Connection', 'aio-event-solution'); ?>');
          });
        });

        // Create table
        $('#aio-create-table-btn').on('click', function() {
          const $btn = $(this);
          const $result = $('#aio-create-table-result');

          $btn.prop('disabled', true).text('<?php _e('Creating...', 'aio-event-solution'); ?>');
          $result.html('');

          $.post(ajaxurl, {
            action: 'aio_create_registrations_table',
            nonce: '<?php echo wp_create_nonce('aio_create_table'); ?>'
          }, function(response) {
            if (response.success) {
              $result.html('<span style="color: #28a745;">✅ ' + response.data.message + '</span>');
              setTimeout(function() {
                location.reload();
              }, 1500);
            } else {
              $result.html('<span style="color: #d63638;">❌ ' + response.data.message + '</span>');
              $btn.prop('disabled', false).text('<?php _e('Create Registrations Table', 'aio-event-solution'); ?>');
            }
          });
        });
      });
    </script>
  <?php
  }

  /**
   * Render Brevo forms library field (repeatable rows)
   */
  public static function render_brevo_forms_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $forms = $settings['brevo_forms'] ?? [];
  ?>
    <div style="max-width: 900px;">
      <p class="description" style="margin: 0 0 10px;">
        <?php echo esc_html($args['description']); ?>
      </p>

      <table class="widefat fixed striped">
        <thead>
          <tr>
            <th style="width: 20%;"><?php _e('Name', 'aio-event-solution'); ?></th>
            <th style="width: 25%;"><?php _e('Form URL', 'aio-event-solution'); ?></th>
            <th><?php _e('Embed Code (iframe/script)', 'aio-event-solution'); ?></th>
            <th style="width: 90px; text-align: right;">&nbsp;</th>
          </tr>
        </thead>
        <tbody id="aio-brevo-forms-rows">
          <?php if (empty($forms)) : ?>
            <tr class="aio-brevo-form-row">
              <td>
                <input type="text" name="aio_events_settings[brevo_forms][0][name]" class="regular-text" placeholder="My Registration Form">
                <input type="hidden" name="aio_events_settings[brevo_forms][0][id]" value="<?php echo esc_attr(substr(md5(microtime(true)), 0, 10)); ?>">
              </td>
              <td>
                <input type="url" name="aio_events_settings[brevo_forms][0][url]" class="regular-text" placeholder="https://my.brevo.com/...">
              </td>
              <td>
                <textarea name="aio_events_settings[brevo_forms][0][embed]" rows="2" style="width: 100%;" placeholder='&lt;iframe src="..." width="100%" height="600"&gt;&lt;/iframe&gt;'></textarea>
              </td>
              <td style="text-align: right;">
                <button type="button" class="button button-link-delete aio-remove-form-row"><?php _e('Remove', 'aio-event-solution'); ?></button>
              </td>
            </tr>
          <?php else : ?>
            <?php foreach ($forms as $index => $form) : ?>
              <tr class="aio-brevo-form-row">
                <td>
                  <input type="text" name="aio_events_settings[brevo_forms][<?php echo esc_attr($index); ?>][name]" class="regular-text" value="<?php echo esc_attr($form['name'] ?? ''); ?>">
                  <input type="hidden" name="aio_events_settings[brevo_forms][<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($form['id'] ?? ''); ?>">
                </td>
                <td>
                  <input type="url" name="aio_events_settings[brevo_forms][<?php echo esc_attr($index); ?>][url]" class="regular-text" value="<?php echo esc_attr($form['url'] ?? ''); ?>">
                </td>
                <td>
                  <textarea name="aio_events_settings[brevo_forms][<?php echo esc_attr($index); ?>][embed]" rows="2" style="width: 100%;"><?php echo esc_textarea($form['embed'] ?? ''); ?></textarea>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="button button-link-delete aio-remove-form-row"><?php _e('Remove', 'aio-event-solution'); ?></button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <p>
        <button type="button" class="button" id="aio-add-form-row"><?php _e('Add Form', 'aio-event-solution'); ?></button>
      </p>
    </div>

    <script>
      jQuery(function($) {
        const $rows = $('#aio-brevo-forms-rows');
        $('#aio-add-form-row').on('click', function() {
          const index = $rows.find('tr').length;
          const uid = (Date.now().toString(36) + Math.random().toString(36).substring(2, 8)).slice(0, 10);
          const row = `
          <tr class="aio-brevo-form-row">
            <td>
              <input type="text" name="aio_events_settings[brevo_forms][${index}][name]" class="regular-text" placeholder="My Registration Form">
              <input type="hidden" name="aio_events_settings[brevo_forms][${index}][id]" value="${uid}">
            </td>
            <td>
              <input type="url" name="aio_events_settings[brevo_forms][${index}][url]" class="regular-text" placeholder="https://my.brevo.com/...">
            </td>
            <td>
              <textarea name="aio_events_settings[brevo_forms][${index}][embed]" rows="2" style="width: 100%;" placeholder='&lt;iframe src="..." width="100%" height="600"&gt;&lt;/iframe&gt;'></textarea>
            </td>
            <td style="text-align: right;">
              <button type="button" class="button button-link-delete aio-remove-form-row"><?php _e('Remove', 'aio-event-solution'); ?></button>
            </td>
          </tr>`;
          $rows.append(row);
        });

        $rows.on('click', '.aio-remove-form-row', function() {
          $(this).closest('tr').remove();
        });
      });
    </script>
  <?php
  }

  /**
   * Render text field
   */
  public static function render_text_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    $type = $args['type'] ?? 'text';
    $class = $args['class'] ?? 'regular-text';
  ?>
    <input type="<?php echo esc_attr($type); ?>"
      id="<?php echo esc_attr($args['id']); ?>"
      name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
      value="<?php echo esc_attr($value); ?>"
      class="<?php echo esc_attr($class); ?>">
    <p class="description"><?php echo esc_html($args['description']); ?></p>
  <?php
  }

  /**
   * Render number field
   */
  public static function render_number_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $default = $args['default'] ?? 0;
    $value = $settings[$args['id']] ?? $default;
    $min = $args['min'] ?? 0;
    $max = $args['max'] ?? null;
    $class = $args['class'] ?? 'small-text';
  ?>
    <input type="number"
      id="<?php echo esc_attr($args['id']); ?>"
      name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
      value="<?php echo esc_attr($value); ?>"
      class="<?php echo esc_attr($class); ?>"
      min="<?php echo esc_attr($min); ?>"
      <?php if ($max !== null) : ?>max="<?php echo esc_attr($max); ?>"<?php endif; ?>
      step="1">
    <p class="description"><?php echo esc_html($args['description']); ?></p>
  <?php
  }

  /**
   * Render textarea field
   */
  public static function render_textarea_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    $rows = $args['rows'] ?? 5;
    $class = $args['class'] ?? 'large-text code';
  ?>
    <textarea
      id="<?php echo esc_attr($args['id']); ?>"
      name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
      class="<?php echo esc_attr($class); ?>"
      rows="<?php echo esc_attr($rows); ?>"
      style="font-family: monospace; font-size: 12px;"><?php echo esc_textarea($value); ?></textarea>
    <p class="description"><?php echo esc_html($args['description']); ?></p>
  <?php
  }

  /**
   * Render email field
   */
  public static function render_email_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    ?>
    <div>
      <input type="email"
        id="<?php echo esc_attr($args['id']); ?>"
        name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
        value="<?php echo esc_attr($value); ?>"
        class="regular-text"
        placeholder="debug@example.com">
      <p class="description"><?php echo esc_html($args['description']); ?></p>
    </div>
    <?php
  }

  /**
   * Render slug field with preview
   */
  public static function render_slug_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $default = $args['default'] ?? 'events';
    $value = $settings[$args['id']] ?? $default;
    $site_url = home_url('/');
    ?>
    <div>
      <input type="text"
        id="<?php echo esc_attr($args['id']); ?>"
        name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
        value="<?php echo esc_attr($value); ?>"
        class="regular-text"
        pattern="[a-z0-9-]+"
        placeholder="<?php echo esc_attr($default); ?>">
      <p class="description">
        <?php echo esc_html($args['description']); ?><br>
        <strong><?php esc_html_e('Preview:', 'aio-event-solution'); ?></strong>
        <code><?php echo esc_html($site_url); ?><span id="slug-preview"><?php echo esc_html($value); ?></span>/event-name</code>
      </p>
    </div>
    <script>
    jQuery(function($) {
      $('#<?php echo esc_js($args['id']); ?>').on('input', function() {
        var slug = $(this).val().toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
        $('#slug-preview').text(slug || '<?php echo esc_js($default); ?>');
      });
    });
    </script>
    <?php
  }

  /**
   * Render debug section description
   */
  public static function render_debug_section_description()
  {
    echo '<p>' . esc_html__('Configure error logging and notifications. Errors from cron jobs and system operations will be sent to the debug email address.', 'aio-event-solution') . '</p>';

    // Force Run Cron button
    $nonce = wp_create_nonce('aio-events-admin');
    echo '<div style="margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
    echo '<strong>' . esc_html__('Manual Cron Execution', 'aio-event-solution') . '</strong><br>';
    echo '<p class="description" style="margin: 5px 0 10px;">' . esc_html__('Force the email scheduler to process pending emails now instead of waiting for the next cron run.', 'aio-event-solution') . '</p>';
    echo '<button type="button" id="aio-force-cron-btn" class="button button-secondary">' . esc_html__('Force Run Cron Now', 'aio-event-solution') . '</button> ';
    echo '<span id="aio-force-cron-result" style="margin-left:10px;"></span>';
    echo '</div>';

    echo <<<SCRIPT
    <script>
    jQuery(function(\$){
      \$("#aio-force-cron-btn").on("click", function(){
        var \$btn = \$(this);
        var \$res = \$("#aio-force-cron-result");
        \$btn.prop("disabled", true);
        \$res.text("⏳ Processing...");
        \$.post(ajaxurl, { action: "aio_force_run_cron", nonce: "{$nonce}" }, function(r){
          var msg = r && r.data && r.data.message ? r.data.message : (r && r.success ? "OK" : "Error");
          \$res.text((r && r.success ? "✅ " : "❌ ") + msg);
          \$btn.prop("disabled", false);
        }).fail(function(xhr){
          \$res.text("❌ HTTP " + xhr.status);
          \$btn.prop("disabled", false);
        });
      });
    });
    </script>
SCRIPT;
  }

  /**
   * Render color field
   */
  public static function render_color_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '#2271b1';
  ?>
    <input type="color"
      id="<?php echo esc_attr($args['id']); ?>"
      name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
      value="<?php echo esc_attr($value); ?>"
      class="aio-color-picker">
    <p class="description"><?php echo esc_html($args['description']); ?></p>
  <?php
  }

  /**
   * Render checkbox field
   */
  public static function render_checkbox_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $checked = isset($settings[$args['id']]) && $settings[$args['id']];
  ?>
    <label>
      <input type="checkbox"
        id="<?php echo esc_attr($args['id']); ?>"
        name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]"
        value="1"
        <?php checked($checked, true); ?>>
      <?php echo esc_html($args['description']); ?>
    </label>
  <?php
  }

  /**
   * Render date format field
   */
  public static function render_date_format_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? 'Y-m-d';

    $formats = [
      'Y-m-d' => 'YYYY-MM-DD',
      'd/m/Y' => 'DD/MM/YYYY',
      'm/d/Y' => 'MM/DD/YYYY',
      'd.m.Y' => 'DD.MM.YYYY',
    ];
  ?>
    <select id="<?php echo esc_attr($args['id']); ?>"
      name="aio_events_settings[<?php echo esc_attr($args['id']); ?>]">
      <?php foreach ($formats as $format_value => $format_label) : ?>
        <option value="<?php echo esc_attr($format_value); ?>"
          <?php selected($value, $format_value); ?>>
          <?php echo esc_html($format_label); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <p class="description"><?php echo esc_html($args['description']); ?></p>
<?php
  }

  /**
   * Render settings page
   */
  public static function render_settings()
  {
    // Always check required settings
    $settings = get_option('aio_events_settings', []);
    $missing_fields = [];
    
    if (empty($settings['brevo_api_key'])) {
      $missing_fields[] = __('Brevo API Key', 'aio-event-solution');
    }
    if (empty($settings['default_brevo_list_id'])) {
      $missing_fields[] = __('Default Brevo List', 'aio-event-solution');
    }
    if (empty($settings['email_template_after_registration'])) {
      $missing_fields[] = __('Template After Registration', 'aio-event-solution');
    }
    if (empty($settings['email_template_before_event'])) {
      $missing_fields[] = __('Event Reminder Template', 'aio-event-solution');
    }
    if (empty($settings['email_template_after_event'])) {
      $missing_fields[] = __('Template After Event', 'aio-event-solution');
    }
    if (empty($settings['global_post_event_message'])) {
      $missing_fields[] = __('Global Post-Event Message', 'aio-event-solution');
    }
    if (empty($settings['global_brevo_form_html'])) {
      $missing_fields[] = __('Global Registration Form (HTML)', 'aio-event-solution');
    }
    
    // Check for notice from redirect (if any)
    $notice = get_transient('aio_event_settings_required_notice');
    $show_notice = false;
    
    if ($notice) {
      $show_notice = true;
      delete_transient('aio_event_settings_required_notice');
    }
    
    // If there are missing fields, always show notice
    if (!empty($missing_fields) && !$show_notice) {
      $show_notice = true;
      $notice = __('Cannot add event. Please fill in the following required fields:', 'aio-event-solution');
    }
    
    $context = [
      'page_title' => __('Event Settings', 'aio-event-solution'),
      'page_slug' => 'aio-events-settings',
      'required_notice' => $show_notice ? $notice : null,
      'missing_fields' => $missing_fields,
    ];

    Timber::render('admin/settings.twig', $context);
  }

  /**
   * Sanitize Brevo form HTML
   * For Brevo forms, we skip sanitization since:
   * 1. Only administrators can fill this field
   * 2. Brevo is a trusted source
   * 3. Form must work fully with all scripts and styles
   * We only balance tags for HTML structure safety
   */
  private static function sanitize_brevo_form_html($html)
  {
    if (empty($html)) {
      return '';
    }
    
    // No sanitization - return HTML exactly as-is
    // This preserves all scripts, styles, and form elements exactly as Brevo provides them
    return $html;
  }
}

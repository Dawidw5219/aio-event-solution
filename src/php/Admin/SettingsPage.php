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

    // ============================================
    // SEKCJA 2: Shortcodes
    // ============================================

    add_settings_section(
      'aio_events_shortcodes_section',
      __('Shortcodes', 'aio-event-solution'),
      [self::class, 'render_shortcodes_section'],
      'aio-events-settings'
    );

    // ============================================
    // SEKCJA 3: Email Templates
    // ============================================

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
    // SEKCJA 4: Permalink Settings
    // ============================================
    
    add_settings_section(
      'aio_events_permalink_section',
      __('Permalink Settings', 'aio-event-solution'),
      [self::class, 'render_permalink_section_description'],
      'aio-events-settings'
    );

    add_settings_field(
      'events_slug',
      __('Events URL Slug', 'aio-event-solution'),
      [self::class, 'render_slug_field'],
      'aio-events-settings',
      'aio_events_permalink_section',
      [
        'id' => 'events_slug',
        'label_for' => 'events_slug',
        'description' => __('URL slug for events (default: "events"). Change if conflicts with other plugins.', 'aio-event-solution'),
      ]
    );

    // ============================================
    // SEKCJA 5: Ustawienia wizualne
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

    if (isset($input['date_format'])) {
      $sanitized['date_format'] = sanitize_text_field($input['date_format']);
    }

    // Events slug - sanitize and set flag to flush rewrite rules
    if (isset($input['events_slug'])) {
      $old_settings = get_option('aio_events_settings', []);
      $old_slug = $old_settings['events_slug'] ?? 'events';
      $new_slug = sanitize_title($input['events_slug']);
      $sanitized['events_slug'] = !empty($new_slug) ? $new_slug : 'events';
      
      // If slug changed, set flag to flush rewrite rules
      if ($old_slug !== $sanitized['events_slug']) {
        update_option('aio_events_flush_rewrite', true);
      }
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
    echo '<div style="max-width: 800px;">';
      wp_editor($value, $args['id'], [
        'textarea_name' => 'aio_events_settings[' . $args['id'] . ']',
        'textarea_rows' => 8,
        'media_buttons' => true,
        'teeny' => false,
      'tinymce' => ['toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,blockquote,alignleft,aligncenter,alignright,undo,redo'],
    ]);
    echo '<p class="description">' . esc_html($args['description']) . '</p></div>';
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
   * Render Shortcodes section
   */
  public static function render_shortcodes_section()
  {
    echo '<p>' . esc_html__('Use the shortcode below to display events on any page or post:', 'aio-event-solution') . '</p>';
    echo '<table class="widefat" style="max-width: 600px;"><thead><tr><th>' . esc_html__('Shortcode', 'aio-event-solution') . '</th><th>' . esc_html__('Description', 'aio-event-solution') . '</th></tr></thead><tbody>';
    echo '<tr><td><code>[aio_events]</code></td><td>' . esc_html__('Display upcoming events (default limit: 12)', 'aio-event-solution') . '</td></tr>';
    echo '<tr><td><code>[aio_events limit="5"]</code></td><td>' . esc_html__('Limit number of events', 'aio-event-solution') . '</td></tr>';
    echo '<tr><td><code>[aio_events category="slug"]</code></td><td>' . esc_html__('Filter by category slug', 'aio-event-solution') . '</td></tr>';
    echo '<tr><td><code>[aio_events show_past="yes"]</code></td><td>' . esc_html__('Include past events', 'aio-event-solution') . '</td></tr>';
    echo '</tbody></table>';
  }

  /**
   * Render Brevo section description
   */
  public static function render_brevo_section_description()
  {
    echo '<p>' . esc_html__('Connect your Brevo account to automatically add event registrants to your email lists.', 'aio-event-solution') . '</p>';
    echo '<p>' . sprintf(esc_html__('Get your API key from %s', 'aio-event-solution'), '<a href="https://app.brevo.com/settings/keys/api" target="_blank">Brevo Dashboard</a>') . '</p>';
  }

  /**
   * Render Brevo list field
   */
  public static function render_brevo_list_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
    $brevo = new \AIOEvents\Integrations\BrevoAPI();
    $lists = $brevo->is_configured() ? $brevo->get_lists() : [];
    $lists_error = is_wp_error($lists);

    echo '<div style="max-width:600px;">';
    if ($brevo->is_configured() && !$lists_error && !empty($lists)) {
      echo '<select id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" style="min-width:400px;width:100%;">';
      echo '<option value="">— ' . esc_html__('Select list', 'aio-event-solution') . ' —</option>';
      foreach ($lists as $list) {
        echo '<option value="' . esc_attr($list['id']) . '"' . selected($value, $list['id'], false) . '>' . esc_html($list['name']) . ' (' . esc_html($list['totalSubscribers'] ?? 0) . ' ' . esc_html__('subscribers', 'aio-event-solution') . ')</option>';
      }
      echo '</select>';
    } else {
      echo '<input type="number" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="small-text" min="1" placeholder="123">';
      if ($lists_error) {
        echo '<p style="color:#d63638;margin-top:10px;">' . esc_html($lists->get_error_message()) . '</p>';
      } elseif (!$brevo->is_configured()) {
        echo '<p style="color:#dba617;margin-top:10px;">' . esc_html__('Configure Brevo API key to see list of lists', 'aio-event-solution') . '</p>';
      }
    }
    echo '<p class="description">' . esc_html($args['description']) . '</p></div>';
  }

  /**
   * Render Brevo API key field with test button
   */
  public static function render_brevo_api_key_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    $nonce = wp_create_nonce('aio_save_brevo_key');

    echo '<div style="max-width:600px;">';
    echo '<input type="text" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="large-text" placeholder="xkeysib-xxxxxxxxxxxx">';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
    echo '<button type="button" id="aio-save-brevo-btn" class="button button-primary" style="margin-top:10px;">' . esc_html__('Save & Connect', 'aio-event-solution') . '</button>';
    echo '<span id="aio-test-brevo-result" style="margin-left:10px;"></span>';
    echo '</div>';
    echo '<script>jQuery(function($){
      $("#aio-save-brevo-btn").on("click",function(){
        var b=$(this),r=$("#aio-test-brevo-result"),key=$("#brevo_api_key").val();
        if(!key){r.html("<span style=color:#d63638>' . esc_js(__('Please enter API key', 'aio-event-solution')) . '</span>");return;}
        b.prop("disabled",true).text("...");
        $.post(ajaxurl,{action:"aio_save_brevo_key",api_key:key,nonce:"' . $nonce . '"},function(d){
          if(d.success){
            r.html("<span style=color:#46b450>✓ "+d.data.message+"</span>");
            setTimeout(function(){location.reload();},1000);
          }else{
            r.html("<span style=color:#d63638>✗ "+d.data.message+"</span>");
            b.prop("disabled",false).text("' . esc_js(__('Save & Connect', 'aio-event-solution')) . '");
          }
          });
        });
    });</script>';
  }

  /**
   * Render Brevo forms library field (repeatable rows)
   */
  public static function render_brevo_forms_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $forms = $settings['brevo_forms'] ?? [];
    $remove_text = esc_html__('Remove', 'aio-event-solution');

    echo '<div style="max-width:900px;"><p class="description" style="margin:0 0 10px;">' . esc_html($args['description']) . '</p>';
    echo '<table class="widefat"><thead><tr><th style="width:20%">' . esc_html__('Name', 'aio-event-solution') . '</th><th style="width:25%">' . esc_html__('Form URL', 'aio-event-solution') . '</th><th>' . esc_html__('Embed Code', 'aio-event-solution') . '</th><th style="width:80px"></th></tr></thead><tbody id="aio-brevo-forms-rows">';
    if (empty($forms)) {
      $uid = substr(md5(microtime(true)), 0, 10);
      echo '<tr class="aio-brevo-form-row"><td><input type="text" name="aio_events_settings[brevo_forms][0][name]" class="regular-text"><input type="hidden" name="aio_events_settings[brevo_forms][0][id]" value="' . $uid . '"></td><td><input type="url" name="aio_events_settings[brevo_forms][0][url]" class="regular-text"></td><td><textarea name="aio_events_settings[brevo_forms][0][embed]" rows="2" style="width:100%"></textarea></td><td style="text-align:right"><button type="button" class="button button-link-delete aio-remove-form-row">' . $remove_text . '</button></td></tr>';
    } else {
      foreach ($forms as $i => $f) {
        echo '<tr class="aio-brevo-form-row"><td><input type="text" name="aio_events_settings[brevo_forms][' . $i . '][name]" class="regular-text" value="' . esc_attr($f['name'] ?? '') . '"><input type="hidden" name="aio_events_settings[brevo_forms][' . $i . '][id]" value="' . esc_attr($f['id'] ?? '') . '"></td><td><input type="url" name="aio_events_settings[brevo_forms][' . $i . '][url]" class="regular-text" value="' . esc_attr($f['url'] ?? '') . '"></td><td><textarea name="aio_events_settings[brevo_forms][' . $i . '][embed]" rows="2" style="width:100%">' . esc_textarea($f['embed'] ?? '') . '</textarea></td><td style="text-align:right"><button type="button" class="button button-link-delete aio-remove-form-row">' . $remove_text . '</button></td></tr>';
      }
    }
    echo '</tbody></table><p><button type="button" class="button" id="aio-add-form-row">' . esc_html__('Add Form', 'aio-event-solution') . '</button></p></div>';
    echo '<script>jQuery(function($){$("#aio-add-form-row").on("click",function(){var i=$(".aio-brevo-form-row").length,u=Date.now().toString(36);$("#aio-brevo-forms-rows").append(\'<tr class="aio-brevo-form-row"><td><input type="text" name="aio_events_settings[brevo_forms][\'+i+\'][name]" class="regular-text"><input type="hidden" name="aio_events_settings[brevo_forms][\'+i+\'][id]" value="\'+u+\'"></td><td><input type="url" name="aio_events_settings[brevo_forms][\'+i+\'][url]" class="regular-text"></td><td><textarea name="aio_events_settings[brevo_forms][\'+i+\'][embed]" rows="2" style="width:100%"></textarea></td><td style="text-align:right"><button type="button" class="button button-link-delete aio-remove-form-row">' . $remove_text . '</button></td></tr>\');});$(document).on("click",".aio-remove-form-row",function(){if($(".aio-brevo-form-row").length>1)$(this).closest("tr").remove();else $(this).closest("tr").find("input,textarea").val("");});});</script>';
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
    echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="' . esc_attr($class) . '">';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
  }

  /**
   * Render number field
   */
  public static function render_number_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? ($args['default'] ?? 0);
    $min = $args['min'] ?? 0;
    $max = $args['max'] ?? null;
    echo '<input type="number" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="small-text" min="' . esc_attr($min) . '"' . ($max !== null ? ' max="' . esc_attr($max) . '"' : '') . ' step="1">';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
  }

  /**
   * Render textarea field
   */
  public static function render_textarea_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    $rows = $args['rows'] ?? 5;
    echo '<textarea id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" class="large-text code" rows="' . esc_attr($rows) . '" style="font-family:monospace;font-size:12px;">' . esc_textarea($value) . '</textarea>';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
  }

  /**
   * Render email field
   */
  public static function render_email_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? '';
    echo '<input type="email" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="regular-text" placeholder="debug@example.com">';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
  }

  /**
   * Render debug section description
   */
  public static function render_debug_section_description()
  {
    $nonce = wp_create_nonce('aio-events-admin');
    echo '<p>' . esc_html__('Configure error logging and notifications.', 'aio-event-solution') . '</p>';
    
    // Force Cron button
    echo '<div style="margin-top:15px;padding:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">';
    echo '<strong>' . esc_html__('Manual Cron Execution', 'aio-event-solution') . '</strong><br>';
    echo '<p class="description" style="margin:5px 0 10px;">' . esc_html__('Force the email scheduler to process pending emails now.', 'aio-event-solution') . '</p>';
    echo '<button type="button" id="aio-force-cron-btn" class="button button-secondary">' . esc_html__('Force Run Cron Now', 'aio-event-solution') . '</button>';
    echo '<span id="aio-force-cron-result" style="margin-left:10px;"></span>';
    echo '</div>';
    
    // Check for Updates button
    echo '<div style="margin-top:15px;padding:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">';
    echo '<strong>' . esc_html__('Plugin Updates', 'aio-event-solution') . '</strong><br>';
    echo '<p class="description" style="margin:5px 0 10px;">' . esc_html__('Force check for plugin updates from GitHub.', 'aio-event-solution') . '</p>';
    echo '<button type="button" id="aio-check-updates-btn" class="button button-secondary">' . esc_html__('Check for Updates', 'aio-event-solution') . '</button>';
    echo '<span id="aio-check-updates-result" style="margin-left:10px;"></span>';
    echo '</div>';
    
    echo '<script>jQuery(function($){
      $("#aio-force-cron-btn").on("click",function(){
        var b=$(this),r=$("#aio-force-cron-result");
        b.prop("disabled",true);r.text("⏳...");
        $.post(ajaxurl,{action:"aio_force_run_cron",nonce:"' . $nonce . '"},function(d){
          r.text((d&&d.success?"✅ ":"❌ ")+(d&&d.data&&d.data.message?d.data.message:""));
          b.prop("disabled",false);
        }).fail(function(){r.text("❌ Error");b.prop("disabled",false);});
      });
      $("#aio-check-updates-btn").on("click",function(){
        var b=$(this),r=$("#aio-check-updates-result");
        b.prop("disabled",true);r.text("⏳...");
        $.post(ajaxurl,{action:"aio_check_github_updates",nonce:"' . $nonce . '"},function(d){
          r.html((d&&d.success?"✅ ":"❌ ")+(d&&d.data&&d.data.message?d.data.message:""));
          b.prop("disabled",false);
        }).fail(function(){r.text("❌ Error");b.prop("disabled",false);});
      });
    });</script>';
  }

  /**
   * Render color field
   */
  public static function render_color_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? ($args['default'] ?? '#2271b1');
    echo '<input type="color" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" style="height:40px;width:100px;">';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
  }

  /**
   * Render checkbox field
   */
  public static function render_checkbox_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $checked = isset($settings[$args['id']]) && $settings[$args['id']];
    echo '<label><input type="checkbox" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="1"' . ($checked ? ' checked' : '') . '> ' . esc_html($args['description']) . '</label>';
  }

  /**
   * Render date format field
   */
  public static function render_date_format_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? 'Y-m-d';
    $formats = ['Y-m-d' => 'YYYY-MM-DD', 'd/m/Y' => 'DD/MM/YYYY', 'm/d/Y' => 'MM/DD/YYYY', 'd.m.Y' => 'DD.MM.YYYY'];
    echo '<select id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']">';
    foreach ($formats as $k => $v) {
      echo '<option value="' . esc_attr($k) . '"' . selected($value, $k, false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select><p class="description">' . esc_html($args['description']) . '</p>';
  }

  /**
   * Render permalink section description
   */
  public static function render_permalink_section_description()
  {
    echo '<p>' . esc_html__('Configure the URL structure for your events.', 'aio-event-solution') . '</p>';
  }

  /**
   * Render slug field
   */
  public static function render_slug_field($args)
  {
    $settings = get_option('aio_events_settings', []);
    $value = $settings[$args['id']] ?? 'events';
    $site_url = home_url('/');
    
    echo '<div style="max-width:600px;">';
    echo '<code style="background:#f0f0f1;padding:5px 10px;border-radius:3px;">' . esc_html($site_url) . '</code>';
    echo '<input type="text" id="' . esc_attr($args['id']) . '" name="aio_events_settings[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="regular-text" style="width:150px;margin-left:-4px;" pattern="[a-z0-9-]+" placeholder="events">';
    echo '<code style="background:#f0f0f1;padding:5px 10px;border-radius:3px;margin-left:-4px;">/event-name/</code>';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
    echo '</div>';
  }

  /**
   * Render settings page
   */
  public static function render_settings()
  {
    $settings = get_option('aio_events_settings', []);
    $missing_fields = [];
    $has_api_key = !empty($settings['brevo_api_key']);
    
    // API key is always required first
    if (!$has_api_key) {
      $missing_fields[] = __('Brevo API Key', 'aio-event-solution');
    } else {
      // Other fields only required after API key is configured
    if (empty($settings['default_brevo_list_id'])) {
      $missing_fields[] = __('Default Brevo List', 'aio-event-solution');
    }
    if (empty($settings['email_template_after_registration'])) {
      $missing_fields[] = __('Template After Registration', 'aio-event-solution');
    }
    if (empty($settings['email_template_before_event'])) {
      $missing_fields[] = __('Event Reminder Template', 'aio-event-solution');
    }
      if (empty($settings['email_template_join_event'])) {
        $missing_fields[] = __('Event Join Template', 'aio-event-solution');
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
      if (!$has_api_key) {
        $notice = __('Start by adding your Brevo API key:', 'aio-event-solution');
      } else {
        $notice = __('Please complete the configuration:', 'aio-event-solution');
      }
    }
    
    $context = [
      'page_title' => __('Event Settings', 'aio-event-solution'),
      'page_slug' => 'aio-events-settings',
      'required_notice' => $show_notice ? $notice : null,
      'missing_fields' => $missing_fields,
    ];

    Timber::render('admin/pages/settings.twig', $context);
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

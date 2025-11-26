<?php

namespace AIOEvents\MetaBoxes;

/**
 * Brevo Integration Meta Box
 * Handles Brevo lists, email templates, and form integration
 */
class BrevoIntegrationMetaBox
{
  /**
   * Register meta box
   */
  public static function register()
  {
    add_action('add_meta_boxes', [self::class, 'add_meta_box']);
    add_action('add_meta_boxes_aio_event', [self::class, 'add_meta_box']);
    add_action('save_post_aio_event', [self::class, 'save'], 10, 1);
  }

  /**
   * Add meta box to event edit screen
   */
  public static function add_meta_box($post_type, $post = null)
  {
    // Ensure we're on the correct post type
    if ($post_type !== 'aio_event') {
      return;
    }

    add_meta_box(
      'aio_event_brevo_integration',
      __('Brevo Integration', 'aio-event-solution'),
      [self::class, 'render'],
      'aio_event',
      'normal',
      'core' // Appears after Event Details (high), before Event Registrations (low)
    );
  }

  /**
   * Render meta box content
   */
  public static function render($post)
  {
    $settings = get_option('aio_events_settings', []);
    $api_key = $settings['brevo_api_key'] ?? '';
    $global_default_list_id = $settings['default_brevo_list_id'] ?? '';

    // Get event-specific list ID (single value, not array)
    $event_list_id = get_post_meta($post->ID, '_aio_event_brevo_list_id', true);

    // Check if event has its own list set
    $has_event_list = metadata_exists('post', $post->ID, '_aio_event_brevo_list_id');

    // If event doesn't have its own list, use global default
    if (!$has_event_list) {
      $event_list_id = $global_default_list_id;
    }

    // If this is a new event (auto-draft), copy global settings to event meta
    if ($post->post_status === 'auto-draft') {
      $global_template_after_registration = $settings['email_template_after_registration'] ?? '';
      $global_template_before_event = $settings['email_template_before_event'] ?? '';
      $global_template_after_event = $settings['email_template_after_event'] ?? '';
      $global_form_html = $settings['global_brevo_form_html'] ?? '';

      // Copy email templates if not already set
      if (!metadata_exists('post', $post->ID, '_aio_event_email_template_after_registration') && !empty($global_template_after_registration)) {
        update_post_meta($post->ID, '_aio_event_email_template_after_registration', absint($global_template_after_registration));
      }
      if (!metadata_exists('post', $post->ID, '_aio_event_email_template_before_event') && !empty($global_template_before_event)) {
        update_post_meta($post->ID, '_aio_event_email_template_before_event', absint($global_template_before_event));
      }
      if (!metadata_exists('post', $post->ID, '_aio_event_email_template_after_event') && !empty($global_template_after_event)) {
        update_post_meta($post->ID, '_aio_event_email_template_after_event', absint($global_template_after_event));
      }

      // Copy form HTML if not already set
      if (!metadata_exists('post', $post->ID, '_aio_event_brevo_form_embed') && !empty($global_form_html)) {
        $sanitized_global = self::sanitize_brevo_form_html($global_form_html);
        if (!empty($sanitized_global)) {
          update_post_meta($post->ID, '_aio_event_brevo_form_embed', $sanitized_global);
        }
      }
    }

    // Initialize data arrays
    $lists = [];
    $templates = [];
    $lists_error = null;
    $templates_error = null;

    if (!empty($api_key)) {
      require_once AIO_EVENTS_PATH . 'php/Integrations/BrevoAPI.php';
      $brevo = new \AIOEvents\Integrations\BrevoAPI($api_key);
      $lists_result = $brevo->get_lists();
      $templates_result = $brevo->get_email_templates();

      if (is_wp_error($lists_result)) {
        $lists_error = $lists_result->get_error_message();
      } else {
        $lists = is_array($lists_result) ? $lists_result : [];
        // Ensure all list items are arrays (not objects) for Twig, and IDs are integers
        $lists = array_map(function ($list) {
          $list_array = is_array($list) ? $list : (array) $list;
          if (isset($list_array['id'])) {
            $list_array['id'] = intval($list_array['id']);
          }
          return $list_array;
        }, $lists);
      }

      if (is_wp_error($templates_result)) {
        $templates_error = $templates_result->get_error_message();
      } else {
        $templates = is_array($templates_result) ? $templates_result : [];
        // Ensure all template items are arrays (not objects) for Twig
        $templates = array_map(function ($template) {
          return is_array($template) ? $template : (array) $template;
        }, $templates);
      }
    }

    // Email templates - check if event has its own (meta exists)
    $event_template_after_registration = get_post_meta($post->ID, '_aio_event_email_template_after_registration', true);
    $event_template_before_event = get_post_meta($post->ID, '_aio_event_email_template_before_event', true);
    $event_template_join_event = get_post_meta($post->ID, '_aio_event_email_template_join_event', true);
    $event_template_after_event = get_post_meta($post->ID, '_aio_event_email_template_after_event', true);

    // Check if meta exists (not just empty value - could be empty string)
    $has_template_after_registration = metadata_exists('post', $post->ID, '_aio_event_email_template_after_registration');
    $has_template_before_event = metadata_exists('post', $post->ID, '_aio_event_email_template_before_event');
    $has_template_join_event = metadata_exists('post', $post->ID, '_aio_event_email_template_join_event');
    $has_template_after_event = metadata_exists('post', $post->ID, '_aio_event_email_template_after_event');

    $global_template_after_registration = $settings['email_template_after_registration'] ?? '';
    $global_template_before_event = $settings['email_template_before_event'] ?? '';
    $global_template_join_event = $settings['email_template_join_event'] ?? '';
    $global_template_after_event = $settings['email_template_after_event'] ?? '';

    // If event doesn't have its own template saved, use empty to show "— Użyj globalnego —"
    // The global will be copied on save
    if (!$has_template_after_registration) {
      $event_template_after_registration = '';
    }
    if (!$has_template_before_event) {
      $event_template_before_event = '';
    }
    if (!$has_template_join_event) {
      $event_template_join_event = '';
    }
    if (!$has_template_after_event) {
      $event_template_after_event = '';
    }

    // Form HTML - always use event's own copy (which is copied from global during creation)
    $brevo_form_embed_raw = get_post_meta($post->ID, '_aio_event_brevo_form_embed', true);
    $global_form_html = $settings['global_brevo_form_html'] ?? '';

    // Always use event's own form (never reference global directly)
    // If event doesn't have its own yet, it will be empty (should be copied during auto-draft)
    $brevo_form_embed = $brevo_form_embed_raw ?? '';

    // Escape for display in textarea (escapes HTML so it shows as text, not rendered)
    $brevo_form_embed = esc_textarea($brevo_form_embed);

    // Available variables
    require_once AIO_EVENTS_PATH . 'php/Helpers/BrevoVariablesHelper.php';
    $available_variables = \AIOEvents\Helpers\BrevoVariablesHelper::get_available_variables();

    // Prepare context for Twig
    $context = [
      'api_key' => $api_key,
      'event_list_id' => $event_list_id,
      'has_event_list' => $has_event_list,
      'global_default_list_id' => $global_default_list_id,
      'lists' => $lists,
      'lists_error' => $lists_error,
      'templates' => $templates,
      'templates_error' => $templates_error,
      'event_template_after_registration' => $event_template_after_registration,
      'event_template_before_event' => $event_template_before_event,
      'event_template_join_event' => $event_template_join_event,
      'event_template_after_event' => $event_template_after_event,
      'global_template_after_registration' => $global_template_after_registration,
      'global_template_before_event' => $global_template_before_event,
      'global_template_join_event' => $global_template_join_event,
      'global_template_after_event' => $global_template_after_event,
      'brevo_form_embed' => $brevo_form_embed,
      'global_form_html' => $global_form_html,
      'available_variables' => $available_variables,
      'post_id' => $post->ID,
    ];

    // Render using Timber/Twig
    \Timber\Timber::render('admin/meta-boxes/brevo-integration.twig', $context);
  }

  /**
   * Save meta box data
   */
  public static function save($post_id)
  {
    // Check nonce
    if (
      !isset($_POST['aio_event_brevo_integration_nonce']) ||
      !wp_verify_nonce($_POST['aio_event_brevo_integration_nonce'], 'aio_event_brevo_integration_nonce')
    ) {
      return;
    }

    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    // Save Brevo list ID (single value)
    $settings = get_option('aio_events_settings', []);
    $global_default_list_id = $settings['default_brevo_list_id'] ?? '';

    if (isset($_POST['aio_event_brevo_list_id'])) {
      $list_id = absint($_POST['aio_event_brevo_list_id']);
      if (!empty($list_id)) {
        // User selected a specific list
        update_post_meta($post_id, '_aio_event_brevo_list_id', $list_id);
      } else {
        // User left empty - check if event already has a list
        $existing_list = get_post_meta($post_id, '_aio_event_brevo_list_id', true);
        if (empty($existing_list) && !empty($global_default_list_id)) {
          // Event doesn't have its own, copy global list (snapshot at save time)
          update_post_meta($post_id, '_aio_event_brevo_list_id', absint($global_default_list_id));
        } elseif (empty($existing_list)) {
          // No list selected and no global list
          delete_post_meta($post_id, '_aio_event_brevo_list_id');
        }
        // If existing_list exists, keep it (don't overwrite with empty)
      }
    } else {
      // Field not set - if event doesn't have its own, copy from global
      $existing_list = get_post_meta($post_id, '_aio_event_brevo_list_id', true);
      if (empty($existing_list) && !empty($global_default_list_id)) {
        update_post_meta($post_id, '_aio_event_brevo_list_id', absint($global_default_list_id));
      }
    }

    // Clean up old meta key if it exists (migration from multiple to single)
    delete_post_meta($post_id, '_aio_event_brevo_list_ids');

    // Get global settings for copying email templates
    $global_template_after_registration = $settings['email_template_after_registration'] ?? '';
    $global_template_before_event = $settings['email_template_before_event'] ?? '';
    $global_template_join_event = $settings['email_template_join_event'] ?? '';
    $global_template_after_event = $settings['email_template_after_event'] ?? '';
    $global_form_html = $settings['global_brevo_form_html'] ?? '';

    // Save email template IDs - copy global if empty and event doesn't have its own yet
    if (isset($_POST['aio_event_email_template_after_registration'])) {
      $template_id = absint($_POST['aio_event_email_template_after_registration']);
      if (!empty($template_id)) {
        // User selected a specific template
        update_post_meta($post_id, '_aio_event_email_template_after_registration', $template_id);
      } else {
        // User left empty - check if event already has a template
        $existing_template = get_post_meta($post_id, '_aio_event_email_template_after_registration', true);
        if (empty($existing_template) && !empty($global_template_after_registration)) {
          // Event doesn't have its own, copy global template (snapshot at save time)
          update_post_meta($post_id, '_aio_event_email_template_after_registration', absint($global_template_after_registration));
        } elseif (empty($existing_template)) {
          // No template selected and no global template
          delete_post_meta($post_id, '_aio_event_email_template_after_registration');
        }
        // If existing_template exists, keep it (don't overwrite with empty)
      }
    } else {
      // Field not set - if event doesn't have its own, copy from global
      $existing_template = get_post_meta($post_id, '_aio_event_email_template_after_registration', true);
      if (empty($existing_template) && !empty($global_template_after_registration)) {
        update_post_meta($post_id, '_aio_event_email_template_after_registration', absint($global_template_after_registration));
      }
    }

    if (isset($_POST['aio_event_email_template_before_event'])) {
      $template_id = absint($_POST['aio_event_email_template_before_event']);
      if (!empty($template_id)) {
        update_post_meta($post_id, '_aio_event_email_template_before_event', $template_id);
      } else {
        $existing_template = get_post_meta($post_id, '_aio_event_email_template_before_event', true);
        if (empty($existing_template) && !empty($global_template_before_event)) {
          update_post_meta($post_id, '_aio_event_email_template_before_event', absint($global_template_before_event));
        } elseif (empty($existing_template)) {
          delete_post_meta($post_id, '_aio_event_email_template_before_event');
        }
      }
    } else {
      $existing_template = get_post_meta($post_id, '_aio_event_email_template_before_event', true);
      if (empty($existing_template) && !empty($global_template_before_event)) {
        update_post_meta($post_id, '_aio_event_email_template_before_event', absint($global_template_before_event));
      }
    }

    if (isset($_POST['aio_event_email_template_join_event'])) {
      $template_id = absint($_POST['aio_event_email_template_join_event']);
      if (!empty($template_id)) {
        update_post_meta($post_id, '_aio_event_email_template_join_event', $template_id);
      } else {
        $existing_template = get_post_meta($post_id, '_aio_event_email_template_join_event', true);
        if (empty($existing_template) && !empty($global_template_join_event)) {
          update_post_meta($post_id, '_aio_event_email_template_join_event', absint($global_template_join_event));
        } elseif (empty($existing_template)) {
          delete_post_meta($post_id, '_aio_event_email_template_join_event');
        }
      }
    } else {
      $existing_template = get_post_meta($post_id, '_aio_event_email_template_join_event', true);
      if (empty($existing_template) && !empty($global_template_join_event)) {
        update_post_meta($post_id, '_aio_event_email_template_join_event', absint($global_template_join_event));
      }
    }

    if (isset($_POST['aio_event_email_template_after_event'])) {
      $template_id = absint($_POST['aio_event_email_template_after_event']);
      if (!empty($template_id)) {
        update_post_meta($post_id, '_aio_event_email_template_after_event', $template_id);
      } else {
        $existing_template = get_post_meta($post_id, '_aio_event_email_template_after_event', true);
        if (empty($existing_template) && !empty($global_template_after_event)) {
          update_post_meta($post_id, '_aio_event_email_template_after_event', absint($global_template_after_event));
        } elseif (empty($existing_template)) {
          delete_post_meta($post_id, '_aio_event_email_template_after_event');
        }
      }
    } else {
      $existing_template = get_post_meta($post_id, '_aio_event_email_template_after_event', true);
      if (empty($existing_template) && !empty($global_template_after_event)) {
        update_post_meta($post_id, '_aio_event_email_template_after_event', absint($global_template_after_event));
      }
    }

    // Save Brevo form HTML - field is required, cannot be empty
    if (isset($_POST['aio_event_brevo_form_embed'])) {
      $form_html = $_POST['aio_event_brevo_form_embed'];
      $sanitized_html = self::sanitize_brevo_form_html($form_html);
      
      // Field is required - if empty, keep existing value or show error
      if (!empty($sanitized_html)) {
        // User provided form HTML - save it
        update_post_meta($post_id, '_aio_event_brevo_form_embed', $sanitized_html);
      } else {
        // User left empty - check if event already has a form
        $existing_form = get_post_meta($post_id, '_aio_event_brevo_form_embed', true);
        if (empty($existing_form)) {
          // Event doesn't have its own form and user left it empty
          // This should not happen if form was copied during creation, but handle it
          // Don't delete - keep empty and let validation catch it
        }
        // If existing_form exists, keep it (don't overwrite with empty)
      }
    } else {
      // Field not set in POST - ensure event has its own copy (should be copied during auto-draft)
      $existing_form = get_post_meta($post_id, '_aio_event_brevo_form_embed', true);
      if (empty($existing_form) && !empty($global_form_html)) {
        // Copy from global only if event doesn't have its own yet (shouldn't happen after creation)
        $sanitized_global = self::sanitize_brevo_form_html($global_form_html);
        if (!empty($sanitized_global)) {
          update_post_meta($post_id, '_aio_event_brevo_form_embed', $sanitized_global);
        }
      }
    }
  }

  /**
   * Sanitize Brevo form HTML
   * For Brevo forms, we skip sanitization since:
   * 1. Only administrators can fill this field
   * 2. Brevo is a trusted source
   * 3. Form must work fully with all scripts and styles
   * Return HTML exactly as provided - no modifications
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

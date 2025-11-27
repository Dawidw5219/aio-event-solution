<?php

namespace AIOEvents\Event;

/**
 * Event Custom Post Type
 */
class PostType
{
  /**
   * Register Event Post Type
   */
  public static function register()
  {
    // Block access to add new event if required settings are missing
    add_action('admin_init', [self::class, 'check_required_settings_before_add']);

    $labels = [
      'name' => __('Events', 'aio-event-solution'),
      'singular_name' => __('Event', 'aio-event-solution'),
      'menu_name' => __('Events', 'aio-event-solution'),
      'add_new' => __('Add New', 'aio-event-solution'),
      'add_new_item' => __('Add New Event', 'aio-event-solution'),
      'edit_item' => __('Edit Event', 'aio-event-solution'),
      'new_item' => __('New Event', 'aio-event-solution'),
      'view_item' => __('View Event', 'aio-event-solution'),
      'view_items' => __('View Events', 'aio-event-solution'),
      'search_items' => __('Search Events', 'aio-event-solution'),
      'not_found' => __('No events found', 'aio-event-solution'),
      'not_found_in_trash' => __('No events found in trash', 'aio-event-solution'),
      'all_items' => __('All Events', 'aio-event-solution'),
      'archives' => __('Event Archives', 'aio-event-solution'),
      'attributes' => __('Event Attributes', 'aio-event-solution'),
      'insert_into_item' => __('Insert into event', 'aio-event-solution'),
      'uploaded_to_this_item' => __('Uploaded to this event', 'aio-event-solution'),
    ];

    $args = [
      'labels' => $labels,
      'public' => true,
      'publicly_queryable' => true,
      'show_ui' => true,
      'show_in_menu' => true,
      'show_in_admin_bar' => true,
      'show_in_rest' => false, // Disable Gutenberg - use classic editor
      'query_var' => true,
      'rewrite' => ['slug' => self::get_events_slug()],
      'capability_type' => 'post',
      'has_archive' => false,
      'hierarchical' => false,
      'menu_position' => 30,
      'menu_icon' => 'dashicons-calendar-alt',
      'supports' => [
        'title',
        'editor',
        'thumbnail',
        'excerpt',
        'custom-fields',
        'revisions',
      ],
    ];

    register_post_type('aio_event', $args);

    // Register Event Categories
    self::register_event_category();

    // Disable Gutenberg editor for this post type
    add_filter('use_block_editor_for_post_type', [self::class, 'disable_gutenberg'], 10, 2);

    // Register meta boxes (now in separate classes)
    self::register_meta_boxes();

    // Add custom columns
    add_filter('manage_aio_event_posts_columns', [self::class, 'add_custom_columns']);
    add_action('manage_aio_event_posts_custom_column', [self::class, 'render_custom_columns'], 10, 2);
    add_filter('manage_edit-aio_event_sortable_columns', [self::class, 'make_columns_sortable']);

    // Modify admin query to sort by event date and show upcoming events by default
    add_action('pre_get_posts', [self::class, 'modify_admin_query']);

    // Ensure all post statuses are shown in admin list (not just published)
    add_action('pre_get_posts', [self::class, 'show_all_statuses_in_admin'], 5);

    // Validate required settings before publishing events
    add_action('transition_post_status', [self::class, 'validate_before_publish'], 10, 3);
  }

  /**
   * Disable Gutenberg editor for aio_event post type
   */
  public static function disable_gutenberg($use_block_editor, $post_type)
  {
    if ($post_type === 'aio_event') {
      return false;
    }
    return $use_block_editor;
  }

  /**
   * Check required settings and show notice on add/edit event page
   */
  public static function check_required_settings_before_add()
  {
    global $pagenow;
    
    // Only check on post-new.php or post.php for aio_event post type
    if ($pagenow !== 'post-new.php' && $pagenow !== 'post.php') {
      return;
    }

    // Check if we're editing aio_event
    $post_type = '';
    if ($pagenow === 'post-new.php') {
      if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'aio_event') {
        return;
      }
      $post_type = 'aio_event';
    } else {
      // post.php - check post ID
      if (!isset($_GET['post'])) {
        return;
      }
      $post = get_post((int) $_GET['post']);
      if (!$post || $post->post_type !== 'aio_event') {
        return;
      }
      $post_type = 'aio_event';
    }

    // Check required settings
    $settings = get_option('aio_events_settings', []);
    $missing = [];

    if (empty($settings['brevo_api_key'])) {
      $missing[] = __('Brevo API Key', 'aio-event-solution');
    }
    if (empty($settings['default_brevo_list_id'])) {
      $missing[] = __('Default Brevo List', 'aio-event-solution');
    }
    if (empty($settings['email_template_after_registration'])) {
      $missing[] = __('Template After Registration', 'aio-event-solution');
    }
    if (empty($settings['email_template_before_event'])) {
      $missing[] = __('Event Reminder Template', 'aio-event-solution');
    }
    if (empty($settings['email_template_after_event'])) {
      $missing[] = __('Template After Event', 'aio-event-solution');
    }
    if (empty($settings['global_post_event_message'])) {
      $missing[] = __('Global Post-Event Message', 'aio-event-solution');
    }
    if (empty($settings['global_brevo_form_html'])) {
      $missing[] = __('Global Registration Form (HTML)', 'aio-event-solution');
    }

    // If any settings are missing, show admin notice instead of redirecting
    if (!empty($missing)) {
      add_action('admin_notices', function() use ($missing) {
        $settings_url = admin_url('edit.php?post_type=aio_event&page=aio-events-settings');
        echo '<div class="notice notice-error"><p><strong>';
        echo esc_html__('Cannot publish event!', 'aio-event-solution');
        echo '</strong></p><p>';
        echo esc_html__('Please fill in the following required fields in settings:', 'aio-event-solution');
        echo '</p><ul style="margin-left: 20px;">';
        foreach ($missing as $field) {
          echo '<li>' . esc_html($field) . '</li>';
        }
        echo '</ul><p>';
        echo sprintf(
          '<a href="%s" class="button button-primary">%s</a>',
          esc_url($settings_url),
          esc_html__('Go to Settings', 'aio-event-solution')
        );
        echo '</p></div>';
      });
    }
  }

  /**
   * Validate required settings before publishing event
   */
  public static function validate_before_publish($new_status, $old_status, $post)
  {
    // Only check when transitioning to publish
    if ($new_status !== 'publish' || $post->post_type !== 'aio_event') {
      return;
    }

    $settings = get_option('aio_events_settings', []);
    $errors = [];

    // Check Brevo API Key
    if (empty($settings['brevo_api_key'])) {
      $errors[] = __('Brevo API Key is required. Configure it in plugin settings.', 'aio-event-solution');
    }

    // Check default Brevo list
    if (empty($settings['default_brevo_list_id'])) {
      $errors[] = __('Default Brevo List is required. Configure it in plugin settings.', 'aio-event-solution');
    }

    // Check email templates
    if (empty($settings['email_template_after_registration'])) {
      $errors[] = __('Template After Registration is required. Configure it in plugin settings.', 'aio-event-solution');
    }
    if (empty($settings['email_template_before_event'])) {
      $errors[] = __('Event Reminder Template is required. Configure it in plugin settings.', 'aio-event-solution');
    }
    if (empty($settings['email_template_after_event'])) {
      $errors[] = __('Template After Event is required. Configure it in plugin settings.', 'aio-event-solution');
    }

    // Check global post-event message
    if (empty($settings['global_post_event_message'])) {
      $errors[] = __('Global Post-Event Message is required. Configure it in plugin settings.', 'aio-event-solution');
    }
    
    // Check global Brevo form HTML
    if (empty($settings['global_brevo_form_html'])) {
      $errors[] = __('Global Registration Form (HTML) is required. Configure it in plugin settings.', 'aio-event-solution');
    }
    
    // Check event-specific Brevo form HTML (required field)
    $event_form_html = get_post_meta($post->ID, '_aio_event_brevo_form_embed', true);
    if (empty($event_form_html)) {
      $errors[] = __('Registration Form (HTML) for this event is required. Fill in the field in Brevo Integration section.', 'aio-event-solution');
    }

    // If there are errors, prevent publishing
    if (!empty($errors)) {
      // Store errors in transient
      set_transient('aio_event_required_settings_error_' . $post->ID, $errors, 30);

      // Change status back to draft
      wp_update_post([
        'ID' => $post->ID,
        'post_status' => 'draft',
      ]);

      // Add admin notice
      add_action('admin_notices', function() use ($errors) {
        echo '<div class="notice notice-error"><p><strong>';
        echo esc_html__('Cannot publish event!', 'aio-event-solution');
        echo '</strong></p><ul style="margin-left: 20px;">';
        foreach ($errors as $error) {
          echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul><p>';
        echo sprintf(
          '<a href="%s" class="button button-primary">%s</a>',
          admin_url('edit.php?post_type=aio_event&page=aio-events-settings'),
          esc_html__('Go to Settings', 'aio-event-solution')
        );
        echo '</p></div>';
      });
    }
  }

  /**
   * Register meta boxes for events
   */
  private static function register_meta_boxes()
  {
    require_once AIO_EVENTS_PATH . 'php/Admin/EventDetailsMetaBox.php';
    require_once AIO_EVENTS_PATH . 'php/Admin/BrevoIntegrationMetaBox.php';
    require_once AIO_EVENTS_PATH . 'php/Admin/EventRegistrationsMetaBox.php';

    \AIOEvents\Admin\EventDetailsMetaBox::register();
    \AIOEvents\Admin\BrevoIntegrationMetaBox::register();
    \AIOEvents\Admin\EventRegistrationsMetaBox::register();
  }

  /**
   * Register Event Category Taxonomy
   */
  private static function register_event_category()
  {
    $labels = [
      'name' => __('Event Categories', 'aio-event-solution'),
      'singular_name' => __('Event Category', 'aio-event-solution'),
      'search_items' => __('Search Event Categories', 'aio-event-solution'),
      'all_items' => __('All Event Categories', 'aio-event-solution'),
      'parent_item' => __('Parent Event Category', 'aio-event-solution'),
      'parent_item_colon' => __('Parent Event Category:', 'aio-event-solution'),
      'edit_item' => __('Edit Event Category', 'aio-event-solution'),
      'update_item' => __('Update Event Category', 'aio-event-solution'),
      'add_new_item' => __('Add New Event Category', 'aio-event-solution'),
      'new_item_name' => __('New Event Category Name', 'aio-event-solution'),
      'menu_name' => __('Categories', 'aio-event-solution'),
    ];

    register_taxonomy('aio_event_category', 'aio_event', [
      'hierarchical' => true,
      'labels' => $labels,
      'show_ui' => true,
      'show_in_rest' => true,
      'show_admin_column' => true,
      'query_var' => true,
      'rewrite' => ['slug' => 'event-category'],
    ]);
  }

  /**
   * Add custom columns
   */
  public static function add_custom_columns($columns)
  {
    $new_columns = [];

    foreach ($columns as $key => $value) {
      $new_columns[$key] = $value;

      // Add custom columns after title
      if ($key === 'title') {
        $new_columns['event_date'] = __('Event Date', 'aio-event-solution');
        $new_columns['registrations'] = __('Registrations', 'aio-event-solution');
      }
    }

    return $new_columns;
  }

  /**
   * Render custom columns
   */
  public static function render_custom_columns($column, $post_id)
  {
    switch ($column) {
      case 'event_date':
        $start_date = get_post_meta($post_id, '_aio_event_start_date', true);
        $start_time = get_post_meta($post_id, '_aio_event_start_time', true);

        if ($start_date) {
          echo '<strong>' . esc_html(date_i18n(get_option('date_format'), strtotime($start_date))) . '</strong>';
          if ($start_time) {
            echo '<br><span style="color: #646970;">' . esc_html($start_time) . '</span>';
          }
        } else {
          echo '—';
        }
        break;

      case 'registrations':
        global $wpdb;
        $table_name = $wpdb->prefix . 'aio_event_registrations';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
          echo '—';
          break;
        }

        $count = (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM $table_name WHERE event_id = %d",
          $post_id
        ));

        echo '<strong>' . esc_html($count) . '</strong> ' . __('registrations', 'aio-event-solution');
        break;
    }
  }

  /**
   * Make columns sortable
   */
  public static function make_columns_sortable($columns)
  {
    $columns['event_date'] = 'event_date';
    $columns['registrations'] = 'registrations';
    return $columns;
  }

  /**
   * Show all post statuses in admin list (not just published)
   */
  public static function show_all_statuses_in_admin($query)
  {
    // Only modify admin queries for aio_event post type
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'aio_event') {
      return;
    }

    // Don't override if post_status is explicitly set (e.g., when filtering by status)
    if ($query->get('post_status')) {
      return;
    }

    // Set post_status to 'any' to show all statuses (published, draft, pending, etc.)
    $query->set('post_status', 'any');
  }

  /**
   * Modify admin query for events list
   */
  public static function modify_admin_query($query)
  {
    // Only modify admin queries for aio_event post type
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'aio_event') {
      return;
    }

    // Don't modify if post_status is explicitly set (e.g., when filtering by status)
    // WordPress handles status filtering automatically
    if ($query->get('post_status')) {
      // Only modify ordering if event_date is selected
      if (isset($_GET['orderby']) && $_GET['orderby'] === 'event_date') {
        $query->set('orderby', 'meta_value');
        $query->set('meta_key', '_aio_event_start_date');
        $query->set('meta_type', 'DATE');
      }
      return;
    }

    // Set default ordering by event date (only if no orderby is set)
    if (!isset($_GET['orderby'])) {
      $query->set('orderby', 'meta_value');
      $query->set('meta_key', '_aio_event_start_date');
      $query->set('meta_type', 'DATE');
      $query->set('order', 'ASC');
    } elseif ($_GET['orderby'] === 'event_date') {
      $query->set('orderby', 'meta_value');
      $query->set('meta_key', '_aio_event_start_date');
      $query->set('meta_type', 'DATE');
    }
  }

  /**
   * Get events slug from settings
   */
  public static function get_events_slug()
  {
    $settings = get_option('aio_events_settings', []);
    return !empty($settings['events_slug']) ? $settings['events_slug'] : 'events';
  }
}


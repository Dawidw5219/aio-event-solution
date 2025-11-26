<?php

namespace AIOEvents\Taxonomies;

/**
 * Event Category Custom Fields (Colors)
 */
class EventCategoryMeta
{
  /**
   * Initialize hooks
   */
  public static function init()
  {
    // Add form fields
    add_action('aio_event_category_add_form_fields', [self::class, 'add_category_fields']);
    add_action('aio_event_category_edit_form_fields', [self::class, 'edit_category_fields']);

    // Save fields
    add_action('created_aio_event_category', [self::class, 'save_category_fields']);
    add_action('edited_aio_event_category', [self::class, 'save_category_fields']);

    // Add column to categories list
    add_filter('manage_edit-aio_event_category_columns', [self::class, 'add_category_columns']);
    add_filter('manage_aio_event_category_custom_column', [self::class, 'render_category_columns'], 10, 3);
  }

  /**
   * Add fields to category add form
   */
  public static function add_category_fields()
  {
?>
    <div class="form-field">
      <label><?php _e('Badge Colors & Preview', 'aio-event-solution'); ?></label>

      <div style="padding: 20px; background: #f9f9f9; border-radius: 8px; border: 2px solid #ddd; max-width: 600px;">
        <!-- Colors -->
        <div style="display: flex; gap: 20px; margin-bottom: 20px; align-items: flex-end;">
          <div style="flex: 1; min-width: 0;">
            <label for="category_bg_color" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
              <?php _e('Background', 'aio-event-solution'); ?>
            </label>
            <input type="color" name="category_bg_color" id="category_bg_color" value="#2271b1" style="width: 100% !important; max-width: 100% !important; height: 50px !important; border: 2px solid #8c8f94 !important; border-radius: 4px !important; cursor: pointer !important; display: block !important; box-sizing: border-box !important; padding: 5px !important;">
          </div>
          <div style="flex: 1; min-width: 0;">
            <label for="category_text_color" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
              <?php _e('Text', 'aio-event-solution'); ?>
            </label>
            <input type="color" name="category_text_color" id="category_text_color" value="#ffffff" style="width: 100% !important; max-width: 100% !important; height: 50px !important; border: 2px solid #8c8f94 !important; border-radius: 4px !important; cursor: pointer !important; display: block !important; box-sizing: border-box !important; padding: 5px !important;">
          </div>
        </div>

        <!-- Preview -->
        <div style="text-align: center; padding: 20px; background: white; border-radius: 6px; margin-bottom: 15px;">
          <span id="category-badge-preview-add" style="display: inline-block; padding: 6px 14px; border-radius: 6px; font-weight: 600; font-size: 14px; background: #2271b1; color: #ffffff;">
            <?php _e('Category Name', 'aio-event-solution'); ?>
          </span>
        </div>

        <!-- Contrast -->
        <div id="contrast-checker-add" style="padding: 10px; background: white; border-radius: 4px; font-size: 12px; text-align: center;">
          <span id="contrast-result-add"></span>
        </div>
      </div>
    </div>

    <script>
      jQuery(document).ready(function($) {
        function updatePreviewAdd() {
          var bgColor = $('#category_bg_color').val();
          var textColor = $('#category_text_color').val();
          var categoryName = $('#tag-name').val() || '<?php _e('Category Name', 'aio-event-solution'); ?>';

          // Update preview
          $('#category-badge-preview-add').css({
            'background-color': bgColor,
            'color': textColor
          }).text(categoryName);

          // Check contrast
          checkContrastAdd(bgColor, textColor);
        }

        function checkContrastAdd(bg, text) {
          var ratio = getContrastRatioAdd(bg, text);
          var $result = $('#contrast-result-add');

          if (ratio >= 4.5) {
            $result.html('✅ <strong>Excellent contrast</strong> (ratio: ' + ratio.toFixed(2) + ':1) - WCAG AA compliant');
            $result.css('color', '#00a32a');
          } else if (ratio >= 3) {
            $result.html('⚠️ <strong>Fair contrast</strong> (ratio: ' + ratio.toFixed(2) + ':1) - May be hard to read');
            $result.css('color', '#dba617');
          } else {
            $result.html('❌ <strong>Poor contrast</strong> (ratio: ' + ratio.toFixed(2) + ':1) - Not recommended');
            $result.css('color', '#d63638');
          }
        }

        function getContrastRatioAdd(color1, color2) {
          var l1 = getLuminanceAdd(color1);
          var l2 = getLuminanceAdd(color2);
          var lighter = Math.max(l1, l2);
          var darker = Math.min(l1, l2);
          return (lighter + 0.05) / (darker + 0.05);
        }

        function getLuminanceAdd(hex) {
          var rgb = hexToRgbAdd(hex);
          var r = rgb.r / 255;
          var g = rgb.g / 255;
          var b = rgb.b / 255;

          r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
          g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
          b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);

          return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        }

        function hexToRgbAdd(hex) {
          var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
          return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
          } : {
            r: 0,
            g: 0,
            b: 0
          };
        }

        // Update on color change
        $('#category_bg_color, #category_text_color').on('input change', updatePreviewAdd);

        // Update on name change
        $('#tag-name').on('input', updatePreviewAdd);

        // Initial check
        updatePreviewAdd();
      });
    </script>
  <?php
  }

  /**
   * Add fields to category edit form
   */
  public static function edit_category_fields($term)
  {
    $bg_color = get_term_meta($term->term_id, 'category_bg_color', true);
    $text_color = get_term_meta($term->term_id, 'category_text_color', true);

    // Defaults
    if (empty($bg_color)) {
      $bg_color = '#2271b1';
    }
    if (empty($text_color)) {
      $text_color = '#ffffff';
    }
  ?>
    <tr class="form-field">
      <th scope="row">
        <label><?php _e('Badge Colors & Preview', 'aio-event-solution'); ?></label>
      </th>
      <td>
        <div style="padding: 20px; background: #f9f9f9; border-radius: 8px; border: 2px solid #ddd; max-width: 600px;">
          <!-- Colors -->
          <div style="display: flex; gap: 20px; margin-bottom: 20px; align-items: flex-end;">
            <div style="flex: 1; min-width: 0;">
              <label for="category_bg_color" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                <?php _e('Background', 'aio-event-solution'); ?>
              </label>
              <input type="color" name="category_bg_color" id="category_bg_color" value="<?php echo esc_attr($bg_color); ?>" style="width: 100% !important; max-width: 100% !important; height: 50px !important; border: 2px solid #8c8f94 !important; border-radius: 4px !important; cursor: pointer !important; display: block !important; box-sizing: border-box !important; padding: 5px !important;">
            </div>
            <div style="flex: 1; min-width: 0;">
              <label for="category_text_color" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                <?php _e('Text', 'aio-event-solution'); ?>
              </label>
              <input type="color" name="category_text_color" id="category_text_color" value="<?php echo esc_attr($text_color); ?>" style="width: 100% !important; max-width: 100% !important; height: 50px !important; border: 2px solid #8c8f94 !important; border-radius: 4px !important; cursor: pointer !important; display: block !important; box-sizing: border-box !important; padding: 5px !important;">
            </div>
          </div>

          <!-- Preview -->
          <div style="text-align: center; padding: 20px; background: white; border-radius: 6px; margin-bottom: 15px;">
            <span id="category-badge-preview" style="display: inline-block; padding: 6px 14px; border-radius: 6px; font-weight: 600; font-size: 14px; background: <?php echo esc_attr($bg_color); ?>; color: <?php echo esc_attr($text_color); ?>;">
              <?php echo esc_html($term->name); ?>
            </span>
          </div>

          <!-- Contrast -->
          <div id="contrast-checker" style="padding: 10px; background: white; border-radius: 4px; font-size: 12px; text-align: center;">
            <span id="contrast-result"></span>
          </div>
        </div>

        <script>
          jQuery(document).ready(function($) {
            function updatePreview() {
              var bgColor = $('#category_bg_color').val();
              var textColor = $('#category_text_color').val();

              // Update preview
              $('#category-badge-preview').css({
                'background-color': bgColor,
                'color': textColor
              });

              // Check contrast
              checkContrast(bgColor, textColor);
            }

            function checkContrast(bg, text) {
              var ratio = getContrastRatio(bg, text);
              var $result = $('#contrast-result');

              if (ratio >= 4.5) {
                $result.html('✅ <strong>Excellent contrast</strong> (ratio: ' + ratio.toFixed(2) + ':1) - WCAG AA compliant');
                $result.css('color', '#00a32a');
              } else if (ratio >= 3) {
                $result.html('⚠️ <strong>Fair contrast</strong> (ratio: ' + ratio.toFixed(2) + ':1) - May be hard to read');
                $result.css('color', '#dba617');
              } else {
                $result.html('❌ <strong>Poor contrast</strong> (ratio: ' + ratio.toFixed(2) + ':1) - Not recommended');
                $result.css('color', '#d63638');
              }
            }

            function getContrastRatio(color1, color2) {
              var l1 = getLuminance(color1);
              var l2 = getLuminance(color2);
              var lighter = Math.max(l1, l2);
              var darker = Math.min(l1, l2);
              return (lighter + 0.05) / (darker + 0.05);
            }

            function getLuminance(hex) {
              var rgb = hexToRgb(hex);
              var r = rgb.r / 255;
              var g = rgb.g / 255;
              var b = rgb.b / 255;

              r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
              g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
              b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);

              return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            }

            function hexToRgb(hex) {
              var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
              return result ? {
                r: parseInt(result[1], 16),
                g: parseInt(result[2], 16),
                b: parseInt(result[3], 16)
              } : {
                r: 0,
                g: 0,
                b: 0
              };
            }

            // Update on color change
            $('#category_bg_color, #category_text_color').on('input change', updatePreview);

            // Initial check
            updatePreview();
          });
        </script>
      </td>
    </tr>
<?php
  }

  /**
   * Save category fields
   */
  public static function save_category_fields($term_id)
  {
    if (isset($_POST['category_bg_color'])) {
      update_term_meta($term_id, 'category_bg_color', sanitize_hex_color($_POST['category_bg_color']));
    }

    if (isset($_POST['category_text_color'])) {
      update_term_meta($term_id, 'category_text_color', sanitize_hex_color($_POST['category_text_color']));
    }
  }

  /**
   * Add custom columns
   */
  public static function add_category_columns($columns)
  {
    $new_columns = [];

    foreach ($columns as $key => $value) {
      $new_columns[$key] = $value;

      // Add color column after name
      if ($key === 'name') {
        $new_columns['category_colors'] = __('Badge Preview', 'aio-event-solution');
      }
    }

    return $new_columns;
  }

  /**
   * Render custom columns
   */
  public static function render_category_columns($content, $column_name, $term_id)
  {
    if ($column_name === 'category_colors') {
      $bg_color = get_term_meta($term_id, 'category_bg_color', true);
      $text_color = get_term_meta($term_id, 'category_text_color', true);

      if (empty($bg_color)) {
        $bg_color = '#2271b1';
      }
      if (empty($text_color)) {
        $text_color = '#ffffff';
      }

      $term = get_term($term_id);

      return sprintf(
        '<span style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: 600; background: %s; color: %s;">%s</span>',
        esc_attr($bg_color),
        esc_attr($text_color),
        esc_html($term->name)
      );
    }

    return $content;
  }

  /**
   * Get category colors
   */
  public static function get_category_colors($term_id)
  {
    $bg_color = get_term_meta($term_id, 'category_bg_color', true);
    $text_color = get_term_meta($term_id, 'category_text_color', true);

    return [
      'bg' => !empty($bg_color) ? $bg_color : '#2271b1',
      'text' => !empty($text_color) ? $text_color : '#ffffff',
    ];
  }
}

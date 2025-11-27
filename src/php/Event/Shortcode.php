<?php

namespace AIOEvents\Event;

use Timber\Timber;

/**
 * Events Shortcode Handler
 */
class Shortcode
{
  /**
   * Register shortcode
   */
  public static function register()
  {
    add_shortcode('aio_events', [self::class, 'render']);
  }

  /**
   * Render shortcode
   * 
   * @param array $atts Shortcode attributes
   * @return string
   */
  public static function render($atts)
  {
    $atts = shortcode_atts([
      'limit' => 12,
      'category' => '',
      'order' => 'ASC',
      'orderby' => 'meta_value',
      'show_past' => 'no',
    ], $atts, 'aio_events');

    $args = [
      'post_type' => 'aio_event',
      'posts_per_page' => intval($atts['limit']),
      'post_status' => 'publish',
      'orderby' => $atts['orderby'],
      'order' => $atts['order'],
      'meta_key' => '_aio_event_start_date',
    ];

    // Show only upcoming events by default
    if ($atts['show_past'] !== 'yes') {
      $args['meta_query'] = [
        [
          'key' => '_aio_event_start_date',
          'value' => date('Y-m-d'),
          'compare' => '>=',
          'type' => 'DATE',
        ],
      ];
    }

    // Filter by category if specified
    if (!empty($atts['category'])) {
      $args['tax_query'] = [
        [
          'taxonomy' => 'aio_event_category',
          'field' => 'slug',
          'terms' => $atts['category'],
        ],
      ];
    }

    $posts = Timber::get_posts($args);

    // Group events by month and year
    $grouped_events = [];
    foreach ($posts as $event) {
      $event_date = $event->meta('_aio_event_start_date');
      if (empty($event_date)) {
        // Events without date go to "unsorted" group
        if (!isset($grouped_events['unsorted'])) {
          $grouped_events['unsorted'] = [];
        }
        $grouped_events['unsorted'][] = $event;
        continue;
      }

      // Parse date to get year and month
      // Ensure date is in Y-m-d format
      $date_timestamp = strtotime($event_date);
      if ($date_timestamp === false) {
        // Invalid date - skip or add to unsorted
        if (!isset($grouped_events['unsorted'])) {
          $grouped_events['unsorted'] = [];
        }
        $grouped_events['unsorted'][] = $event;
        continue;
      }
      
      $year = (int) date('Y', $date_timestamp);
      $month = (int) date('m', $date_timestamp);
      $key = sprintf('%04d-%02d', $year, $month);

      if (!isset($grouped_events[$key])) {
        $grouped_events[$key] = [
          'year' => $year,
          'month' => $month,
          'events' => [],
        ];
      }

      $grouped_events[$key]['events'][] = $event;
    }

    // Sort groups by date (year-month)
    ksort($grouped_events);

    // Move unsorted to the end if exists
    if (isset($grouped_events['unsorted'])) {
      $unsorted = $grouped_events['unsorted'];
      unset($grouped_events['unsorted']);
      $grouped_events['unsorted'] = $unsorted;
    }

    // Count how many month groups we have (excluding unsorted)
    $month_groups_count = 0;
    foreach ($grouped_events as $key => $group) {
      if ($key !== 'unsorted') {
        $month_groups_count++;
      }
    }

    $context = [
      'grouped_events' => $grouped_events,
      'show_month_headers' => $month_groups_count > 1, // Show headers only if multiple months
      'atts' => $atts,
    ];

    return Timber::compile('events/shortcode.twig', $context);
  }
}


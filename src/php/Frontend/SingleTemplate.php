<?php

namespace AIOEvents\Frontend;

use Timber\Timber;
use AIOEvents\Email\EmailHelper;

/**
 * Single Event Template Handler
 * This template is used when viewing a single event
 * Uses standard WordPress theme header and footer for consistency
 */
class SingleTemplate
{
  /**
   * Render single event template
   */
  public static function render()
  {
    get_header();

    $context = Timber::context();
    $post = Timber::get_post();
    $context['post'] = $post;

    // Get settings for content box styling
    $settings = get_option('aio_events_settings', []);
    $context['content_box_background'] = $settings['content_box_background'] ?? '#f3f3f3';

    // Check if registration was successful (from redirect)
    $context['registration_success'] = isset($_GET['registered']) && $_GET['registered'] === '1';

    // Check if event has passed (with proper timezone handling)
    $context['is_past_event'] = self::is_event_past($post->ID);

    Timber::render('events/single.twig', $context);

    get_footer();
  }

  /**
   * Check if registration period has ended (using proper timezone + grace period)
   */
  private static function is_event_past($event_id)
  {
    require_once AIO_EVENTS_PATH . 'php/Email/EmailHelper.php';
    
    $event_datetime = EmailHelper::get_event_datetime($event_id);
    
    if (!$event_datetime) {
      return false;
    }

    $settings = get_option('aio_events_settings', []);
    $grace_minutes = isset($settings['registration_grace_minutes']) ? absint($settings['registration_grace_minutes']) : 45;
    $registration_closes_at = $event_datetime + ($grace_minutes * 60);

    return time() > $registration_closes_at;
  }
}


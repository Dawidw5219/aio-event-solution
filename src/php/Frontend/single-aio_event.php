<?php

/**
 * Single Event Template
 * 
 * This template is used when viewing a single event
 * Uses standard WordPress theme header and footer for consistency
 */

use Timber\Timber;

get_header();

$context = Timber::context();
$context['post'] = Timber::get_post();

// Get settings for content box styling
$settings = get_option('aio_events_settings', []);
$context['content_box_background'] = $settings['content_box_background'] ?? '#f3f3f3';

// Check if registration was successful (from redirect)
$context['registration_success'] = isset($_GET['registered']) && $_GET['registered'] === '1';

Timber::render('events/single.twig', $context);

get_footer();

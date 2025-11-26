<?php
/**
 * Archive Events Template
 * 
 * This template is used when viewing events archive, category or tag pages
 */

use Timber\Timber;

$context = Timber::context();

$context['posts'] = Timber::get_posts();
$context['title'] = get_the_archive_title();

// If it's a term archive (category or tag)
if (is_tax()) {
    $context['term'] = Timber::get_term();
}

// Pagination
$context['pagination'] = Timber::get_pagination();

Timber::render('events/archive.twig', $context);


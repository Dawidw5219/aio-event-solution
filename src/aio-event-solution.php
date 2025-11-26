<?php

/**
 * Plugin Name: All-in-One Event Solution
 * Text Domain: aio-event-solution
 * Domain Path: /languages
 * Description: Complete event management solution for WordPress with Brevo integration
 * Version: 1.0.8
 * Author: App4You.dev
 * Author URI: https://app4you.dev
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.4
 * GitHub URI: Dawidw5219/aio-event-solution
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

define('AIO_EVENTS_VERSION', '1.0.0');
define('AIO_EVENTS_PATH', plugin_dir_path(__FILE__));
define('AIO_EVENTS_URL', plugin_dir_url(__FILE__));
define('AIO_EVENTS_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader
require_once AIO_EVENTS_PATH . 'vendor/autoload.php';

// Load plugin classes
require_once AIO_EVENTS_PATH . 'php/Plugin.php';
require_once AIO_EVENTS_PATH . 'php/Admin/SettingsPage.php';
require_once AIO_EVENTS_PATH . 'php/PostTypes/EventPostType.php';

use AIOEvents\Plugin;

/**
 * Check if Timber is installed
 */
function aio_events_check_timber()
{
	if (!class_exists('Timber\Timber')) {
		add_action('admin_notices', function () {
			echo '<div class="error"><p>';
			echo esc_html__('Timber not found. Please install Timber library via Composer.', 'aio-event-solution');
			echo '</p></div>';
		});
		return false;
	}
	return true;
}

/**
 * Initialize plugin
 */
function aio_events_init()
{
	// Check Timber
	if (!aio_events_check_timber()) {
		return;
	}

	// Configure Timber
	Timber\Timber::init();
	Timber\Timber::$dirname = ['templates', 'components'];
	Timber\Timber::$locations = [
		AIO_EVENTS_PATH . 'templates',
		AIO_EVENTS_PATH . 'components',
	];

	// Add Twig functions
	require_once AIO_EVENTS_PATH . 'php/Helpers/EventJoinLinkHelper.php';
	require_once AIO_EVENTS_PATH . 'php/Helpers/EmailTemplateSelector.php';
	add_filter('timber/twig', function ($twig) {
		$twig->addFunction(new \Twig\TwigFunction('aio_get_join_link', function ($event_id, $email = null) {
			return \AIOEvents\Helpers\EventJoinLinkHelper::get_join_link($event_id, $email);
		}));
		$twig->addFunction(new \Twig\TwigFunction('aio_is_registered', function ($event_id, $email = null) {
			return \AIOEvents\Helpers\EventJoinLinkHelper::is_registered($event_id, $email);
		}));
		$twig->addFunction(new \Twig\TwigFunction('aio_render_email_template_selector', function ($args) {
			ob_start();
			\AIOEvents\Helpers\EmailTemplateSelector::render($args);
			return ob_get_clean();
		}, ['is_safe' => ['html']]));
		return $twig;
	});

	// Load text domain
	load_plugin_textdomain('aio-event-solution', false, dirname(AIO_EVENTS_BASENAME) . '/languages');

	// Initialize plugin
	Plugin::instance();
}
add_action('plugins_loaded', 'aio_events_init');

/**
 * Activation hook
 */
function aio_events_activate()
{
	// Register post types first
	require_once AIO_EVENTS_PATH . 'php/PostTypes/EventPostType.php';
	\AIOEvents\PostTypes\EventPostType::register();

	// Create registrations table
	aio_events_create_tables();

	// Flush rewrite rules
	flush_rewrite_rules();

	// Save version
	update_option('aio_events_version', AIO_EVENTS_VERSION);
}
register_activation_hook(__FILE__, 'aio_events_activate');

/**
 * Create custom database tables
 */
function aio_events_create_tables()
{
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name = $wpdb->prefix . 'aio_event_registrations';

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_id bigint(20) unsigned NOT NULL,
		name varchar(255) NOT NULL,
		email varchar(255) NOT NULL,
		phone varchar(50) DEFAULT NULL,
		registered_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		brevo_added tinyint(1) DEFAULT 0 NOT NULL,
		PRIMARY KEY  (id),
		KEY event_id (event_id),
		KEY email (email)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);

	// Create scheduled emails table
	require_once AIO_EVENTS_PATH . 'php/Scheduler/EmailScheduler.php';
	\AIOEvents\Scheduler\EmailScheduler::create_table();

	// Create cron logs table
	require_once AIO_EVENTS_PATH . 'php/Scheduler/CronLogger.php';
	\AIOEvents\Scheduler\CronLogger::create_table();
}

/**
 * Deactivation hook
 */
function aio_events_deactivate()
{
	flush_rewrite_rules();
	wp_clear_scheduled_hook('aio_events_schedule_daily_emails');
}
register_deactivation_hook(__FILE__, 'aio_events_deactivate');

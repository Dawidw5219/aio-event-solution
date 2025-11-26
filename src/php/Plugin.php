<?php

namespace AIOEvents;

use AIOEvents\Admin\SettingsPage;
use AIOEvents\Admin\AjaxController;
use AIOEvents\Assets\AssetManager;
use AIOEvents\Database\Migrator;
use AIOEvents\PostTypes\EventPostType;
use AIOEvents\Scheduler\CronManager;

/**
 * Main Plugin Class - Singleton Bootstrap
 */
class Plugin
{
  private static $instance = null;

  /**
   * Get singleton instance
   */
  public static function instance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Constructor
   */
  private function __construct()
  {
    $this->init_hooks();
  }

  /**
   * Initialize hooks
   */
  private function init_hooks()
  {
    add_action('init', [$this, 'register_post_types']);
    add_action('init', [$this, 'register_shortcodes']);
    add_action('init', [$this, 'init_registration']);
    add_action('init', [$this, 'maybe_flush_rewrite_rules'], 99);
    add_action('admin_menu', [$this, 'register_admin_pages']);
    add_filter('template_include', [$this, 'load_event_templates']);
    add_filter('timber/twig', [$this, 'add_twig_functions']);

    // Initialize components
    $this->init_assets();
    $this->init_github_updater();
    $this->init_rest_api();
    $this->init_cron();
  }

  /**
   * Initialize assets manager
   */
  private function init_assets()
  {
    require_once AIO_EVENTS_PATH . 'php/Assets/AssetManager.php';
    AssetManager::register();
  }

  /**
   * Initialize GitHub updater
   */
  private function init_github_updater()
  {
    require_once AIO_EVENTS_PATH . 'php/Updater/GitHubUpdater.php';
    new \AIOEvents\Updater\GitHubUpdater(AIO_EVENTS_PATH . 'aio-event-solution.php');
  }

  /**
   * Initialize REST API controllers
   */
  private function init_rest_api()
  {
    require_once AIO_EVENTS_PATH . 'php/API/BrevoWebhookController.php';
    \AIOEvents\API\BrevoWebhookController::register();
    
    require_once AIO_EVENTS_PATH . 'php/API/JoinEventController.php';
    \AIOEvents\API\JoinEventController::register();
  }

  /**
   * Initialize cron manager
   */
  private function init_cron()
  {
    require_once AIO_EVENTS_PATH . 'php/Scheduler/CronManager.php';
    CronManager::init();
  }

  /**
   * Flush rewrite rules if slug was changed
   */
  public function maybe_flush_rewrite_rules()
  {
    if (get_option('aio_events_flush_rewrite')) {
      flush_rewrite_rules();
      delete_option('aio_events_flush_rewrite');
    }
  }

  /**
   * Add custom Twig functions for templates
   */
  public function add_twig_functions($twig)
  {
    $existingFunctions = $twig->getFunctions();
    if (!isset($existingFunctions['aio_render_email_template_selector'])) {
      $twig->addFunction(new \Twig\TwigFunction('aio_render_email_template_selector', function ($args) {
        require_once AIO_EVENTS_PATH . 'php/Helpers/EmailTemplateSelector.php';
        ob_start();
        \AIOEvents\Helpers\EmailTemplateSelector::render($args);
        return ob_get_clean();
      }, ['is_safe' => ['html']]));
    }

    return $twig;
  }

  /**
   * Initialize plugin features
   */
  public function init_registration()
  {
    require_once AIO_EVENTS_PATH . 'php/Taxonomies/EventCategoryMeta.php';
    \AIOEvents\Taxonomies\EventCategoryMeta::init();

    require_once AIO_EVENTS_PATH . 'php/Database/Migrator.php';
    Migrator::run();

    require_once AIO_EVENTS_PATH . 'php/Admin/AjaxController.php';
    AjaxController::register();
  }

  /**
   * Register custom post types
   */
  public function register_post_types()
  {
    EventPostType::register();
  }

  /**
   * Register admin pages
   */
  public function register_admin_pages()
  {
    require_once AIO_EVENTS_PATH . 'php/Admin/ScheduledEmailsPage.php';
    \AIOEvents\Admin\ScheduledEmailsPage::register();

    require_once AIO_EVENTS_PATH . 'php/Admin/CronStatusPage.php';
    \AIOEvents\Admin\CronStatusPage::register();

    SettingsPage::register();
  }

  /**
   * Register shortcodes
   */
  public function register_shortcodes()
  {
    require_once AIO_EVENTS_PATH . 'php/Shortcodes/EventsShortcode.php';
    \AIOEvents\Shortcodes\EventsShortcode::register();
  }

  /**
   * Load event templates
   */
  public function load_event_templates($template)
  {
    if (is_singular('aio_event')) {
      $plugin_template = AIO_EVENTS_PATH . 'php/Frontend/single-aio_event.php';
      if (file_exists($plugin_template)) {
        return $plugin_template;
      }
    }

    if (is_tax('aio_event_category')) {
      $plugin_template = AIO_EVENTS_PATH . 'php/Frontend/archive-aio_event.php';
      if (file_exists($plugin_template)) {
        return $plugin_template;
      }
    }

    return $template;
  }

  /**
   * Save registration data (delegation to RegistrationService)
   * Kept for backward compatibility with existing code
   * 
   * @deprecated Use RegistrationService::register() directly
   */
  public function save_registration_data($event_id, $email, $name, $phone = '', $from_brevo = false, $extra_attributes = [])
  {
    require_once AIO_EVENTS_PATH . 'php/Services/RegistrationService.php';
    return \AIOEvents\Services\RegistrationService::register($event_id, $email, $name, $phone, $extra_attributes);
  }
}

/**
 * Helper function to get UTC start and end times for an event
 * Must be in global namespace for Twig/Timber function() calls
 *
 * @param string $date Event date (Y-m-d)
 * @param string $time Event time (H:i)
 * @param int $duration_hours Duration in hours
 * @return array ['start' => 'YYYYMMDDTHHmmssZ', 'end' => 'YYYYMMDDTHHmmssZ']
 */
function aio_event_utc_times($date, $time, $duration_hours = 1)
{
  if (empty($date) || empty($time)) {
    return ['start' => '', 'end' => ''];
  }

  $wp_timezone = wp_timezone();
  $datetime_str = $date . ' ' . $time;

  try {
    $start = new \DateTime($datetime_str, $wp_timezone);
    $end = clone $start;
    $end->modify('+' . $duration_hours . ' hour');

    $utc = new \DateTimeZone('UTC');
    $start->setTimezone($utc);
    $end->setTimezone($utc);

    return [
      'start' => $start->format('Ymd\THis\Z'),
      'end' => $end->format('Ymd\THis\Z'),
    ];
  } catch (\Exception $e) {
    return ['start' => '', 'end' => ''];
  }
}

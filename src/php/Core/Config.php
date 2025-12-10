<?php

namespace AIOEvents\Core;

/**
 * Central configuration constants for AIO Events
 */
class Config
{
  /**
   * Brevo scheduling limit - max hours ahead we can schedule emails
   */
  const MAX_SCHEDULE_HOURS = 48;

  /**
   * Grace period for join emails - hours after event start when join email can still be sent
   */
  const JOIN_GRACE_PERIOD_HOURS = 1;

  /**
   * Grace period for followup emails - days after event when followup can still be sent
   */
  const FOLLOWUP_GRACE_PERIOD_DAYS = 7;

  /**
   * Minimum hours before event that registration must occur to receive reminder email
   */
  const MIN_REGISTRATION_HOURS_FOR_REMINDER = 24;

  /**
   * Default email timing settings (in minutes)
   */
  const DEFAULT_TIME_BEFORE_EVENT = 1440;  // 24 hours
  const DEFAULT_TIME_JOIN_EVENT = 10;      // 10 minutes
  const DEFAULT_TIME_AFTER_EVENT = 120;    // 2 hours

  /**
   * Get max schedule time as seconds
   */
  public static function get_max_schedule_seconds()
  {
    return self::MAX_SCHEDULE_HOURS * 3600;
  }

  /**
   * Get join grace period as seconds
   */
  public static function get_join_grace_seconds()
  {
    return self::JOIN_GRACE_PERIOD_HOURS * 3600;
  }

  /**
   * Get followup grace period as seconds
   */
  public static function get_followup_grace_seconds()
  {
    return self::FOLLOWUP_GRACE_PERIOD_DAYS * DAY_IN_SECONDS;
  }
}


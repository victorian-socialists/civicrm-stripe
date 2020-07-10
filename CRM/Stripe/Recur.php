<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use CRM_Stripe_ExtensionUtil as E;

/**
 * Class CRM_Stripe_Recur
 */
class CRM_Stripe_Recur {

  /**
   * Get a list of [dayOfMonth => dayOfMonth] for selecting
   *   allowed future recur start dates in settings
   *
   * @return string[]
   */
  public static function getRecurStartDays() {
    // 0 = Special case - now = as soon as transaction is processed
    $days = [0 => 'now'];
    for ($i = 1; $i <= 28; $i++) {
      // Add days 1 to 28 (29-31 are excluded because don't exist for some months)
      $days["$i"] = "$i";
    }
    return $days;
  }

  /**
   * Get list of future start dates formatted to display to user
   *
   * @return array|null
   */
  public static function getFutureMonthlyStartDates() {
    $allowDays = \Civi::settings()->get('stripe_future_recur_start_days');
    // Future date options.
    $startDates = [];

    // If only "now" (default) is specified don't give start date options
    if (count($allowDays) === 1 && ((int) $allowDays[0] === 0)) {
      return NULL;
    }

    $todayDay = (int) date('d');
    $now = date('YmdHis');
    foreach ($allowDays as $dayOfMonth) {
      $dayOfMonth = (int) $dayOfMonth;
      if (($dayOfMonth === 0) || ($dayOfMonth === $todayDay)) {
        // Today or now
        $startDates[$now] = E::ts('Now');
      }
      else {
        // Add all days permitted in settings
        // We add for this month or next month depending on todays date.
        $month = ($dayOfMonth < $todayDay) ? 'next' : 'this';
        $date = new DateTime();
        $date->setTime(3,0,0)
          ->modify("first day of {$month} month")
          ->modify("+" . ($dayOfMonth - 1) . " days");
        $startDates[$date->format('YmdHis')] = CRM_Utils_Date::customFormat($date->format('YmdHis'), \Civi::settings()->get('dateformatFull'));
      }
    }
    ksort($startDates);
    return $startDates;
  }

}

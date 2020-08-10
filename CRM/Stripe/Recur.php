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

  /**
   * Build the form functionality for future recurring start date
   *
   * @param \CRM_Core_Form|\CRM_Contribute_Form_Contribution_Main $form
   *   The payment form
   * @param \CRM_Core_Payment_Stripe $paymentProcessor
   *   The payment object
   * @param array $jsVars
   *   (reference) Array of variables to be assigned to CRM.vars.stripe in the javascript domain
   */
  public static function buildFormFutureRecurStartDate($form, $paymentProcessor, &$jsVars) {
    // We can choose which frequency_intervals to enable future recurring start date for.
    // If none are enabled (or the contribution page does not have any that are enabled in Stripe settings)
    //   then don't load the futurerecur elements on the form.
    $enableFutureRecur = FALSE;
    $startDateFrequencyIntervals = \Civi::settings()->get('stripe_enable_public_future_recur_start');
    if (!empty($form->_values['recur_frequency_unit'])) {
      $formFrequencyIntervals = explode(CRM_Core_DAO::VALUE_SEPARATOR, $form->_values['recur_frequency_unit']);
      $enableFutureRecur = FALSE;
      foreach ($formFrequencyIntervals as $interval) {
        if (in_array($interval, $startDateFrequencyIntervals)) {
          $enableFutureRecur = TRUE;
          break;
        }
      }
    }
    elseif (!empty($form->_membershipBlock)) {
      if (isset($form->_membershipBlock['auto_renew'])) {
        foreach ($form->_membershipBlock['auto_renew'] as $membershipType => $autoRenew) {
          if (!empty($autoRenew)) {
            $interval = civicrm_api3('MembershipType', 'getvalue', ['id' => $membershipType, 'return' => 'duration_unit']);
            if (in_array($interval, $startDateFrequencyIntervals)) {
              $enableFutureRecur = TRUE;
            }
            break;
          }
        }
      }
    }

    // Add form element and js to select future recurring start date
    if ($enableFutureRecur && !$paymentProcessor->isBackOffice() && $paymentProcessor->supportsFutureRecurStartDate()) {
      $jsVars['startDateFrequencyIntervals'] = $startDateFrequencyIntervals;
      $startDates = CRM_Stripe_Recur::getFutureMonthlyStartDates();
      if ($startDates) {
        $form->addElement('select', 'receive_date', ts('Start date'), $startDates);
        CRM_Core_Region::instance('billing-block')->add([
          'template' => 'CRM/Core/Payment/Stripe/BillingBlockRecurringExtra.tpl',
        ]);
        CRM_Core_Region::instance('billing-block')->add([
          'scriptUrl' => \Civi::service('asset_builder')->getUrl(
            'recurStart.js',
            [
              'path' => \Civi::resources()
                ->getPath(E::LONG_NAME, 'js/recur_start.js'),
              'mimetype' => 'application/javascript',
            ]
          ),
          // Load after civicrm_stripe.js (weight 100)
          'weight' => 120,
        ]);
      }
    }

  }
}

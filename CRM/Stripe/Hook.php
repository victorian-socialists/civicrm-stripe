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

/**
 * This class implements hooks for Stripe
 */
class CRM_Stripe_Hook {

  /**
   * This hook allows modifying recurring contribution parameters
   *
   * @param array $recurContributionParams Recurring contribution params (ContributionRecur.create API parameters)
   *
   * @return mixed
   */
  public static function updateRecurringContribution(&$recurContributionParams) {
    return CRM_Utils_Hook::singleton()
      ->invoke(1, $recurContributionParams, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject,
        CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'civicrm_stripe_updateRecurringContribution');
  }

}

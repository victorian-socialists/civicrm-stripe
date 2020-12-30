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

class CRM_Stripe_BAO_StripePaymentintent extends CRM_Stripe_DAO_StripePaymentintent {

  public static function getEntityName() {
    return 'StripePaymentintent';
  }

  /**
   * Create a new StripePaymentintent based on array-data
   *
   * @param array $params key-value pairs
   *
   * @return \CRM_Stripe_BAO_StripePaymentintent
   */
  public static function create($params) {
    $instance = new self;
    try {
      if (!empty($params['id'])) {
        $instance->id = $params['id'];
      }
      elseif ($params['stripe_intent_id']) {
        $instance->id = civicrm_api3('StripePaymentintent', 'getvalue', [
          'return' => "id",
          'stripe_intent_id' => $params['stripe_intent_id'],
        ]);
      }
      if ($instance->id) {
        if ($instance->find()) {
          $instance->fetch();
        }
      }
    }
    catch (Exception $e) {
      // do nothing, we're creating a new one
    }

    $flags = empty($instance->flags) ? [] : unserialize($instance->flags);
    if (!empty($params['flags']) && is_array($params['flags'])) {
      foreach ($params['flags'] as $flag) {
        if (!in_array($flag, $flags)) {
          $flags[] = 'NC';
        }
      }
      unset($params['flags']);
    }
    $instance->flags = serialize($flags);

    if (!empty($_SERVER['HTTP_REFERER']) && empty($instance->referrer)) {
      $instance->referrer = $_SERVER['HTTP_REFERER'];
    }

    $hook = empty($instance->id) ? 'create' : 'edit';
    CRM_Utils_Hook::pre($hook, self::getEntityName(), $params['id'] ?? NULL, $params);
    $instance->copyValues($params);
    $instance->save();

    CRM_Utils_Hook::post($hook, self::getEntityName(), $instance->id, $instance);

    return $instance;
  }
}

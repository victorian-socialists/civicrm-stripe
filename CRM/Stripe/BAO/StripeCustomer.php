<?php
use CRM_Stripe_ExtensionUtil as E;

class CRM_Stripe_BAO_StripeCustomer extends CRM_Stripe_DAO_StripeCustomer {

  /**
   * Create a new StripeCustomer based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Stripe_DAO_StripeCustomer|NULL
   *
  public static function create($params) {
    $className = 'CRM_Stripe_DAO_StripeCustomer';
    $entityName = 'StripeCustomer';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}

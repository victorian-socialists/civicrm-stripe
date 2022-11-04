<?php
namespace Civi\Api4;

/**
 * StripeCustomer entity.
 *
 * Provided by the Stripe Payment Processor extension.
 *
 * @package Civi\Api4
 */
class StripeCustomer extends Generic\DAOEntity {

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\StripeCustomer\GetFromStripe
   */
  public static function getFromStripe($checkPermissions = TRUE) {
    return (new Action\StripeCustomer\GetFromStripe(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\StripeCustomer\UpdateStripe
   */
  public static function updateStripe($checkPermissions = TRUE) {
    return (new Action\StripeCustomer\UpdateStripe(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}

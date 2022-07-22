<?php
namespace Civi\Api4;

/**
 * StripePaymentintent entity.
 *
 * Provided by the Stripe extension
 *
 * @package Civi\Api4
 */
class StripePaymentintent extends Generic\DAOEntity {

  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['processMOTO'] = ['allow stripe moto payments'];
    // The "minimum" permission for an API4 call is "access CiviCRM"|"access AJAX API".
    // We can't remove the requirement for one of those permissions here.
    // So we specify only 'make online contributions' using alterAPIRoutePermissions hook.
    // $permissions['processPublic'] = ['make online contributions'];
    return $permissions;
  }

  /**
   * @param bool $checkPermissions
   * @return Action\StripePaymentintent\ProcessPublic
   */
  public static function processPublic($checkPermissions = TRUE) {
    return (new Action\StripePaymentintent\ProcessPublic(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Action\StripePaymentintent\ProcessMOTO
   */
  public static function processMOTO($checkPermissions = TRUE) {
    return (new Action\StripePaymentintent\ProcessMOTO(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}

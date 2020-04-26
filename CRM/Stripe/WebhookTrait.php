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

trait CRM_Stripe_WebhookTrait {
  /**********************
   * MJW_Webhook_Trait: 20190707
   *********************/

  /**
   * @var array Payment processor
   */
  private $_paymentProcessor;

  /**
   * Get the path of the webhook depending on the UF (eg Drupal, Joomla, Wordpress)
   *
   * @param string $paymentProcessorId
   *
   * @return string
   */
  public static function getWebhookPath($paymentProcessorId) {
    $UFWebhookPath = CRM_Utils_System::url('civicrm/payment/ipn/' . $paymentProcessorId, NULL, TRUE, NULL, FALSE, TRUE);
    return $UFWebhookPath;
  }

}

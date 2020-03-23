<?php
/**
 * https://civicrm.org/licensing
 */

use CRM_Stripe_ExtensionUtil as E;

/**
 * Class CRM_Stripe_Check
 */
class CRM_Stripe_Check {

  /**
   * @var string
   */
  const API_VERSION = '2020-03-02';
  const API_MIN_VERSION = '2019-12-03';

  /**
   * @var string
   */
  const MIN_VERSION_MJWSHARED = '0.7';

  public static function checkRequirements(&$messages) {
    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => "mjwshared",
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $messages[] = new CRM_Utils_Check_Message(
        'stripe_requirements',
        E::ts('The Stripe extension requires the mjwshared extension which is not installed (https://lab.civicrm.org/extensions/mjwshared).'),
        E::ts('Stripe: Missing Requirements'),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
    }

    if (version_compare($extensions['values'][$extensions['id']]['version'], CRM_Stripe_Check::MIN_VERSION_MJWSHARED) === -1) {
      $messages[] = new CRM_Utils_Check_Message(
        'stripe_requirements',
        E::ts('The Stripe extension requires the mjwshared extension version %1 or greater but your system has version %2.',
          [
            1 => CRM_Stripe_Check::MIN_VERSION_MJWSHARED,
            2 => $extensions['values'][$extensions['id']]['version']
          ]),
        E::ts('Stripe: Missing Requirements'),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
    }

    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => 'firewall',
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $messages[] = new CRM_Utils_Check_Message(
        'stripe_recommended',
        E::ts('If you are using Stripe to accept payments on public forms (eg. contribution/event registration forms) it is recommended that you install the <strong><a href="https://lab.civicrm.org/extensions/firewall">firewall</a></strong> extension.
        Some sites have become targets for spammers who use the payment endpoint to try and test credit cards by submitting invalid payments to your Stripe account.'),
        E::ts('Recommended Extension: firewall'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-lightbulb-o'
      );
    }
  }

}

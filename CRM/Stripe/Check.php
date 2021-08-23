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
 * Class CRM_Stripe_Check
 */
class CRM_Stripe_Check {

  /**
   * @var string
   */
  const API_VERSION = '2020-08-27';
  const API_MIN_VERSION = '2019-12-03';

  /**
   * @var string
   */
  const MIN_VERSION_MJWSHARED = '1.0';
  const MIN_VERSION_SWEETALERT = '1.5';
  const MIN_VERSION_FIREWALL = '1.1.3';

  public static function checkRequirements(&$messages) {
    self::checkExtensionMjwshared($messages);
    self::checkExtensionFirewall($messages);
    self::checkExtensionSweetalert($messages);
    self::checkIfSeparateMembershipPaymentEnabled($messages);
    self::checkUpgradeMessages($messages);
  }

  /**
   * @param array $messages
   *
   * @throws \CiviCRM_API3_Exception
   */
  private static function checkExtensionMjwshared(&$messages) {
    // mjwshared: required. Requires min version
    $extensionName = 'mjwshared';
    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . E::SHORT_NAME . '_requirements',
        E::ts('The <em>%1</em> extension requires the <em>Payment Shared</em> extension which is not installed. See <a href="%2" target="_blank">details</a> for more information.',
          [
            1 => ucfirst(E::SHORT_NAME),
            2 => 'https://civicrm.org/extensions/mjwshared',
          ]
        ),
        E::ts('%1: Missing Requirements', [1 => ucfirst(E::SHORT_NAME)]),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
      return;
    }
    if (isset($extensions['id']) && $extensions['values'][$extensions['id']]['status'] === 'installed') {
      self::requireExtensionMinVersion($messages, $extensionName, self::MIN_VERSION_MJWSHARED, $extensions['values'][$extensions['id']]['version']);
    }
  }

  /**
   * @param array $messages
   *
   * @throws \CiviCRM_API3_Exception
   */
  private static function checkExtensionFirewall(&$messages) {
    $extensionName = 'firewall';

    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'stripe_recommended',
        E::ts('If you are using Stripe to accept payments on public forms (eg. contribution/event registration forms) it is recommended that you install the <strong><a href="https://lab.civicrm.org/extensions/firewall">firewall</a></strong> extension.
        Some sites have become targets for spammers who use the payment endpoint to try and test credit cards by submitting invalid payments to your Stripe account.'),
        E::ts('Recommended Extension: firewall'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-lightbulb-o'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
    }
    if (isset($extensions['id']) && $extensions['values'][$extensions['id']]['status'] === 'installed') {
      self::requireExtensionMinVersion($messages, $extensionName, CRM_Stripe_Check::MIN_VERSION_FIREWALL, $extensions['values'][$extensions['id']]['version']);
    }
  }

  /**
   * @param array $messages
   *
   * @throws \CiviCRM_API3_Exception
   */
  private static function checkExtensionSweetalert(&$messages) {
    // sweetalert: recommended. If installed requires min version
    $extensionName = 'sweetalert';
    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'stripe_recommended',
        E::ts('It is recommended that you install the <strong><a href="https://civicrm.org/extensions/sweetalert">sweetalert</a></strong> extension.
        This allows the stripe extension to show useful messages to the user when processing payment.
        If this is not installed it will fallback to the browser "alert" message but you will
        not see some messages (such as <em>we are pre-authorizing your card</em> and <em>please wait</em>) and the feedback to the user will not be as helpful.'),
        E::ts('Recommended Extension: sweetalert'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-lightbulb-o'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
      return;
    }
    if (isset($extensions['id']) && $extensions['values'][$extensions['id']]['status'] === 'installed') {
      self::requireExtensionMinVersion($messages, $extensionName, CRM_Stripe_Check::MIN_VERSION_SWEETALERT, $extensions['values'][$extensions['id']]['version']);
    }
  }

  /**
   * @param array $messages
   * @param string $extensionName
   * @param string $minVersion
   * @param string $actualVersion
   */
  private static function requireExtensionMinVersion(&$messages, $extensionName, $minVersion, $actualVersion) {
    if (version_compare($actualVersion, $minVersion) === -1) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . $extensionName . E::SHORT_NAME . '_requirements',
        E::ts('The %1 extension requires the %2 extension version %3 or greater but your system has version %4.',
          [
            1 => ucfirst(E::SHORT_NAME),
            2 => $extensionName,
            3 => $minVersion,
            4 => $actualVersion
          ]),
        E::ts('%1: Missing Requirements', [1 => ucfirst(E::SHORT_NAME)]),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
      $message->addAction(
        E::ts('Upgrade now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
    }
  }

  /**
   * @throws \CiviCRM_API3_Exception
   */
  private static function checkIfSeparateMembershipPaymentEnabled(&$messages) {
    $membershipBlocks = civicrm_api3('MembershipBlock', 'get', [
      'is_separate_payment' => 1,
      'is_active' => 1,
    ]);
    if ($membershipBlocks['count'] === 0) {
      return;
    }
    else {
      $contributionPagesToCheck = [];
      foreach ($membershipBlocks['values'] as $blockID => $blockDetails) {
        if ($blockDetails['entity_table'] !== 'civicrm_contribution_page') {
          continue;
        }
        $contributionPagesToCheck[] = $blockDetails['entity_id'];
      }
      $stripePaymentProcessorIDs = civicrm_api3('PaymentProcessor', 'get', [
        'return' => ['id'],
        'payment_processor_type_id' => 'Stripe',
      ]);
      $stripePaymentProcessorIDs = CRM_Utils_Array::collect('id', $stripePaymentProcessorIDs['values']);
      if (!empty($contributionPagesToCheck)) {
        $contributionPages = civicrm_api3('ContributionPage', 'get', [
          'return' => ['payment_processor'],
          'id' => ['IN' => $contributionPagesToCheck],
          'is_active' => 1,
        ]);
        foreach ($contributionPages['values'] as $contributionPage) {
          $enabledPaymentProcessors = explode(CRM_Core_DAO::VALUE_SEPARATOR, $contributionPage['payment_processor']);
          foreach ($enabledPaymentProcessors as $enabledID) {
            if (in_array($enabledID, $stripePaymentProcessorIDs)) {
              $message = new CRM_Utils_Check_Message(
                __FUNCTION__ . 'stripe_requirements',
                E::ts('Stripe does not support "Separate Membership Payment" on contribution pages but you have one or more contribution pages with
                that setting enabled and Stripe as the payment processor (found on contribution page ID: %1).',
                  [
                    1 => $contributionPage['id'],
                  ]),
                E::ts('Stripe: Invalid configuration'),
                \Psr\Log\LogLevel::ERROR,
                'fa-money'
              );
              $messages[] = $message;
              return;
            }
          }
        }
      }
    }
  }

  /**
   * Display messages on version upgrade
   *
   * @param array $messages
   */
  private static function checkUpgradeMessages(&$messages) {
    // @todo: When we release 6.7 we need to think about if this should be displayed or not (consider install/upgrade).
    if ((bool) \Civi::settings()->get('stripe_upgrade66message')) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'stripe_upgrade66message',
        E::ts('Thankyou for upgrading to Stripe 6.6. You MUST check your configuration to make sure it will continue working:
        <ul>
          <li><strong>Access AJAX API</strong> permission is required for all users that make payments using Stripe (including the anonymous user).
          You need to check that all users making Stripe payments have the CiviCRM permission <strong>Access AJAX API</strong> otherwise payments will fail.</li>
          <li>Billing address fields are now disabled by default (if you require additional fields collect them via a profile).
          If you require the billing address fields you can enable them in <em>Administer->CiviContribute->Stripe Settings->Disable billing address fields</em>.
          They will be removed in a future version - if this is a problem please contact <a href="https://mjw.pt/support">MJW</a> and let us know why!</li>
          <li><a href="https://docs.civicrm.org/mjwshared/en/latest/refunds/">Refund UI</a> is now enabled by default.</li>
        </ul>'
        ),
        E::ts('Stripe: You need to check some settings now that you have upgraded to 6.6'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-money'
      );
      $message->addAction(
        E::ts('I\'ve read this'),
        E::ts('I\'ve read this and made any necessary configuration changes - don\'t show me this upgrade message any
      more'),
        'api3',
        ['Setting', 'create', ['stripe_upgrade66message' => 0]]
      );
      $messages[] = $message;
    }
  }

}

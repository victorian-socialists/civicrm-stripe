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

require_once 'stripe.civix.php';
require_once __DIR__.'/vendor/autoload.php';

use CRM_Stripe_ExtensionUtil as E;

/**
 * Implementation of hook_civicrm_config().
 */
function stripe_civicrm_config(&$config) {
  _stripe_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu().
 *
 * @param $files array(string)
 */
function stripe_civicrm_xmlMenu(&$files) {
  _stripe_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install().
 */
function stripe_civicrm_install() {
  _stripe_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_postInstall
 */
function stripe_civicrm_postInstall() {
  _stripe_civix_civicrm_postInstall();
}

/**
 * Implementation of hook_civicrm_uninstall().
 */
function stripe_civicrm_uninstall() {
  _stripe_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable().
 */
function stripe_civicrm_enable() {
  _stripe_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable().
 */
function stripe_civicrm_disable() {
  return _stripe_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 */
function stripe_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _stripe_civix_civicrm_upgrade($op, $queue);
}


/**
 * Implementation of hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function stripe_civicrm_managed(&$entities) {
  _stripe_civix_civicrm_managed($entities);
}


/**
 * Implements hook_civicrm_entityTypes().
 */
function stripe_civicrm_entityTypes(&$entityTypes) {
  _stripe_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 */
function stripe_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _stripe_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Add stripe.js to forms, to generate stripe token
 * hook_civicrm_alterContent is not called for all forms (eg. CRM_Contribute_Form_Contribution on backend)
 *
 * @param string $formName
 * @param \CRM_Core_Form $form
 *
 * @throws \CRM_Core_Exception
 */
function stripe_civicrm_buildForm($formName, &$form) {
  // Don't load stripe js on ajax forms
  if (CRM_Utils_Request::retrieveValue('snippet', 'String') === 'json') {
    return;
  }

  switch ($formName) {
    case 'CRM_Contribute_Form_Contribution_ThankYou':
    case 'CRM_Event_Form_Registration_ThankYou':
      \Civi::resources()->addScriptUrl(
        \Civi::service('asset_builder')->getUrl(
          'civicrmStripeConfirm.js',
          [
            'path' => \Civi::resources()->getPath(E::LONG_NAME, 'js/civicrmStripeConfirm.js'),
            'mimetype' => 'application/javascript',
          ]
        ),
        // Load after any other scripts
        100,
        'page-footer'
      );

      // This is a fairly nasty way of matching and retrieving our paymentIntent as it is no longer available.
      $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String');
      if (!empty($qfKey)) {
        try {
          $paymentIntent = civicrm_api3('StripePaymentintent', 'getsingle', [
            'return' => [
              'paymentintent_id',
              'status',
              'contribution_id'
            ],
            'identifier' => $qfKey
          ]);
        }
        catch (Exception $e) {
          // If we can't find a paymentIntent assume it was not a Stripe transaction and don't load Stripe vars
          // This could happen for various reasons (eg. amount = 0).
          return;
        }
      }

      if (empty($paymentIntent['contribution_id'])) {
        // If we now have a contribution ID try and update it so we can cross-reference the paymentIntent
        $contributionId = $form->getVar('_values')['contributionId'];
        if (!empty($contributionId)) {
          civicrm_api3('StripePaymentintent', 'create', [
            'id' => $paymentIntent['id'],
            'contribution_id' => $contributionId
          ]);
        }
      }

      /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
      $paymentProcessor = \Civi\Payment\System::singleton()->getById($form->_paymentProcessor['id']);
      $paymentProcessor->setAPIParams();
      try {
        $intent = \Stripe\PaymentIntent::retrieve($paymentIntent['paymentintent_id']);
        // We need the confirmation_method to decide whether to use handleCardAction (manual) or handleCardPayment (automatic) on the js side
        $jsVars = [
          'id' => $form->_paymentProcessor['id'],
          'paymentIntentID' => $paymentIntent['paymentintent_id'],
          'paymentIntentStatus' => $intent->status,
          'paymentIntentMethod' => $intent->confirmation_method,
          'publishableKey' => CRM_Core_Payment_Stripe::getPublicKeyById($form->_paymentProcessor['id']),
          'locale' => CRM_Core_I18n::getLocale(),
          'apiVersion' => CRM_Stripe_Check::API_VERSION,
          'jsDebug' => (boolean) \Civi::settings()->get('stripe_jsdebug'),
          'csrfToken' => class_exists('\Civi\Firewall\Firewall') ? \Civi\Firewall\Firewall::getCSRFToken() : NULL,
          'country' => CRM_Core_BAO_Country::defaultContactCountry(),
        ];
        \Civi::resources()->addVars(E::SHORT_NAME, $jsVars);
      }
      catch (Exception $e) {
        // Do nothing, we won't attempt further stripe processing
      }
      break;

    case 'CRM_Admin_Form_PaymentProcessor':
      // Hide configuration fields that we don't use
      foreach (['accept_credit_cards', 'url_site', 'url_recur', 'test_url_site', 'test_url_recur'] as $element)
      if ($form->elementExists($element)) {
        $form->removeElement($element);
      }
      break;
  }
}

/**
 * Implements hook_civicrm_check().
 *
 * @throws \CiviCRM_API3_Exception
 */
function stripe_civicrm_check(&$messages) {
  CRM_Stripe_Check::checkRequirements($messages);
  // If we didn't install mjwshared yet check requirements but don't crash when checking webhooks
  if (trait_exists('CRM_Mjwshared_WebhookTrait')) {
    CRM_Stripe_Webhook::check($messages);
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function stripe_civicrm_navigationMenu(&$menu) {
  _stripe_civix_insert_navigation_menu($menu, 'Administer/CiviContribute', [
    'label' => E::ts('Stripe Settings'),
    'name' => 'stripe_settings',
    'url' => 'civicrm/admin/setting/stripe',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _stripe_civix_navigationMenu($menu);
}

/**
 * Implements hook_civicrm_alterLogTables().
 *
 * Exclude tables from logging tables since they hold mostly temp data.
 */
function stripe_civicrm_alterLogTables(&$logTableSpec) {
  unset($logTableSpec['civicrm_stripe_paymentintent']);
}

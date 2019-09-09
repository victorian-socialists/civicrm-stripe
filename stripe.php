<?php

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
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
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
 * Implements hook_civicrm_alterSettingsFolders().
 */
function stripe_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _stripe_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implementation of hook_civicrm_validateForm().
 *
 * Prevent server validation of cc fields
 *
 * @param $formName - the name of the form
 * @param $fields - Array of name value pairs for all 'POST'ed form values
 * @param $files - Array of file properties as sent by PHP POST protocol
 * @param $form - reference to the form object
 * @param $errors - Reference to the errors array.
 *
 */

function stripe_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if (empty($form->_paymentProcessor['payment_processor_type'])) {
    return;
  }
  // If Stripe is active here.
  if ($form->_paymentProcessor['class_name'] == 'Payment_Stripe') {
    if (isset($form->_elementIndex['stripe_token'])) {
      if ($form->elementExists('credit_card_number')) {
        $cc_field = $form->getElement('credit_card_number');
        $form->removeElement('credit_card_number', true);
        $form->addElement($cc_field);
      }
      if ($form->elementExists('cvv2')) {
        $cvv2_field = $form->getElement('cvv2');
        $form->removeElement('cvv2', true);
        $form->addElement($cvv2_field);
      }
    }
  } else {
    return;
  }
}

/**
 * Implementation of hook_civicrm_alterContent
 *
 * Adding civicrm_stripe.js in a way that works for webforms and (some) Civi forms.
 * hook_civicrm_buildForm is not called for webforms
 *
 * @return void
 */
function stripe_civicrm_alterContent( &$content, $context, $tplName, &$object ) {
  /* Adding stripe js:
   * - Webforms don't get scripts added by hook_civicrm_buildForm so we have to user alterContent
   * - (Webforms still call buildForm and it looks like they are added but they are not,
   *   which is why we check for $object instanceof CRM_Financial_Form_Payment here to ensure that
   *   Webforms always have scripts added).
   * - Almost all forms have context = 'form' and a paymentprocessor object.
   * - Membership backend form is a 'page' and has a _isPaymentProcessor=true flag.
   *
   */
  if (($context == 'form' && !empty($object->_paymentProcessor['class_name']))
    || (($context == 'page') && !empty($object->_isPaymentProcessor))) {
    if (!isset(\Civi::$statics[E::LONG_NAME]['stripeJSLoaded']) || $object instanceof CRM_Financial_Form_Payment) {
      $stripeJSURL = \Civi::resources()->getUrl(E::LONG_NAME, 'js/civicrm_stripe.js');
      $content .= "<script src='{$stripeJSURL}'></script>";
      \Civi::$statics[E::LONG_NAME]['stripeJSLoaded'] = TRUE;
    }
  }
}

/**
 * Add stripe.js to forms, to generate stripe token
 * hook_civicrm_alterContent is not called for all forms (eg. CRM_Contribute_Form_Contribution on backend)
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 */
function stripe_civicrm_buildForm($formName, &$form) {
  // Don't load stripe js on ajax forms
  if (CRM_Utils_Request::retrieveValue('snippet', 'String') === 'json') {
    return;
  }

  // Load stripe.js on all civi forms per stripe requirements
  if (!isset(\Civi::$statics[E::LONG_NAME]['stripeJSLoaded'])) {
    \Civi::resources()->addScriptUrl('https://js.stripe.com/v3');
    \Civi::$statics[E::LONG_NAME]['stripeJSLoaded'] = TRUE;
  }
}

/**
 * Implements hook_civicrm_postProcess().
 *
 * @param string $formName
 * @param \CRM_Core_Form $form
 */
function stripe_civicrm_postProcess($formName, &$form) {
  // We're only interested in forms that have a paymentprocessor
  if (empty($form->get('paymentProcessor')) || ($form->get('paymentProcessor')['class_name'] !== 'Payment_Stripe')) {
    return;
  }

  // Retrieve the paymentIntentID that was posted along with the form and add it to the form params
  //  This allows multi-page checkout to work (eg. register->confirm->thankyou)
  $params = $form->get('params');
  if (!$params) {
    // @fixme Hack for contributionpages - see https://github.com/civicrm/civicrm-core/pull/15252
    $params = $form->getVar('_params');
    $hackForContributionPages = TRUE;
  }
  if (isset($params['amount'])) {
    // Contribution pages have params directly in the main array
    $paymentParams = &$params;
  }
  elseif (isset($params[0]['amount'])) {
    // Event registration pages have params in a sub-array
    $paymentParams = &$params[0];
  }
  else {
    return;
  }
  $paymentIntentID = CRM_Utils_Request::retrieveValue('paymentIntentID', 'String');
  if ($paymentIntentID) {
    $paymentParams['paymentIntentID'] = $paymentIntentID;
    $form->set('params', $params);
    if (isset($hackForContributionPages)) {
      // @fixme Hack for contributionpages - see https://github.com/civicrm/civicrm-core/pull/15252
      CRM_Core_Session::singleton()->set('stripePaymentIntent', $paymentIntentID);
    }
  }
}

/**
 * Implements hook_civicrm_check().
 */
function stripe_civicrm_check(&$messages) {
  CRM_Stripe_Webhook::check($messages);
  CRM_Stripe_Check::checkRequirements($messages);
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function stripe_civicrm_navigationMenu(&$menu) {
  _stripe_civix_insert_navigation_menu($menu, 'Administer/CiviContribute', array(
    'label' => E::ts('Stripe Settings'),
    'name' => 'stripe_settings',
    'url' => 'civicrm/admin/setting/stripe',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _stripe_civix_navigationMenu($menu);
}

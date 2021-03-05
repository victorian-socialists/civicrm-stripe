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
 * Stripe Invoice API
 *
 */

/**
 * StripeInvoice.Get API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_invoice_process_spec(&$spec) {
  $spec['invoice_id']['title'] = E::ts('Stripe Invoice ID');
  $spec['invoice_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['payment_processor_id']['title'] = E::ts('Payment Processor ID');
  $spec['payment_processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['is_email_receipt']['title'] = E::ts('Send Email Receipt');
  $spec['is_email_receipt']['type'] = CRM_Utils_Type::T_BOOLEAN;
}

function civicrm_api3_stripe_invoice_process($params) {
  $ipnClass = new CRM_Core_Payment_StripeIPN();
  $ipnClass->setPaymentProcessor($params['payment_processor_id']);
  $stripeInvoiceObject = $ipnClass->getPaymentProcessor()->stripeClient->invoices->retrieve($params['invoice_id']);

  if ($stripeInvoiceObject->paid === TRUE) {
    $ipnClass->setEventID('invoice');
    $ipnClass->setEventType('invoice.payment_succeeded');
    $ipnClass->setVerifyData(FALSE);
    $ipnClass->setData((Object) ['object' => $stripeInvoiceObject]);
    $ipnClass->setExceptionMode(FALSE);
    if (isset($params['is_email_receipt'])) {
      $ipnClass->setSendEmailReceipt($params['is_email_receipt']);
    }

    if ($ipnClass->processWebhook()) {
      return civicrm_api3_create_success([TRUE], $params);
    }
    else {
      return civicrm_api3_create_error('Failed to process invoice');
    }
  }
  else {
    return civicrm_api3_create_error('Invoice not paid');
  }
}


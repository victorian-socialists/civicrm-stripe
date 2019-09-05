<?php

/**
 * https://civicrm.org/licensing
 */

/**
 * Class CRM_Stripe_AJAX
 */
class CRM_Stripe_AJAX {

  public static function getClientSecret() {
    $amount = CRM_Utils_Request::retrieveValue('amount', 'Money', NULL, TRUE);
    $currency = CRM_Utils_Request::retrieveValue('currency', 'String', CRM_Core_Config::singleton()->defaultCurrency);
    $processorID = CRM_Utils_Request::retrieveValue('id', 'Integer', NULL, TRUE);
    $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $processorID]));
    $processor->setAPIParams();

    $intent = \Stripe\PaymentIntent::create([
      'amount' => $processor->getAmount(['amount' => $amount]),
      'currency' => $currency,
    ]);
    CRM_Utils_JSON::output(['client_secret' => $intent->client_secret]);
  }

  public static function confirmPayment() {
    $paymentMethodID = CRM_Utils_Request::retrieveValue('payment_method_id', 'String', NULL, TRUE);
    $paymentIntentID = CRM_Utils_Request::retrieveValue('payment_intent_id', 'String');
    $amount = CRM_Utils_Request::retrieveValue('amount', 'Money', NULL, TRUE);
    $currency = CRM_Utils_Request::retrieveValue('currency', 'String', CRM_Core_Config::singleton()->defaultCurrency);
    $processorID = CRM_Utils_Request::retrieveValue('id', 'Integer', NULL, TRUE);
    $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $processorID]));
    $processor->setAPIParams();

    if ($paymentIntentID) {
      $intent = \Stripe\PaymentIntent::retrieve($paymentIntentID);
      $intent->confirm([
        'payment_method' => $paymentMethodID,
      ]);
    }
    else {
      $intent = \Stripe\PaymentIntent::create([
        'payment_method' => $paymentMethodID,
        'amount' => $processor->getAmount(['amount' => $amount]),
        'currency' => $currency,
        'confirmation_method' => 'manual',
        'capture_method' => 'manual', // authorize the amount but don't take from card yet
        'setup_future_usage' => 'off_session', // Setup the card to be saved and used later
        'confirm' => TRUE,
      ]);
    }

    self::generatePaymentResponse($intent);
  }

  private static function generatePaymentResponse($intent) {
    if ($intent->status == 'requires_action' &&
      $intent->next_action->type == 'use_stripe_sdk') {
      // Tell the client to handle the action
      CRM_Utils_JSON::output([
        'requires_action' => true,
        'payment_intent_client_secret' => $intent->client_secret,
        //'payment_method_id' => $intent->payment_method,
      ]);
    } else if ($intent->status == 'requires_capture') {
      // The payment intent has been confirmed, we just need to capture the payment
      // Handle post-payment fulfillment
      CRM_Utils_JSON::output([
        'success' => true,
        'paymentIntent' => ['id' => $intent->id],
      ]);
    } else {
      // Invalid status
      CRM_Utils_JSON::output(['error' => ['message' => 'Invalid PaymentIntent status']]);
    }
  }

}

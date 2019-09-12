<?php

/**
 * https://civicrm.org/licensing
 */

/**
 * Class CRM_Stripe_AJAX
 */
class CRM_Stripe_AJAX {

  /**
   * Generate the paymentIntent for civicrm_stripe.js
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function confirmPayment() {
    $paymentMethodID = CRM_Utils_Request::retrieveValue('payment_method_id', 'String');
    $paymentIntentID = CRM_Utils_Request::retrieveValue('payment_intent_id', 'String');
    $amount = CRM_Utils_Request::retrieveValue('amount', 'Money', NULL, TRUE);
    $currency = CRM_Utils_Request::retrieveValue('currency', 'String', CRM_Core_Config::singleton()->defaultCurrency);
    $processorID = CRM_Utils_Request::retrieveValue('id', 'Integer', NULL, TRUE);
    $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $processorID]));
    $processor->setAPIParams();

    if (!$paymentIntentID) {
      try {
        $intent = \Stripe\PaymentIntent::create([
          'payment_method' => $paymentMethodID,
          'amount' => $processor->getAmount(['amount' => $amount]),
          'currency' => $currency,
          'confirmation_method' => 'manual',
          'capture_method' => 'manual',
          // authorize the amount but don't take from card yet
          'setup_future_usage' => 'off_session',
          // Setup the card to be saved and used later
          'confirm' => TRUE,
        ]);
      }
      catch (Exception $e) {
        CRM_Utils_JSON::output(['error' => ['message' => $e->getMessage()]]);
      }
    }

    self::generatePaymentResponse($intent);
  }

  /**
   * Generate the json response for civicrm_stripe.js
   *
   * @param \Stripe\PaymentIntent $intent
   */
  private static function generatePaymentResponse($intent) {
    if ($intent->status == 'requires_action' &&
      $intent->next_action->type == 'use_stripe_sdk') {
      // Tell the client to handle the action
      CRM_Utils_JSON::output([
        'requires_action' => true,
        'payment_intent_client_secret' => $intent->client_secret,
      ]);
    } else if (($intent->status == 'requires_capture') || ($intent->status == 'requires_confirmation')) {
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

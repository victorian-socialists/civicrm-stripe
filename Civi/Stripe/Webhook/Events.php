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

namespace Civi\Stripe\Webhook;

class Events {

  use \CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \CRM_Core_Payment_Stripe Payment processor
   */
  private $paymentProcessor;

  /**
   * @param string $eventID
   *
   * @return void
   */
  public function setEventID(string $eventID): void {
    $this->eventID = $eventID;
  }

  /**
   * @param string $eventType
   *
   * @return void
   */
  public function setEventType(string $eventType): void {
    $this->eventType = $eventType;
  }

  /**
   * @param \Stripe\StripeObject|\PropertySpy $data
   *
   * @return void
   */
  public function setData($data): void {
    $this->data = $data;
  }

  /**
   * @return \stdClass
   */
  private function getResultObject() {
    $return = new \stdClass();
    $return->message = '';
    $return->ok = FALSE;
    $return->exception = NULL;
    return $return;
  }

  /**
   * @param string $name The key of the required value
   * @param string $dataType The datatype of the required value (eg. String)
   *
   * @return int|mixed|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  private function getValueFromStripeObject(string $name, string $dataType) {
    $value = \CRM_Stripe_Api::getObjectParam($name, $this->getData()->object);
    $value = \CRM_Utils_Type::validate($value, $dataType, FALSE);
    return $value;
  }

  /**
   * A) A one-off contribution will have trxn_id == stripe.charge_id
   * B) A contribution linked to a recur (stripe subscription):
   *   1. May have the trxn_id == stripe.subscription_id if the invoice was not generated at the time the contribution
   * was created
   *     (Eg. the recur was setup with a future recurring start date).
   *     This will be updated to trxn_id == stripe.invoice_id when a suitable IPN is received
   *     @todo: Which IPN events will update this?
   *   2. May have the trxn_id == stripe.invoice_id if the invoice was generated at the time the contribution was
   *   created OR the contribution has been updated by the IPN when the invoice was generated.
   *
   * @param string $chargeID Optional, one of chargeID, invoiceID, subscriptionID must be specified
   * @param string $invoiceID
   * @param string $subscriptionID
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function findContribution(string $chargeID = '', string $invoiceID = '', string $subscriptionID = ''): array {
    $paymentParams = [
      'contribution_test' => $this->getPaymentProcessor()->getIsTestMode(),
    ];

    // A) One-off contribution
    if (!empty($chargeID)) {
      $paymentParams['trxn_id'] = $chargeID;
      $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
    }

    // B2) Contribution linked to subscription and we have invoice_id
    // @todo there is a case where $contribution is not defined (i.e. if charge_id is empty)
    if (!$contributionApi3['count']) {
      unset($paymentParams['trxn_id']);
      if (!empty($invoiceID)) {
        $paymentParams['order_reference'] = $invoiceID;
        $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // B1) Contribution linked to subscription and we have subscription_id
    // @todo there is a case where $contribution is not defined (i.e. if charge_id, invoice_id are empty)
    if (!$contributionApi3['count']) {
      unset($paymentParams['trxn_id']);
      if (!empty($subscriptionID)) {
        $paymentParams['order_reference'] = $subscriptionID;
        $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // @todo there is a case where $contribution is not defined (i.e. if charge_id, invoice_id, subscription_id are empty)
    if (!$contributionApi3['count']) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->getPaymentProcessor()->getPaymentProcessorLabel() . 'No matching contributions for event ' . $this->getEventID();
        \Civi::log()->debug($message);
      }
      $result = [];
      \CRM_Mjwshared_Hook::webhookEventNotMatched('stripe', $this, 'contribution_not_found', $result);
      if (empty($result['contribution'])) {
        return [];
      }
      $contribution = $result['contribution'];
    }
    else {
      $contribution = $contributionApi3['values'][$contributionApi3['id']];
    }
    return $contribution;
  }

  /**
   * Process the charge.refunded event from Stripe
   *
   * @return \stdClass
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doChargeRefunded() {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object['object'] ?? '') !== 'charge') {
      $return->message = 'doChargeRefunded Invalid object type';
      return $return;
    }

    // Cancelling an uncaptured paymentIntent triggers charge.refunded but we don't want to process that
    if (empty(\CRM_Stripe_Api::getObjectParam('captured', $this->getData()->object))) {
      $return->ok = TRUE;
      return $return;
    }

    // Charge ID is required
    $chargeID = $this->getValueFromStripeObject('charge_id', 'String');
    if (!$chargeID) {
      $return->message = 'doChargeRefunded Missing charge_id';
      return $return;
    }

    // Invoice ID is optional
    $invoiceID = $this->getValueFromStripeObject('invoice_id', 'String');

    // This gives us the refund date + reason code
    $refunds = $this->getPaymentProcessor()->stripeClient->refunds->all(['charge' => $chargeID, 'limit' => 1]);
    $refund = $refunds->data[0];

    // Stripe does not refund fees - see https://support.stripe.com/questions/understanding-fees-for-refunded-payments
    // This gives us the actual amount refunded
    $amountRefunded = \CRM_Stripe_Api::getObjectParam('amount_refunded', $this->getData()->object);

    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    $contribution = $this->findContribution($chargeID, $invoiceID);
    if (empty($contribution)) {
      $return->message = 'doChargeRefunded Contribution not found';
      return $return;
    }

    if (isset($contribution['payments'])) {
      foreach ($contribution['payments'] as $payment) {
        if ($payment['trxn_id'] === $refund->id) {
          $return->message = 'Refund ' . $refund->id . ' already recorded in CiviCRM';
          return $return;
        }
        if ($payment['trxn_id'] === $chargeID) {
          // This triggers the financial transactions/items to be updated correctly.
          $cancelledPaymentID = $payment['id'];
        }
      }
    }

    $refundParams = [
      'contribution_id' => $contribution['id'],
      'total_amount' => 0 - abs($amountRefunded),
      'trxn_date' => date('YmdHis', $refund->created),
      'trxn_result_code' => $refund->reason,
      'fee_amount' => 0,
      'trxn_id' => $refund->id,
      'order_reference' => $invoiceID,
    ];

    if (!empty($cancelledPaymentID)) {
      $refundParams['cancelled_payment_id'] = $cancelledPaymentID;
    }

    $lock = \Civi::lockManager()->acquire('data.contribute.contribution.' . $refundParams['contribution_id']);
    if (!$lock->isAcquired()) {
      \Civi::log()->error('Could not acquire lock to record refund for contribution: ' . $refundParams['contribution_id']);
    }
    $refundPayment = civicrm_api3('Payment', 'get', [
      'trxn_id' => $refundParams['trxn_id'],
      'total_amount' => $refundParams['total_amount'],
    ]);
    if (!empty($refundPayment['count'])) {
      $return->message = 'OK - refund already recorded';
    }
    else {
      $this->updateContributionRefund($refundParams);
      $return->message = 'OK - refund recorded';
    }
    $lock->release();
    return $return;
  }

}

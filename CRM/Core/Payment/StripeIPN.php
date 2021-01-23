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

/**
 * Class CRM_Core_Payment_StripeIPN
 */
class CRM_Core_Payment_StripeIPN {

  use CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \CRM_Core_Payment_Stripe Payment processor
   */
  protected $_paymentProcessor;

  /**
   * @var \Stripe\StripeObject
   */
  protected $_inputParameters;

  /**
   * The CiviCRM contact ID that maps to the Stripe customer
   *
   * @var int
   */
  protected $contactID = NULL;

  /**
   * Do we send an email receipt for each contribution?
   *
   * @var int
   */
  protected $is_email_receipt = NULL;

  // Properties of the event.
  protected $event_type = NULL;
  protected $subscription_id = NULL;
  protected $customer_id = NULL;
  protected $charge_id = NULL;
  protected $previous_plan_id = NULL;
  protected $plan_amount = NULL;
  protected $frequency_interval = NULL;
  protected $frequency_unit = NULL;
  protected $plan_start = NULL;

  /**
   * @var int The recurring contribution ID (linked to Stripe Subscription) (if available)
   */
  protected $contribution_recur_id = NULL;

  /**
   * @var string The Stripe Event ID
   */
  protected $event_id = NULL;

  /**
   * @var string The stripe Invoice ID (mapped to trxn_id on a contribution for recurring contributions)
   */
  protected $invoice_id = NULL;

  /**
   * @var string The date/time the charge was made
   */
  protected $receive_date = NULL;

  /**
   * @var float The amount paid
   */
  protected $amount = 0.0;

  /**
   * @var float The fee charged by Stripe
   */
  protected $fee = 0.0;

  /**
   * @var array The current contribution (linked to Stripe charge(single)/invoice(subscription)
   */
  protected $contribution = NULL;

  /**
   * CRM_Core_Payment_StripeIPN constructor.
   *
   * @param string $rawData
   *   json encoded string
   * @param bool $verifyRequest
   *   Should we check the received event is valid/matches what the payment provider has?
   */
  public function __construct($rawData, $verifyRequest = TRUE) {
    $data = json_decode($rawData);
    $this->setInputParameters($data, $verifyRequest);
  }

  /**
   * Store input array on the class.
   * We override base because our input parameter is an object
   *
   * @param \Stripe\StripeObject $data
   */
  public function setInputParameters($data, $verifyRequest = TRUE) {
    // Determine the proper Stripe Processor ID so we can get the secret key
    // and initialize Stripe.
    $this->getPaymentProcessor();
    $this->_paymentProcessor->setAPIParams();

    if (!is_object($data)) {
      $this->exception('Invalid input data');
    }

    $this->event_id = $data->id;
    $this->event_type = $data->type;

    // Now re-retrieve the data from Stripe to ensure it's legit.
    // Special case if this is the test webhook
    if (substr($this->event_id, -15, 15) === '_00000000000000') {
      http_response_code(200);
      $test = (boolean) $this->_paymentProcessor->getPaymentProcessor()['is_test'] ? '(Test)' : '(Live)';
      $name = $this->_paymentProcessor->getPaymentProcessor()['name'];
      echo "Test webhook from Stripe ({$this->event_id}) received successfully by CiviCRM: {$name} {$test}.";
      exit();
    }

    if ($verifyRequest) {
      $this->_inputParameters = $this->_paymentProcessor->stripeClient->events->retrieve($data->id);
    }
    else {
      $this->_inputParameters = $data;
    }

    $this->invoice_id = $this->retrieve('invoice_id', 'String', FALSE);
    $this->charge_id = $this->retrieve('charge_id', 'String', FALSE);
    $this->subscription_id = $this->retrieve('subscription_id', 'String', FALSE);

    http_response_code(200);
  }

  /**
   * Get a parameter from the Stripe data object
   *
   * @param string $name
   * @param string $type
   * @param bool $abort
   *
   * @return int|mixed|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function retrieve($name, $type, $abort = TRUE) {
    $value = CRM_Stripe_Api::getObjectParam($name, $this->_inputParameters->data->object);
    $value = CRM_Utils_Type::validate($value, $type, FALSE);
    if ($abort && $value === NULL) {
      echo "Failure: Missing or invalid parameter " . CRM_Utils_Type::escape($name, 'String');
      $this->exception("Missing or invalid parameter {$name}");
    }
    return $value;
  }

  /**
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Stripe\Exception\UnknownApiErrorException
   */
  public function main() {
    // Return 200 OK for any events that we don't handle
    if (!in_array($this->event_type, CRM_Stripe_Webhook::getDefaultEnabledEvents())) {
      return TRUE;
    }

    $pendingContributionStatusID = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $failedContributionStatusID = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $statusesAllowedToComplete = [$pendingContributionStatusID, $failedContributionStatusID];

    // NOTE: If you add an event here make sure you add it to the webhook or it will never be received!
    switch($this->event_type) {
      case 'invoice.finalized':
        // An invoice has been created and finalized (ready for payment)
        // This usually happens automatically through a Stripe subscription
        if (!$this->setInfo()) {
          if (!$this->contribution_recur_id) {
            // We don't have a matching contribution or a recurring contribution - this was probably created outside of CiviCRM
            // @todo In the future we may want to match the customer->contactID and create a contribution to match.
            return TRUE;
          }
          else {
            if (!$this->createNextContributionForRecur()) {
              return FALSE;
            }
          }
          return TRUE;
        }
        // For a future recur start date we setup the initial contribution with the
        // Stripe subscriptionID because we didn't have an invoice.
        // Now we do we can map subscription_id to invoice_id so payment can be recorded
        // via subsequent IPN requests (eg. invoice.payment_succeeded)
        if ($this->contribution['trxn_id'] === $this->subscription_id) {
          $this->updateContribution([
            'contribution_id' => $this->contribution['id'],
            'trxn_id' => $this->invoice_id,
          ]);
        }
        break;

      case 'invoice.payment_succeeded':
        // Successful recurring payment. Either we are completing an existing contribution or it's the next one in a subscription
        if (!$this->setInfo()) {
          if (!$this->contribution_recur_id) {
            return TRUE;
          }
          else {
            // We have a recurring contribution but have not yet received invoice.finalized so we don't have the next contribution yet.
            // invoice.payment_succeeded sometimes comes before invoice.finalized so trigger the same behaviour here to create a new contribution
            if (!$this->createNextContributionForRecur()) {
              return FALSE;
            }
            // Now get the contribution we just created.
            $this->getContribution();
          }
        }
        if (civicrm_api3('Mjwpayment', 'get_payment', [
            'trxn_id' => $this->charge_id,
            'status_id' => 'Completed',
          ])['count'] > 0) {
          // Payment already recorded
          return TRUE;
        }

        // If contribution is in Pending or Failed state record payment and transition to Completed
        if (in_array($this->contribution['contribution_status_id'], $statusesAllowedToComplete)) {
          $params = [
            'contribution_id' => $this->contribution['id'],
            'trxn_date' => $this->receive_date,
            'order_reference' => $this->invoice_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'contribution_status_id' => $this->contribution['contribution_status_id'],
          ];
          $this->updateContributionCompleted($params);
          // Don't touch the contributionRecur as it's updated automatically by Contribution.completetransaction
        }
        $this->handleInstallmentsForSubscription();
        return TRUE;

      case 'invoice.payment_failed':
        // Failed recurring payment. Either we are failing an existing contribution or it's the next one in a subscription
        if (!$this->setInfo()) {
          return TRUE;
        }

        if ($this->contribution['contribution_status_id'] == $pendingContributionStatusID) {
          // If this contribution is Pending, set it to Failed.
          $params = [
            'contribution_id' => $this->contribution['id'],
            'order_reference' => $this->invoice_id,
            'cancel_date' => $this->receive_date,
            'cancel_reason' => $this->retrieve('failure_message', 'String'),
          ];
          $this->updateContributionFailed($params);
        }
        return TRUE;

      // One-time donation and per invoice payment.
      case 'charge.failed':
        // If we don't have a customer_id we can't do anything with it!
        // It's quite likely to be a fraudulent/spam so we ignore.
        if (empty(CRM_Stripe_Api::getObjectParam('customer_id', $this->_inputParameters->data->object))) {
          return TRUE;
        }

        if (!$this->setInfo()) {
          return TRUE;
        }
        $params = [
          'contribution_id' => $this->contribution['id'],
          'order_reference' => $this->invoice_id ?? $this->charge_id,
          'cancel_date' => $this->receive_date,
          'cancel_reason' => $this->retrieve('failure_message', 'String'),
        ];
        $this->updateContributionFailed($params);
        return TRUE;

      case 'charge.refunded':
        // Cancelling an uncaptured paymentIntent triggers charge.refunded but we don't want to process that
        if (empty(CRM_Stripe_Api::getObjectParam('captured', $this->_inputParameters->data->object))) {
          return TRUE;
        };
        // This charge was actually captured, so record the refund in CiviCRM
        if (!$this->setInfo()) {
          return TRUE;
        }
        // This gives us the actual amount refunded
        $amountRefunded = CRM_Stripe_Api::getObjectParam('amount_refunded', $this->_inputParameters->data->object);
        // This gives us the refund date + reason code
        $refunds = $this->_paymentProcessor->stripeClient->refunds->all(['charge' => $this->charge_id, 'limit' => 1]);
        // This gets the fee refunded
        $this->setBalanceTransactionDetails($refunds->data[0]->balance_transaction);

        $params = [
          'contribution_id' => $this->contribution['id'],
          'total_amount' => 0 - abs($amountRefunded),
          'trxn_date' => date('YmdHis', $refunds->data[0]->created),
          'trxn_result_code' => $refunds->data[0]->reason,
          'fee_amount' => 0 - abs($this->fee),
          'trxn_id' => $this->charge_id,
          'order_reference' => $this->invoice_id ?? NULL,
        ];
        if (isset($this->contribution['payments'])) {
          $refundStatusID = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Refunded');
          foreach ($this->contribution['payments'] as $payment) {
            if (((int) $payment['status_id'] === $refundStatusID) && ((float) $payment['total_amount'] === $params['total_amount'])) {
              // Already refunded
              return TRUE;
            }
          }
          // This triggers the financial transactions/items to be updated correctly.
          $params['cancelled_payment_id'] = reset($this->contribution['payments'])['id'];
        }

        $this->updateContributionRefund($params);
        return TRUE;

      case 'charge.succeeded':
        // For a recurring contribution we can process charge.succeeded once we receive the event with an invoice ID.
        // For a single contribution we can't process charge.succeeded because it only triggers BEFORE the charge is captured
        if (empty(CRM_Stripe_Api::getObjectParam('customer_id', $this->_inputParameters->data->object))) {
          return TRUE;
        }
      // Deliberately missing break here because we process charge.succeeded per charge.captured
      case 'charge.captured':
        // For a single contribution we have to use charge.captured because it has the customer_id.
        if (!$this->setInfo()) {
          return TRUE;
        }

        // We only process charge.captured for one-off contributions (see invoice.paid/invoice.payment_succeeded for recurring)
        if (!empty($this->contribution['contribution_recur_id'])) {
          return TRUE;
        }

        // If contribution is in Pending or Failed state record payment and transition to Completed
        if (in_array($this->contribution['contribution_status_id'], $statusesAllowedToComplete)) {
          $params = [
            'contribution_id' => $this->contribution['id'],
            'trxn_date' => $this->receive_date,
            'order_reference' => $this->invoice_id ?? $this->charge_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'contribution_status_id' => $this->contribution['contribution_status_id'],
          ];
          $this->updateContributionCompleted($params);
        }
        return TRUE;

      case 'customer.subscription.updated':
        if (!$this->getSubscriptionDetails()) {
          // Not matched with an existing subscription in CiviCRM
          return TRUE;
        }
        if (empty($this->previous_plan_id)) {
          // Not a plan change...don't care.
          return TRUE;
        }

        civicrm_api3('ContributionRecur', 'create', [
          'id' => $this->contribution_recur_id,
          'amount' => $this->plan_amount,
          'auto_renew' => 1,
          'created_date' => $this->plan_start,
          'frequency_unit' => $this->frequency_unit,
          'frequency_interval' => $this->frequency_interval,
        ]);
        return TRUE;

      case 'customer.subscription.deleted':
        // Subscription is cancelled
        if (!$this->getSubscriptionDetails()) {
          // Subscription was not found in CiviCRM
          return TRUE;
        }
        // Cancel the recurring contribution
        $this->updateRecurCancelled(['id' => $this->contribution_recur_id, 'cancel_date' => $this->retrieve('cancel_date', 'String', FALSE)]);
        return TRUE;
    }

    // Unhandled event
    return TRUE;
  }

  /**
   * Create the next contribution for a recurring contribution
   * This happens when Stripe generates a new invoice and notifies us (normally by invoice.finalized but invoice.payment_succeeded sometimes arrives first).
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function createNextContributionForRecur() {
    // We have a recurring contribution but no contribution so we'll repeattransaction
    // Stripe has generated a new invoice (next payment in a subscription) so we
    //   create a new contribution in CiviCRM
    $params = [
      'contribution_recur_id' => $this->contribution_recur_id,
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
      'receive_date' => $this->receive_date,
      'order_reference' => $this->invoice_id,
      'trxn_id' => $this->charge_id,
      'total_amount' => $this->amount,
      'fee_amount' => $this->fee,
    ];
    return $this->repeatContribution($params);
    // Don't touch the contributionRecur as it's updated automatically by Contribution.repeattransaction
  }

  /**
   * Gather and set info as class properties.
   *
   * Given the data passed to us via the Stripe Event, try to determine
   * as much as we can about this event and set that information as
   * properties to be used later.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function setInfo() {
    if (!$this->getCustomer()) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . ': ' . CRM_Stripe_Api::getParam('id', $this->_inputParameters) . ': Missing customer_id';
        Civi::log()->debug($message);
      }
      return FALSE;
    }

    $this->receive_date = $this->retrieve('receive_date', 'String', FALSE);
    $this->amount = $this->retrieve('amount', 'String', FALSE);

    if (($this->_inputParameters->data->object->object !== 'charge') && ($this->charge_id !== NULL)) {
      $charge = $this->_paymentProcessor->stripeClient->charges->retrieve($this->charge_id);
      $balanceTransactionID = CRM_Stripe_Api::getObjectParam('balance_transaction', $charge);
    }
    else {
      $balanceTransactionID = CRM_Stripe_Api::getObjectParam('balance_transaction', $this->_inputParameters->data->object);
    }
    $this->setBalanceTransactionDetails($balanceTransactionID);

    // Get the CiviCRM recurring contribution that matches the Stripe subscription (if we have one).
    $this->getSubscriptionDetails();
    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    return $this->getContribution();
  }

  /**
   * Get the recurring contribution from the Stripe event parameters (subscription_id)
   *   and set subscription_id, contribution_recur_id vars.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function getSubscriptionDetails() {
    if (!$this->subscription_id) {
      return FALSE;
    }

    // Get the recurring contribution record associated with the Stripe subscription.
    try {
      $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $this->subscription_id]);
      $this->contribution_recur_id = $contributionRecur['id'];
    }
    catch (Exception $e) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . ': ' . CRM_Stripe_Api::getParam('id', $this->_inputParameters) . ': Cannot find recurring contribution for subscription ID: ' . $this->subscription_id;
        Civi::log()->debug($message);
      }
      return FALSE;
    }
    $this->previous_plan_id = CRM_Stripe_Api::getParam('previous_plan_id', $this->_inputParameters);
    $this->plan_amount = $this->retrieve('plan_amount', 'String', FALSE);
    $this->frequency_interval = $this->retrieve('frequency_interval', 'String', FALSE);
    $this->frequency_unit = $this->retrieve('frequency_unit', 'String', FALSE);
    $this->plan_start = $this->retrieve('plan_start', 'String', FALSE);
    return TRUE;
  }

  /**
   * A) A one-off contribution will have trxn_id == stripe.charge_id
   * B) A contribution linked to a recur (stripe subscription):
   *   1. May have the trxn_id == stripe.subscription_id if the invoice was not generated at the time the contribution was created
   *     (Eg. the recur was setup with a future recurring start date).
   *     This will be updated to trxn_id == stripe.invoice_id when a suitable IPN is received
   *     @todo: Which IPN events will update this?
   *   2. May have the trxn_id == stripe.invoice_id if the invoice was generated at the time the contribution was created
   *     OR the contribution has been updated by the IPN when the invoice was generated.
   *
   * @return bool
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  private function getContribution() {
    $paymentParams = [
      'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
    ];

    // A) One-off contribution
    if ($this->charge_id) {
      $paymentParams['trxn_id'] = $this->charge_id;
    }
    $contribution = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);

    // B2) Contribution linked to subscription and we have invoice_id
    if (!$contribution['count']) {
      unset($paymentParams['trxn_id']);
      if ($this->invoice_id) {
        $paymentParams['order_reference'] = $this->invoice_id;
        $contribution = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // B1) Contribution linked to subscription and we have subscription_id
    if (!$contribution['count']) {
      unset($paymentParams['trxn_id']);
      if ($this->subscription_id) {
        $paymentParams['order_reference'] = $this->subscription_id;
        $contribution = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    if (!$contribution['count']) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . 'No matching contributions for event ' . CRM_Stripe_Api::getParam('id', $this->_inputParameters);
        Civi::log()->debug($message);
      }
      return FALSE;
    }

    $this->contribution = $contribution['values'][$contribution['id']];
    return TRUE;
  }

  /**
   * Get the Stripe customer details and match to the StripeCustomer record in CiviCRM
   * This gives us $this->contactID
   *
   * @return bool
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  private function getCustomer() {
    $this->customer_id = CRM_Stripe_Api::getObjectParam('customer_id', $this->_inputParameters->data->object);
    if (empty($this->customer_id)) {
      $this->exception('Missing customer_id!');
    }
    try {
      $customer = civicrm_api3('StripeCustomer', 'getsingle', [
        'id' => $this->customer_id,
      ]);
      $this->contactID = $customer['contact_id'];
      if ($this->_paymentProcessor->getID() !== (int) $customer['processor_id']) {
        $this->exception("Customer ({$this->customer_id}) and payment processor ID don't match (expected: {$customer['processor_id']}, actual: {$this->_paymentProcessor->getID()})");
      }
    }
    catch (Exception $e) {
      // Customer not found in CiviCRM
      if ((bool)\Civi::settings()->get('stripe_ipndebug') && !$this->contribution) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . 'Stripe Customer not found in CiviCRM for event ' . CRM_Stripe_Api::getParam('id', $this->_inputParameters);
        Civi::log()->debug($message);
      }
      return FALSE;
    }
    return TRUE;
  }

  private function setBalanceTransactionDetails($balanceTransactionID) {
    // Gather info about the amount and fee.
    // Get the Stripe charge object if one exists. Null charge still needs processing.
    // If the transaction is declined, there won't be a balance_transaction_id.
    $this->fee = 0.0;
    if ($balanceTransactionID) {
      try {
        $currency = $this->retrieve('currency', 'String', FALSE);
        $balanceTransaction = $this->_paymentProcessor->stripeClient->balanceTransactions->retrieve($balanceTransactionID);
        if ($currency !== $balanceTransaction->currency && !empty($balanceTransaction->exchange_rate)) {
          $this->fee = CRM_Stripe_Api::currencyConversion($balanceTransaction->fee, $balanceTransaction->exchange_rate, $currency);
        } else {
          // We must round to currency precision otherwise payments may fail because Contribute BAO saves but then
          // can't retrieve because it tries to use the full unrounded number when it only got saved with 2dp.
          $this->fee = round($balanceTransaction->fee / 100, CRM_Utils_Money::getCurrencyPrecision($currency));
        }
      }
      catch(Exception $e) {
        $this->exception('Error retrieving balance transaction. ' . $e->getMessage());
      }
    }
  }

  /**
   * This allows us to end a subscription once:
   *   a) We've reached the end date / number of installments
   *   b) The recurring contribution is marked as completed
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function handleInstallmentsForSubscription() {
    if ((!$this->contribution_recur_id) || (!$this->subscription_id)) {
      return;
    }

    $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $this->contribution_recur_id,
    ]);

    if (empty($contributionRecur['installments']) && empty($contributionRecur['end_date'])) {
      return;
    }

    $stripeSubscription = $this->_paymentProcessor->stripeClient->subscriptions->retrieve($this->subscription_id);
    // If we've passed the end date cancel the subscription
    if (($stripeSubscription->current_period_end >= strtotime($contributionRecur['end_date']))
      || ($contributionRecur['contribution_status_id']
        == CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Completed'))) {
      $this->_paymentProcessor->stripeClient->subscriptions->update($this->subscription_id, ['cancel_at_period_end' => TRUE]);
      $this->updateRecurCompleted(['id' => $this->contribution_recur_id]);
    }
    // There is no easy way of retrieving a count of all invoices for a subscription so we ignore the "installments"
    //   parameter for now and rely on checking end_date (which was calculated based on number of installments...)
    // $stripeInvoices = \Stripe\Invoice::all(['subscription' => $this->subscription_id, 'limit' => 100]);
  }

}

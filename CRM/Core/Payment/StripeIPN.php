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
class CRM_Core_Payment_StripeIPN extends CRM_Core_Payment_BaseIPN {

  use CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \CRM_Core_Payment_Stripe Payment processor
   */
  protected $_paymentProcessor;

  /**
   * Transaction ID is the contribution in the redirect flow and a random number in the on-site->POST flow
   * Ideally the contribution id would always be created at this point in either flow for greater consistency
   * @var
   */
  protected $transaction_id;

  // By default, always retrieve the event from stripe to ensure we are
  // not being fed garbage. However, allow an override so when we are
  // testing, we can properly test a failed recurring contribution.
  protected $verify_event = TRUE;

  /**
   * @var \Stripe\StripeObject
   */
  protected $_inputParameters;

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
  protected $plan_id = NULL;
  protected $plan_amount = NULL;
  protected $frequency_interval = NULL;
  protected $frequency_unit = NULL;
  protected $plan_name = NULL;
  protected $plan_start = NULL;

  // Derived properties.

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
   * @param \stdClass $ipnData
   * @param bool $verify
   */
  public function __construct($ipnData, $verify = TRUE) {
    $this->verify_event = $verify;
    $this->setInputParameters($ipnData);
    parent::__construct();
  }

  /**
   * Store input array on the class.
   * We override base because our input parameter is an object
   *
   * @param \Stripe\StripeObject $parameters
   */
  public function setInputParameters($parameters) {
    // Determine the proper Stripe Processor ID so we can get the secret key
    // and initialize Stripe.
    $this->getPaymentProcessor();
    $this->_paymentProcessor->setAPIParams();

    if (!is_object($parameters)) {
      $this->exception('Invalid input parameters');
    }

    // Now re-retrieve the data from Stripe to ensure it's legit.
    // Special case if this is the test webhook
    if (substr($parameters->id, -15, 15) === '_00000000000000') {
      http_response_code(200);
      $test = (boolean) $this->_paymentProcessor->getPaymentProcessor()['is_test'] ? '(Test processor)' : '(Live processor)';
      echo "Test webhook from Stripe ({$parameters->id}) received successfully by CiviCRM {$test}.";
      exit();
    }

    if ($this->verify_event) {
      $this->_inputParameters = \Stripe\Event::retrieve($parameters->id);
    }
    else {
      $this->_inputParameters = $parameters;
    }
    http_response_code(200);
  }

  /**
   * Get a parameter given to us by Stripe.
   *
   * @param string $name
   * @param $type
   * @param bool $abort
   *
   * @return false|int|null|string
   * @throws \CRM_Core_Exception
   */
  public function retrieve($name, $type, $abort = TRUE) {
    $value = CRM_Stripe_Api::getObjectParam($name, $this->_inputParameters->data->object);

    $value = CRM_Utils_Type::validate($value, $type, FALSE);
    if ($abort && $value === NULL) {
      echo "Failure: Missing or invalid parameter<p>" . CRM_Utils_Type::escape($name, 'String');
      $this->exception("Missing or invalid parameter {$name}");
    }
    return $value;
  }

  /**
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Stripe\Error\Api
   */
  public function main() {
    // Collect and determine all data about this event.
    $this->event_type = CRM_Stripe_Api::getParam('event_type', $this->_inputParameters);
    $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    // Return 200 OK for any events that we don't handle
    if (!in_array($this->event_type, CRM_Stripe_Webhook::getDefaultEnabledEvents())) {
      return TRUE;
    }

    // NOTE: If you add an event here make sure you add it to the webhook or it will never be received!
    switch($this->event_type) {
      case 'invoice.payment_succeeded':
        // Successful recurring payment. Either we are completing an existing contribution or it's the next one in a subscription
        if (!$this->setInfo()) {
          return TRUE;
        }
        // This gives us the actual amount
        $this->amount = CRM_Stripe_Api::getObjectParam('amount', $this->_inputParameters->data->object);
        if ($this->contribution['contribution_status_id'] == $pendingStatusId) {
          $params = [
            'contribution_id' => $this->contribution['id'],
            'trxn_date' => $this->receive_date,
            'order_reference' => $this->invoice_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
          ];
          $this->updateContributionCompleted($params);
          // Don't touch the contributionRecur as it's updated automatically by Contribution.completetransaction
        }
        elseif ($this->contribution['trxn_id'] != $this->invoice_id) {
          // Stripe has generated a new invoice (next payment in a subscription) so we
          //   create a new contribution in CiviCRM
          $params = [
            'contribution_recur_id' => $this->contribution_recur_id,
            'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
            'receive_date' => $this->receive_date,
            'order_reference' => $this->invoice_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'original_contribution_id' => $this->contribution['id'],
          ];
          $this->repeatContribution($params);
          // Don't touch the contributionRecur as it's updated automatically by Contribution.repeattransaction
        }
        $this->handleInstallmentsForSubscription();
        return TRUE;

      case 'invoice.payment_failed':
        // Failed recurring payment. Either we are failing an existing contribution or it's the next one in a subscription
        if (!$this->setInfo()) {
          return TRUE;
        }

        if ($this->contribution['contribution_status_id'] == $pendingStatusId) {
          // If this contribution is Pending, set it to Failed.
          $params = [
            'contribution_id' => $this->contribution['id'],
            'trxn_date' => $this->receive_date,
            'cancel_reason' => $this->retrieve('failure_message', 'String'),
            'trxn_id' => $this->charge_id,
            'order_reference' => $this->invoice_id,
          ];
          $this->updateContributionFailed($params);
        }
        elseif ($this->contribution['trxn_id'] != $this->invoice_id) {
          $params = [
            'contribution_recur_id' => $this->contribution_recur_id,
            'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed'),
            'receive_date' => $this->receive_date,
            'order_reference' => $this->invoice_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'original_contribution_id' => $this->contribution['id'],
          ];
          $this->repeatContribution($params);
          // Don't touch the contributionRecur as it's updated automatically by Contribution.completetransaction
        }
        return TRUE;

      case 'customer.subscription.deleted':
        // Subscription is cancelled
        if (!$this->setInfo()) {
          return TRUE;
        }
        // Cancel the recurring contribution
        $this->updateRecurCancelled(['id' => $this->contribution_recur_id, 'cancel_date' => $this->retrieve('cancel_date', 'String', FALSE)]);
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
          'trxn_date' => $this->receive_date,
          'cancel_reason' => $this->retrieve('failure_message', 'String'),
          'trxn_id' => $this->charge_id,
          'order_reference' => $this->invoice_id ?? $this->charge_id,
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
        $refunds = \Stripe\Refund::all(['charge' => $this->charge_id, 'limit' => 1]);
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
        $this->updateContributionRefund($params);
        return TRUE;

      case 'charge.succeeded':
        // For a recurring contribution we can process charge.succeeded once we receive the event with an invoice ID.
        // For a single contribution we can't process charge.succeeded because it only triggers BEFORE the charge is captured
        if (empty(CRM_Stripe_Api::getObjectParam('customer_id', $this->_inputParameters->data->object))) {
          return TRUE;
        }
      case 'charge.captured':
        // For a single contribution we have to use charge.captured because it has the customer_id.
        if (!$this->setInfo()) {
          return TRUE;
        }
        // This gives us the actual amount
        $this->amount = CRM_Stripe_Api::getObjectParam('amount', $this->_inputParameters->data->object);
        if ($this->contribution['contribution_status_id'] == $pendingStatusId && empty($this->contribution['contribution_recur_id'])) {
          $params = [
            'contribution_id' => $this->contribution['id'],
            'trxn_date' => $this->receive_date,
            'order_reference' => $this->invoice_id ?? $this->charge_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
          ];
          $this->updateContributionCompleted($params);
        }
        return TRUE;

      case 'customer.subscription.updated':
        if (!$this->setInfo()) {
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
    }
    // Unhandled event type.
    return TRUE;
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
    $abort = FALSE;
    $stripeObjectName = get_class($this->_inputParameters->data->object);
    $this->customer_id = CRM_Stripe_Api::getObjectParam('customer_id', $this->_inputParameters->data->object);
    if (empty($this->customer_id)) {
      $this->exception('Missing customer_id!');
    }

    $this->previous_plan_id = CRM_Stripe_Api::getParam('previous_plan_id', $this->_inputParameters);
    $this->subscription_id = $this->retrieve('subscription_id', 'String', $abort);
    $this->invoice_id = $this->retrieve('invoice_id', 'String', $abort);
    $this->receive_date = $this->retrieve('receive_date', 'String', $abort);
    $this->charge_id = $this->retrieve('charge_id', 'String', $abort);
    $this->plan_id = $this->retrieve('plan_id', 'String', $abort);
    $this->plan_amount = $this->retrieve('plan_amount', 'String', $abort);
    $this->frequency_interval = $this->retrieve('frequency_interval', 'String', $abort);
    $this->frequency_unit = $this->retrieve('frequency_unit', 'String', $abort);
    $this->plan_name = $this->retrieve('plan_name', 'String', $abort);
    $this->plan_start = $this->retrieve('plan_start', 'String', $abort);

    if (($stripeObjectName !== 'Stripe\Charge') && ($this->charge_id !== NULL)) {
      $charge = \Stripe\Charge::retrieve($this->charge_id);
      $balanceTransactionID = CRM_Stripe_Api::getObjectParam('balance_transaction', $charge);
    }
    else {
      $balanceTransactionID = CRM_Stripe_Api::getObjectParam('balance_transaction', $this->_inputParameters->data->object);
    }
    $this->setBalanceTransactionDetails($balanceTransactionID);

    // Additional processing of values is only relevant if there is a subscription id.
    if ($this->subscription_id) {
      // Get the recurring contribution record associated with the Stripe subscription.
      try {
        $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $this->subscription_id]);
        $this->contribution_recur_id = $contributionRecur['id'];
      }
      catch (Exception $e) {
        $this->exception('Cannot find recurring contribution for subscription ID: ' . $this->subscription_id . '. ' . $e->getMessage());
      }
    }

    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    return $this->getContribution();
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
    $contributionParamsToReturn = [
      'id',
      'trxn_id',
      'contribution_status_id',
      'contribution_recur_id',
      'total_amount',
      'fee_amount',
      'net_amount',
      'tax_amount',
    ];

    // A) One-off contribution
    if ($this->charge_id) {
      try {
        $this->contribution = civicrm_api3('Contribution', 'getsingle', [
          'trxn_id' => $this->charge_id,
          'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
          'return' => $contributionParamsToReturn,
        ]);
        return TRUE;
      }
      catch (Exception $e) {
        // Contribution not found - that's ok
      }
    }

    // B2) Contribution linked to subscription and we have invoice_id
    if (!$this->contribution && $this->invoice_id) {
      try {
        $this->contribution = civicrm_api3('Contribution', 'getsingle', [
          'trxn_id' => $this->invoice_id,
          'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
          'return' => $contributionParamsToReturn,
        ]);
        return TRUE;
      }
      catch (Exception $e) {
        // Contribution not found - that's ok
      }
    }

    // B1) Contribution linked to subscription and we have subscription_id
    if (!$this->contribution && $this->subscription_id) {
      try {
        $this->contribution = civicrm_api3('Contribution', 'getsingle', [
          'trxn_id' => $this->subscription_id,
          'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
          'return' => $contributionParamsToReturn,
        ]);
        return TRUE;
      }
      catch (Exception $e) {
        // Contribution not found - that's ok
      }
    }

    // If a recurring contribution has been found, get the most recent contribution belonging to it.
    // @todo: In what scenario would this happen?
    if (!$this->contribution && $this->contribution_recur_id) {
      try {
        $this->contribution = civicrm_api3('contribution', 'getsingle', [
          'contribution_recur_id' => $this->contribution_recur_id,
          'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
          'return' => $contributionParamsToReturn,
          'options' => ['limit' => 1, 'sort' => 'id DESC'],
        ]);
        return TRUE;
      }
      catch (Exception $e) {
        // A recurring contribution should always be setup with a pending contribution.
        $this->exception('Cannot find any contributions with recurring contribution ID: ' . $this->contribution_recur_id . '. ' . $e->getMessage());
      }
    }
    if ((bool)\Civi::settings()->get('stripe_ipndebug') && !$this->contribution) {
      $message = $this->_paymentProcessor->getPaymentProcessorLabel() . 'No matching contributions for event ' . CRM_Stripe_Api::getParam('id', $this->_inputParameters);
      Civi::log()->debug($message);
    }
    return FALSE;
  }

  private function setBalanceTransactionDetails($balanceTransactionID) {
    // Gather info about the amount and fee.
    // Get the Stripe charge object if one exists. Null charge still needs processing.
    // If the transaction is declined, there won't be a balance_transaction_id.
    $this->fee = 0.0;
    if ($balanceTransactionID) {
      try {
        $currency = $this->retrieve('currency', 'String', FALSE);
        $balanceTransaction = \Stripe\BalanceTransaction::retrieve($balanceTransactionID);
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

    $stripeSubscription = \Stripe\Subscription::retrieve($this->subscription_id);
    // If we've passed the end date cancel the subscription
    if (($stripeSubscription->current_period_end >= strtotime($contributionRecur['end_date']))
      || ($contributionRecur['contribution_status_id']
        == CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Completed'))) {
      \Stripe\Subscription::update($this->subscription_id, ['cancel_at_period_end' => TRUE]);
      $this->updateRecurCompleted(['id' => $this->contribution_recur_id]);
    }
    // There is no easy way of retrieving a count of all invoices for a subscription so we ignore the "installments"
    //   parameter for now and rely on checking end_date (which was calculated based on number of installments...)
    // $stripeInvoices = \Stripe\Invoice::all(['subscription' => $this->subscription_id, 'limit' => 100]);
  }

}

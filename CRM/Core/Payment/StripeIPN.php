<?php
/**
 * https://civicrm.org/licensing
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
  protected $contribution_recur_id = NULL;
  protected $event_id = NULL;
  protected $invoice_id = NULL;
  protected $receive_date = NULL;
  protected $amount = NULL;
  protected $fee = NULL;
  protected $contribution = NULL;
  protected $previous_contribution = NULL;

  /**
   * CRM_Core_Payment_StripeIPN constructor.
   *
   * @param $ipnData
   * @param bool $verify
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($ipnData, $verify = TRUE) {
    $this->verify_event = $verify;
    $this->setInputParameters($ipnData);
    parent::__construct();
  }

  /**
   * Set the value of is_email_receipt to use when a new contribution is received for a recurring contribution
   * This is used for the API Stripe.Ipn function.  If not set, we respect the value set on the ContributionRecur entity.
   *
   * @param int $sendReceipt The value of is_email_receipt
   */
  public function setSendEmailReceipt($sendReceipt) {
    switch ($sendReceipt) {
      case 0:
        $this->is_email_receipt = 0;
        break;

      case 1:
        $this->is_email_receipt = 1;
        break;

      default:
        $this->is_email_receipt = 0;
    }
  }

  /**
   * Get the value of is_email_receipt to use when a new contribution is received for a recurring contribution
   * This is used for the API Stripe.Ipn function.  If not set, we respect the value set on the ContributionRecur entity.
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  public function getSendEmailReceipt() {
    if (isset($this->is_email_receipt)) {
      return (int) $this->is_email_receipt;
    }
    if (!empty($this->contribution_recur_id)) {
      $this->is_email_receipt = civicrm_api3('ContributionRecur', 'getvalue', [
        'return' => "is_email_receipt",
        'id' => $this->contribution_recur_id,
      ]);
    }
    return (int) $this->is_email_receipt;
  }

  /**
   * Store input array on the class.
   * We override base because our input parameter is an object
   *
   * @param array $parameters
   */
  public function setInputParameters($parameters) {
    if (!is_object($parameters)) {
      $this->exception('Invalid input parameters');
    }

    // Determine the proper Stripe Processor ID so we can get the secret key
    // and initialize Stripe.
    $this->getPaymentProcessor();
    $this->_paymentProcessor->setAPIParams();

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
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function main() {
    // Collect and determine all data about this event.
    $this->event_type = CRM_Stripe_Api::getParam('event_type', $this->_inputParameters);
    $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    // NOTE: If you add an event here make sure you add it to the webhook or it will never be received!
    switch($this->event_type) {
      // Successful recurring payment.
      case 'invoice.payment_succeeded':
        $this->setInfo();
        if ($this->contribution['contribution_status_id'] == $pendingStatusId) {
          $this->completeContribution();
        }
        elseif ($this->contribution['trxn_id'] != $this->charge_id) {
          // The first contribution was completed, so create a new one.
          // api contribution repeattransaction repeats the appropriate contribution if it is given
          // simply the recurring contribution id. It also updates the membership for us.
          $repeatParams = [
            'contribution_recur_id' => $this->contribution_recur_id,
            'contribution_status_id' => 'Completed',
            'receive_date' => $this->receive_date,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'is_email_receipt' => $this->getSendEmailReceipt(),
          ];
          if ($this->previous_contribution) {
            $repeatParams['original_contribution_id'] = $this->previous_contribution['id'];
          }
          civicrm_api3('Contribution', 'repeattransaction', $repeatParams);
        }

        // Successful charge & more to come.
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $this->contribution_recur_id,
          'failure_count' => 0,
          'contribution_status_id' => 'In Progress'
        ]);
        return TRUE;

      // Failed recurring payment.
      case 'invoice.payment_failed':
        $this->setInfo();
        $failDate = date('YmdHis');

        if ($this->contribution['contribution_status_id'] == $pendingStatusId) {
          // If this contribution is Pending, set it to Failed.
          civicrm_api3('Contribution', 'create', [
            'id' => $this->contribution['id'],
            'contribution_status_id' => "Failed",
            'receive_date' => $failDate,
            'is_email_receipt' => 0,
          ]);
        }
        else {
          $contributionParams = [
            'contribution_recur_id' => $this->contribution_recur_id,
            'contribution_status_id' => 'Failed',
            'receive_date' => $failDate,
            'total_amount' => $this->amount,
            'is_email_receipt' => 0,
          ];
          civicrm_api3('Contribution', 'repeattransaction', $contributionParams);
        }

        $failureCount = civicrm_api3('ContributionRecur', 'getvalue', [
          'id' => $this->contribution_recur_id,
          'return' => 'failure_count',
        ]);
        $failureCount++;

        // Change the status of the Recurring and update failed attempts.
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $this->contribution_recur_id,
          'contribution_status_id' => "Failed",
          'failure_count' => $failureCount,
          'modified_date' => $failDate,
        ]);
        return TRUE;

      // Subscription is cancelled
      case 'customer.subscription.deleted':
        $this->setInfo();
        // Cancel the recurring contribution
        civicrm_api3('ContributionRecur', 'cancel', [
          'id' => $this->contribution_recur_id,
        ]);
        return TRUE;

      // One-time donation and per invoice payment.
      case 'charge.failed':
        $failureCode = $this->retrieve('failure_code', 'String');
        $failureMessage = $this->retrieve('failure_message', 'String');
        $chargeId = $this->retrieve('charge_id', 'String');
        // @fixme: Check if "note" param actually does anything!
        try {
          $contribution = civicrm_api3('Contribution', 'getsingle', [
            'trxn_id' => $chargeId,
            'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
            'return' => 'id'
          ]);
        }
        catch (Exception $e) {
          // No failed contribution found, we won't record in CiviCRM for now
          return TRUE;
        }
        $params = [
          'note' => "{$failureCode} : {$failureMessage}",
          'contribution_id' => $contribution['id'],
        ];
        $this->recordFailed($params);
        return TRUE;

      case 'charge.refunded':
        $chargeId = $this->retrieve('charge_id', 'String');
        $refunds = \Stripe\Refund::all(['charge' => $chargeId, 'limit' => 1]);
        $params = [
          'contribution_id' => civicrm_api3('Contribution', 'getvalue', [
            'trxn_id' => $chargeId,
            'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
            'return' => 'id'
          ]),
          'total_amount' => $this->retrieve('amount_refunded', 'Float'),
          'cancel_reason' => $refunds->data[0]->reason,
          'cancel_date' => date('YmdHis', $refunds->data[0]->created),
        ];
        $this->recordRefund($params);
        return TRUE;

      case 'charge.succeeded':
        $this->setInfo();
        if ($this->contribution['contribution_status_id'] == $pendingStatusId) {
          $this->recordCompleted(['contribution_id' => $this->contribution['id']]);
        }
        return TRUE;

      case 'customer.subscription.updated':
        $this->setInfo();
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

        civicrm_api3('Contribution', 'create', [
          'id' => $this->contribution['id'],
          'total_amount' => $this->plan_amount,
          'contribution_recur_id' => $this->contribution_recur_id,
        ]);
        return TRUE;
    }
    // Unhandled event type.
    return TRUE;
  }

  /**
   * Complete a pending contribution and update associated entities (recur/membership)
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function completeContribution() {
    // Update the contribution to include the fee.
    civicrm_api3('Contribution', 'create', [
      'id' => $this->contribution['id'],
      'total_amount' => $this->amount,
      'fee_amount' => $this->fee,
    ]);
    // The last one was not completed, so complete it.
    civicrm_api3('Contribution', 'completetransaction', [
      'id' => $this->contribution['id'],
      'trxn_date' => $this->receive_date,
      'trxn_id' => $this->charge_id,
      'total_amount' => $this->amount,
      'fee_amount' => $this->fee,
      'payment_processor_id' => $this->_paymentProcessor->getPaymentProcessor()['id'],
      'is_email_receipt' => $this->getSendEmailReceipt(),
    ]);
  }

  /**
   * Gather and set info as class properties.
   *
   * Given the data passed to us via the Stripe Event, try to determine
   * as much as we can about this event and set that information as
   * properties to be used later.
   *
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
      $charge = $this->_inputParameters->data->object;
      $balanceTransactionID = CRM_Stripe_Api::getObjectParam('balance_transaction', $this->_inputParameters->data->object);
    }
    // Gather info about the amount and fee.
    // Get the Stripe charge object if one exists. Null charge still needs processing.
    // If the transaction is declined, there won't be a balance_transaction_id.
    $this->amount = 0;
    $this->fee = 0;
    if ($balanceTransactionID) {
      try {
        $balanceTransaction = \Stripe\BalanceTransaction::retrieve($balanceTransactionID);
        $this->amount = $charge->amount / 100;
        $this->fee = $balanceTransaction->fee / 100;
      }
      catch(Exception $e) {
        $this->exception('Error retrieving balance transaction. ' . $e->getMessage());
      }
    }

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

    if ($this->charge_id) {
      try {
        $this->contribution = civicrm_api3('Contribution', 'getsingle', [
          'trxn_id' => $this->charge_id,
          'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
        ]);
      }
      catch (Exception $e) {
        // Contribution not yet created?
      }
    }
    if (!$this->contribution && $this->contribution_recur_id) {
      // If a recurring contribution has been found, get the most recent contribution belonging to it.
      try {
        // Same approach as api repeattransaction.
        $contribution = civicrm_api3('contribution', 'getsingle', [
          'return' => ['id', 'contribution_status_id', 'total_amount', 'trxn_id'],
          'contribution_recur_id' => $this->contribution_recur_id,
          'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
          'options' => ['limit' => 1, 'sort' => 'id DESC'],
        ]);
        $this->previous_contribution = $contribution;
      }
      catch (Exception $e) {
        $this->exception('Cannot find any contributions with recurring contribution ID: ' . $this->contribution_recur_id . '. ' . $e->getMessage());
      }
    }
  }

}

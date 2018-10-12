<?php

/*
 * Payment Processor class for Stripe
 */

class CRM_Core_Payment_Stripe extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   */
  private static $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var object
   */
  protected $_mode = NULL;

  /**
   * TRUE if we are dealing with a live transaction
   *
   * @var boolean
   */
  private $_islive = FALSE;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_islive = ($mode == 'live' ? 1 : 0);
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Stripe');
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return null|string
   *   The error message if any.
   */
  public function checkConfig() {
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Secret Key" is not set in the Stripe Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Publishable Key" is not set in the Stripe Payment Processor settings.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Get the currency for the transaction.
   *
   * Handle any inconsistency about how it is passed in here.
   *
   * @param $params
   *
   * @return string
   */
  public function getAmount($params) {
    // Stripe amount required in cents.
    $amount = number_format($params['amount'], 2, '.', '');
    $amount = (int) preg_replace('/[^\d]/', '', strval($amount));
    return $amount;
  }

  /**
   * Helper log function.
   *
   * @param string $op
   *   The Stripe operation being performed.
   * @param Exception $exception
   *   The error!
   */
  public function logStripeException($op, $exception) {
    Civi::log()->debug("Stripe_Error {$op}: " . print_r($exception->getJsonBody(), TRUE));
  }

  /**
   * Check if return from stripeCatchErrors was an error object
   * that should be passed back to original api caller.
   *
   * @param array $err
   *   The return from a call to stripeCatchErrors
   *
   * @return bool
   */
  public function isErrorReturn($err) {
    if (!empty($err['is_error'])) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Handle an error from Stripe API and notify the user
   *
   * @param array $err
   * @param string $bounceURL
   *
   * @return string errorMessage (or statusbounce if URL is specified)
   */
  public function handleErrorNotification($err, $bounceURL = NULL) {
    $errorMessage = 'Payment Response: <br />' .
      'Type: ' . $err['type'] . '<br />' .
      'Code: ' . $err['code'] . '<br />' .
      'Message: ' . $err['message'] . '<br />';

    Civi::log()->debug('Stripe Payment Error: ' . $errorMessage);

    if ($bounceURL) {
      CRM_Core_Error::statusBounce($errorMessage, $bounceURL, 'Payment Error');
    }
    return $errorMessage;
  }

  /**
   * Run Stripe calls through this to catch exceptions gracefully.
   *
   * @param string $op
   *   Determine which operation to perform.
   * @param $stripe_params
   * @param array $params
   *   Parameters to run Stripe calls on.
   * @param array $ignores
   *
   * @return bool|\CRM_Core_Error|\Stripe\Charge|\Stripe\Customer|\Stripe\Plan
   *   Response from gateway.
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function stripeCatchErrors($op = 'create_customer', $stripe_params, $params, $ignores = array()) {
    $return = FALSE;
    // Check for errors before trying to submit.
    try {
      switch ($op) {
         case 'create_customer':
          $return = \Stripe\Customer::create($stripe_params);
          break;

        case 'update_customer':
          $return = \Stripe\Customer::update($stripe_params);
          break;

        case 'charge':
          $return = \Stripe\Charge::create($stripe_params);
          break;

        case 'save':
          $return = $stripe_params->save();
          break;

        case 'create_plan':
          $return = \Stripe\Plan::create($stripe_params);
          break;

        case 'retrieve_customer':
          $return = \Stripe\Customer::retrieve($stripe_params);
          break;

        case 'retrieve_balance_transaction':
          $return = \Stripe\BalanceTransaction::retrieve($stripe_params);
          break;

        default:
          $return = \Stripe\Customer::create($stripe_params);
          break;
      }
    }
    catch (Exception $e) {
      if (is_a($e, 'Stripe_Error')) {
        foreach ($ignores as $ignore) {
          if (is_a($e, $ignore['class'])) {
            $body = $e->getJsonBody();
            $error = $body['error'];
            if ($error['type'] == $ignore['type'] && $error['message'] == $ignore['message']) {
              return $return;
            }
          }
        }
      }

      $this->logStripeException($op, $e);
      // Since it's a decline, Stripe_CardError will be caught
      $body = $e->getJsonBody();
      $err = $body['error'];
      if (!isset($err['code'])) {
        // A "fake" error code
        $err['code'] = 9000;
      }

      if (is_a($e, 'Stripe_CardError')) {
        civicrm_api3('Note', 'create', array(
          'entity_id' => self::getContactId($params),
          'contact_id' => $params['contributionID'],
          'subject' => $err['type'],
          'note' => $err['code'],
          'entity_table' => "civicrm_contributions",
        ));
      }

      // Flag to detect error return
      $err['is_error'] = TRUE;
      return $err;
    }

    return $return;
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    return array(
      'credit_card_type',
      'credit_card_number',
      'cvv2',
      'credit_card_exp_date',
      'stripe_token',
      'stripe_pub_key',
      'stripe_id',
    );
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    $creditCardType = array('' => ts('- select -')) + CRM_Contribute_PseudoConstant::creditCard();
    return array(
      'credit_card_number' => array(
        'htmlType' => 'text',
        'name' => 'credit_card_number',
        'title' => ts('Card Number'),
        'cc_field' => TRUE,
        'attributes' => array(
          'size' => 20,
          'maxlength' => 20,
          'autocomplete' => 'off',
        ),
        'is_required' => TRUE,
      ),
      'cvv2' => array(
        'htmlType' => 'text',
        'name' => 'cvv2',
        'title' => ts('Security Code'),
        'cc_field' => TRUE,
        'attributes' => array(
          'size' => 5,
          'maxlength' => 10,
          'autocomplete' => 'off',
        ),
        'is_required' => TRUE,
      ),
      'credit_card_exp_date' => array(
        'htmlType' => 'date',
        'name' => 'credit_card_exp_date',
        'title' => ts('Expiration Date'),
        'cc_field' => TRUE,
        'attributes' => CRM_Core_SelectValues::date('creditCard'),
        'is_required' => TRUE,
        'month_field' => 'credit_card_exp_date_M',
        'year_field' => 'credit_card_exp_date_Y',
      ),

      'credit_card_type' => array(
        'htmlType' => 'select',
        'name' => 'credit_card_type',
        'title' => ts('Card Type'),
        'cc_field' => TRUE,
        'attributes' => $creditCardType,
        'is_required' => FALSE,
      ),
      'stripe_token' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_token',
        'title' => 'Stripe Token',
        'attributes' => array(
          'id' => 'stripe-token',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
      'stripe_id' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_id',
        'title' => 'Stripe ID',
        'attributes' => array(
          'id' => 'stripe-id',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
      'stripe_pub_key' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_pub_key',
        'title' => 'Stripe Public Key',
        'attributes' => array(
          'id' => 'stripe-pub-key',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
    );
  }

  /**
   * Get form metadata for billing address fields.
   *
   * @param int $billingLocationID
   *
   * @return array
   *    Array of metadata for address fields.
   */
  public function getBillingAddressFieldsMetadata($billingLocationID = NULL) {
    $metadata = parent::getBillingAddressFieldsMetadata($billingLocationID);
    if (!$billingLocationID) {
      // Note that although the billing id is passed around the forms the idea that it would be anything other than
      // the result of the function below doesn't seem to have eventuated.
      // So taking this as a param is possibly something to be removed in favour of the standard default.
      $billingLocationID = CRM_Core_BAO_LocationType::getBilling();
    }

    // Stripe does not require the state/county field
    if (!empty($metadata["billing_state_province_id-{$billingLocationID}"]['is_required'])) {
      $metadata["billing_state_province_id-{$billingLocationID}"]['is_required'] = FALSE;
    }

    return $metadata;
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Set default values
    $paymentProcessorId = CRM_Utils_Array::value('id', $form->_paymentProcessor);
    $publishableKey = CRM_Core_Payment_Stripe::getPublishableKey($paymentProcessorId);
    $defaults = [
      'stripe_id' => $paymentProcessorId,
      'stripe_pub_key' => $publishableKey,
    ];
    $form->setDefaults($defaults);
  }

   /**
   * Given a payment processor id, return the publishable key (password field)
   *
   * @param $paymentProcessorId
   *
   * @return string
   */
  public static function getPublishableKey($paymentProcessorId) {
    try {
      $publishableKey = (string) civicrm_api3('PaymentProcessor', 'getvalue', array(
        'return' => "password",
        'id' => $paymentProcessorId,
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      return '';
    }
    return $publishableKey;
  }

  /**
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array|\CRM_Core_Error
   *   The result in a nice formatted array (or an error object).
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function doDirectPayment(&$params) {
    if (array_key_exists('credit_card_number', $params)) {
      $cc = $params['credit_card_number'];
      if (!empty($cc) && substr($cc, 0, 8) != '00000000') {
        Civi::log()->debug(ts('ALERT! Unmasked credit card received in back end. Please report this error to the site administrator.'));
      }
    }

    // Let a $0 transaction pass.
    if (empty($params['amount']) || $params['amount'] == 0) {
      return $params;
    }

    // Get proper entry URL for returning on error.
    if (!(array_key_exists('qfKey', $params))) {
      // Probably not called from a civicrm form (e.g. webform) -
      // will return error object to original api caller.
      $params['stripe_error_url'] = NULL;
    }
    else {
      $qfKey = $params['qfKey'];
      $parsed_url = parse_url($params['entryURL']);
      $url_path = substr($parsed_url['path'], 1);
      $params['stripe_error_url'] = CRM_Utils_System::url($url_path,
      $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);
    }

    // Set plugin info and API credentials.
    \Stripe\Stripe::setAppInfo('CiviCRM', CRM_Utils_System::version(), CRM_Utils_System::baseURL());
    \Stripe\Stripe::setApiKey($this->_paymentProcessor['user_name']);

    $amount = self::getAmount($params);

    // Use Stripe.js instead of raw card details.
    if (!empty($params['stripe_token'])) {
      $card_token = $params['stripe_token'];
    }
    else if(!empty(CRM_Utils_Array::value('stripe_token', $_POST, NULL))) {
      $card_token = CRM_Utils_Array::value('stripe_token', $_POST, NULL);
    }
    else {
      CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Stripe.js token was not passed!  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
    }

    $contactId = self::getContactId($params);
    $email = self::getBillingEmail($params, $contactId);

    // See if we already have a stripe customer
    $customerParams = [
      'contact_id' => $contactId,
      'card_token' => $card_token,
      'is_live' => $this->_islive,
      'processor_id' => $this->_paymentProcessor['id'],
      'email' => $email,
    ];

    $stripeCustomerId = CRM_Stripe_Customer::find($customerParams);

    // Customer not in civicrm database.  Create a new Customer in Stripe.
    if (!isset($stripeCustomerId)) {
      $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
    }
    else {
      // Customer was found in civicrm database, fetch from Stripe.
      $stripeCustomer = $this->stripeCatchErrors('retrieve_customer', $stripeCustomerId, $params);
      if (!empty($stripeCustomer)) {
        if ($this->isErrorReturn($stripeCustomer)) {
          if (($stripeCustomer['type'] == 'invalid_request_error') && ($stripeCustomer['code'] == 'resource_missing')) {
            // Customer doesn't exist, create a new one
            $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
          }
          if ($this->isErrorReturn($stripeCustomer)) {
            // We still failed to create a customer
            self::handleErrorNotification($stripeCustomer, $params['stripe_error_url']);
            return $stripeCustomer;
          }
        }

        // Avoid the 'use same token twice' issue while still using latest card.
        if (!empty($params['is_secondary_financial_transaction'])) {
          // This is a Contribution page with "Separate Membership Payment".
          // Charge is coming through for the 2nd time.
          // Don't update customer again or we will get "token_already_used" error from Stripe.
        }
        else {
          $stripeCustomer->card = $card_token;
          $updatedStripeCustomer = $this->stripeCatchErrors('save', $stripeCustomer, $params);
          if ($this->isErrorReturn($updatedStripeCustomer)) {
            if (($updatedStripeCustomer['type'] == 'invalid_request_error') && ($updatedStripeCustomer['code'] == 'token_already_used')) {
              // This error is ok, we've already used the token during create_customer
            }
            else {
              self::handleErrorNotification($updatedStripeCustomer, $params['stripe_error_url']);
              return $updatedStripeCustomer;
            }
          }
        }
      }
      else {
        // Customer was found in civicrm_stripe database, but not in Stripe.
        // Delete existing customer record from CiviCRM and create a new customer
        CRM_Stripe_Customer::delete($customerParams);
        $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
      }
    }

    // Prepare the charge array, minus Customer/Card details.
    if (empty($params['description'])) {
      $params['description'] = ts('Backend Stripe contribution');
    }

    // Stripe charge.
    $stripe_charge = array(
      'amount' => $amount,
      'currency' => strtolower($params['currencyID']),
      'description' => $params['description'] . ' # Invoice ID: ' . CRM_Utils_Array::value('invoiceID', $params),
    );

    // Use Stripe Customer if we have a valid one.  Otherwise just use the card.
    if (!empty($stripeCustomer->id)) {
      $stripe_charge['customer'] = $stripeCustomer->id;
    }
    else {
      $stripe_charge['card'] = $card_token;
    }

    // Handle recurring payments in doRecurPayment().
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      return $this->doRecurPayment($params, $amount, $stripeCustomer);
    }

    // Fire away!  Check for errors before trying to submit.
    $stripeCharge = $this->stripeCatchErrors('charge', $stripe_charge, $params);
    if (!empty($stripeCharge)) {
      if ($this->isErrorReturn($stripeCharge)) {
        self::handleErrorNotification($stripeCharge, $params['stripe_error_url']);
        return $stripeCharge;
      }
      // Success!  Return some values for CiviCRM.
      $params['trxn_id'] = $stripeCharge->id;
      // Return fees & net amount for Civi reporting.
      // Uses new Balance Trasaction object.
      $balanceTransaction = $this->stripeCatchErrors('retrieve_balance_transaction', $stripeCharge->balance_transaction, $params);
      if (!empty($balanceTransaction)) {
        if ($this->isErrorReturn($balanceTransaction)) {
          self::handleErrorNotification($balanceTransaction, $params['stripe_error_url']);
          return $balanceTransaction;
        }
        $params['fee_amount'] = $balanceTransaction->fee / 100;
        $params['net_amount'] = $balanceTransaction->net / 100;
      }
    }
    else {
      // There was no response from Stripe on the create charge command.
      if (isset($params['stripe_error_url'])) {
        CRM_Core_Error::statusBounce('Stripe transaction response not received!  Check the Logs section of your stripe.com account.', $params['stripe_error_url']);
      }
      else {
        // Don't have return url - return error object to api
        $core_err = CRM_Core_Error::singleton();
        $core_err->push(9000, 0, NULL, 'Stripe transaction response not recieved!  Check the Logs section of your stripe.com account.');
        return $core_err;
      }
    }

    return $params;
  }

  /**
   * Submit a recurring payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   * @param int $amount
   *   Transaction amount in USD cents.
   * @param object $stripeCustomer
   *   Stripe customer object generated by Stripe API.
   *
   * @return array
   *   The result in a nice formatted array (or an error object).
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function doRecurPayment(&$params, $amount, $stripeCustomer) {
    // Get recurring contrib properties.
    $frequency = $params['frequency_unit'];
    $frequency_interval = (empty($params['frequency_interval']) ? 1 : $params['frequency_interval']);
    $currency = strtolower($params['currencyID']);
    if (isset($params['installments'])) {
      $installments = $params['installments'];
    }

    // This adds some support for CiviDiscount on recurring contributions and changes the default behavior to discounting
    // only the first of a recurring contribution set instead of all. (Intro offer) The Stripe procedure for discounting the
    // first payment of subscription entails creating a negative invoice item or negative balance first,
    // then creating the subscription at 100% full price. The customers first Stripe invoice will reflect the
    // discount. Subsequent invoices will be at the full undiscounted amount.
    // NB: Civi currently won't send a $0 charge to a payproc extension, but it should in this case. If the discount is >
    // the cost of initial payment, we still send the whole discount (or giftcard) as a negative balance.
    // Consider not selling giftards greater than your least expensive auto-renew membership until we can override this.
    // TODO: add conditonals that look for $param['intro_offer'] (to give admins the choice of default behavior) and
    // $params['trial_period'].

    if (!empty($params['discountcode'])) {
      $discount_code = $params['discountcode'];
      $discount_object = civicrm_api3('DiscountCode', 'get', array(
         'sequential' => 1,
         'return' => "amount,amount_type",
         'code' => $discount_code,
          ));
       // amount_types: 1 = percentage, 2 = fixed, 3 = giftcard
       if ((!empty($discount_object['values'][0]['amount'])) && (!empty($discount_object['values'][0]['amount_type']))) {
         $discount_type = $discount_object['values'][0]['amount_type'];
         if ( $discount_type == 1 ) {
         // Discount is a percentage. Avoid ugly math and just get the full price using price_ param.
           foreach($params as $key=>$value){
             if("price_" == substr($key,0,6)){
               $price_param = $key;
               $price_field_id = substr($key,strrpos($key,'_') + 1);
             }
           }
           if (!empty($params[$price_param])) {
             $priceFieldValue = civicrm_api3('PriceFieldValue', 'get', array(
               'sequential' => 1,
               'return' => "amount",
               'id' => $params[$price_param],
               'price_field_id' => $price_field_id,
              ));
           }
           if (!empty($priceFieldValue['values'][0]['amount'])) {
              $priceset_amount = $priceFieldValue['values'][0]['amount'];
              $full_price = $priceset_amount * 100;
              $discount_in_cents = $full_price - $amount;
              // Set amount to full price.
              $amount = $full_price;
           }
        } else if ( $discount_type >= 2 ) {
        // discount is fixed or a giftcard. (may be > amount).
          $discount_amount = $discount_object['values'][0]['amount'];
          $discount_in_cents = $discount_amount * 100;
          // Set amount to full price.
          $amount =  $amount + $discount_in_cents;
        }
     }
        // Apply the disount through a negative balance.
       $stripeCustomer->account_balance = -$discount_in_cents;
       $stripeCustomer->save();
     }

    // Tying a plan to a membership (or priceset->membership) makes it possible
    // to automatically change the users membership level with subscription upgrade/downgrade.
    // An amount is not enough information to distinguish a membership related recurring
    // contribution from a non-membership related one.
    $membership_type_tag = '';
    $membership_name = '';
    if (isset($params['selectMembership'])) {
      $membership_type_id = $params['selectMembership'][0];
      $membership_type_tag = 'membertype_' . $membership_type_id . '-';
      $membershipType = civicrm_api3('MembershipType', 'get', array(
       'sequential' => 1,
       'return' => "name",
       'id' => $membership_type_id,
      ));
      $membership_name = $membershipType['values'][0]['name'];
    }

    // Currently plan_id is a unique db key. Therefore test plans of the
    // same name as a live plan fail to be added with a DB error Already exists,
    // which is a problem for testing.  This appends 'test' to a test
    // plan to avoid that error.
    $is_live = $this->_islive;
    $mode_tag = '';
    if ( $is_live == 0 ) {
      $mode_tag = '-test';
    }
    $plan_id = "{$membership_type_tag}every-{$frequency_interval}-{$frequency}-{$amount}-{$currency}{$mode_tag}";

    // Prepare escaped query params.
    $query_params = array(
      1 => array($plan_id, 'String'),
      2 => array($this->_paymentProcessor['id'], 'Integer'),
    );

    $stripe_plan_query = CRM_Core_DAO::singleValueQuery("SELECT plan_id
      FROM civicrm_stripe_plans
      WHERE plan_id = %1 AND is_live = '{$this->_islive}' AND processor_id = %2", $query_params);

    if (!isset($stripe_plan_query)) {
      $formatted_amount = number_format(($amount / 100), 2);
      $product = \Stripe\Product::create(array(
        "name" => "CiviCRM {$membership_name} every {$frequency_interval} {$frequency}(s) {$formatted_amount}{$currency}{$mode_tag}",
        "type" => "service"
      ));
      // Create a new Plan.
      $stripe_plan = array(
        'amount' => $amount,
        'interval' => $frequency,
        'product' => $product->id,
        'currency' => $currency,
        'id' => $plan_id,
        'interval_count' => $frequency_interval,
      );

      $ignores = array(
        array(
          'class' => 'Stripe_InvalidRequestError',
          'type' => 'invalid_request_error',
          'message' => 'Plan already exists.',
        ),
      );
      $this->stripeCatchErrors('create_plan', $stripe_plan, $params, $ignores);
      // Prepare escaped query params.
      $query_params = array(
        1 => array($plan_id, 'String'),
        2 => array($this->_paymentProcessor['id'], 'Integer'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_plans (plan_id, is_live, processor_id)
        VALUES (%1, '{$this->_islive}', %2)", $query_params);
    }

    // As of Feb. 2014, Stripe handles multiple subscriptions per customer, even
    // ones of the exact same plan. To pave the way for that kind of support here,
    // were using subscription_id as the unique identifier in the
    // civicrm_stripe_subscription table, instead of using customer_id to derive
    // the invoice_id.  The proposed default behavor should be to always create a
    // new subscription. Upgrade/downgrades keep the same subscription id in Stripe
    // and we mirror this behavior by modifing our recurring contribution when this happens.
    // For now, updating happens in Webhook.php as a result of modifiying the subscription
    // in the UI at stripe.com. Eventually we'll initiating subscription changes
    // from within Civi and Stripe.php. The Webhook.php code should still be relevant.

    // Attach the Subscription to the Stripe Customer.
    $cust_sub_params = array(
      'prorate' => FALSE,
      'plan' => $plan_id,
    );
    $stripeSubscription = $stripeCustomer->subscriptions->create($cust_sub_params);
    $subscription_id = $stripeSubscription->id;
    $recuring_contribution_id = $params['contributionRecurID'];

    // Prepare escaped query params.
    $query_params = array(
      1 => array($subscription_id, 'String'),
      2 => array($stripeCustomer->id, 'String'),
      3 => array($recuring_contribution_id, 'String'),
      4 => array($this->_paymentProcessor['id'], 'Integer'),
    );

    // Insert the Stripe Subscription info.

    // Let end_time be NULL if installments are ongoing indefinitely
    if (empty($installments)) {
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions
        (subscription_id, customer_id, contribution_recur_id, processor_id, is_live )
        VALUES (%1, %2, %3, %4,'{$this->_islive}')", $query_params);
    } else {
      // Calculate timestamp for the last installment.
      $end_time = strtotime("+{$installments} {$frequency}");
      // Add the end time to the query params.
      $query_params[5] = array($end_time, 'Integer');
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions
        (subscription_id, customer_id, contribution_recur_id, processor_id, end_time, is_live)
        VALUES (%1, %2, %3, %4, %5, '{$this->_islive}')", $query_params);
    }

    //  Don't return a $params['trxn_id'] here or else recurring membership contribs will be set
    //  "Completed" prematurely.  Webhook.php does that.
    
    // Add subscription_id so tests can properly work with recurring
    // contributions. 
    $params['subscription_id'] = $subscription_id;

    return $params;

  }

  /**
   * Transfer method not in use.
   *
   * @param array $params
   *   Name value pair of contribution data.
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function doTransferCheckout(&$params, $component) {
    self::doDirectPayment($params);
  }

  /**
   * Default payment instrument validation.
   *
   * Implement the usual Luhn algorithm via a static function in the CRM_Core_Payment_Form if it's a credit card
   * Not a static function, because I need to check for payment_type.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    // Use $_POST here and not $values - for webform fields are not set in $values, but are in $_POST
    CRM_Core_Form::validateMandatoryFields($this->getMandatoryFields(), $_POST, $errors);
    if ($this->_paymentProcessor['payment_type'] == 1) {
      // Don't validate credit card details as they are not passed (and stripe does this for us)
      //CRM_Core_Payment_Form::validateCreditCard($values, $errors, $this->_paymentProcessor['id']);
    }
  }

  /**
   * Process incoming notification.
   *
   * @throws \CRM_Core_Exception
   */
  public static function handlePaymentNotification() {
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
    $ipnClass = new CRM_Core_Payment_StripeIPN($data);
    $ipnClass->main();
  }


  /*******************************************************************
   * THE FOLLOWING FUNCTIONS SHOULD BE REMOVED ONCE THEY ARE IN CORE
   * getBillingEmail
   * getContactId
   ******************************************************************/

  /**
   * Get the billing email address
   *
   * @param array $params
   * @param int $contactId
   *
   * @return string|NULL
   */
  protected static function getBillingEmail($params, $contactId) {
    $billingLocationId = CRM_Core_BAO_LocationType::getBilling();

    $emailAddress = CRM_Utils_Array::value("email-{$billingLocationId}", $params,
      CRM_Utils_Array::value('email-Primary', $params,
        CRM_Utils_Array::value('email', $params, NULL)));

    if (empty($emailAddress) && !empty($contactId)) {
      // Try and retrieve an email address from Contact ID
      try {
        $emailAddress = civicrm_api3('Email', 'getvalue', array(
          'contact_id' => $contactId,
          'return' => ['email'],
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        return NULL;
      }
    }
    return $emailAddress;
  }

  /**
   * Get the contact id
   *
   * @param array $params
   *
   * @return int ContactID
   */
  protected static function getContactId($params) {
    return CRM_Utils_Array::value('contactID', $params,
      CRM_Utils_Array::value('contact_id', $params,
        CRM_Utils_Array::value('cms_contactID', $params,
          CRM_Utils_Array::value('cid', $params, NULL
          ))));
  }

}


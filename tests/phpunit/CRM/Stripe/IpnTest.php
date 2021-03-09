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

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests simple recurring contribution with IPN.
 *
 * @group headless
 */
require ('BaseTest.php');
class CRM_Stripe_IpnTest extends CRM_Stripe_BaseTest {
  protected $contributionRecurID;
  protected $installments = 5;
  protected $frequency_unit = 'month';
  protected $frequency_interval = 1;
  protected $created_ts;

  // This test is particularly dirty for some reason so we have to
  // force a reset.
  public function setUpHeadless() {
    $force = false;
    return \Civi\Test::headless()
      ->install('mjwshared')
      ->installMe(__DIR__)
      ->apply($force);
  }

  /**
   * Test creating a recurring contribution and
   * update it after creation. The membership should also be updated.
   */
  public function testIPNContribution() {

    PropertySpy::$buffer = 'none';
    // Set this to 'print' or 'log' maybe more helpful in development.
    PropertySpy::$outputMode = 'exception';
    $this->assertInstanceOf('CRM_Core_Payment_Stripe', $this->paymentObject);

    // Create a mock stripe client.
    $stripeClient = $this->createMock('Stripe\\StripeClient');
    // Update our CRM_Core_Payment_Stripe object and ensure any others
    // instantiated separately will also use it.
    $this->paymentObject->setMockStripeClient($stripeClient);

    // Mock the payment methods service.
    $mockPaymentMethod = $this->createMock('Stripe\\PaymentMethod');
    $mockPaymentMethod->method('__get')
                      ->will($this->returnValueMap([
                        [ 'id', 'pm_mock']
                      ]));

    $stripeClient->paymentMethods = $this->createMock('Stripe\\Service\\PaymentMethodService');
    // When create called, return something with an ID.
    $stripeClient->paymentMethods
                 ->method('create')
                 ->willReturn($mockPaymentMethod);
//                     new PropertySpy('paymentMethod.create', ['id' => 'pm_mock']));

    $stripeClient->paymentMethods
                 ->method('retrieve')
                 ->willReturn($mockPaymentMethod);


    // Mock the Customers service
    $stripeClient->customers = $this->createMock('Stripe\\Service\\CustomerService');
    $stripeClient->customers
                 ->method('create')
                 ->willReturn(
                     new PropertySpy('customers.create', ['id' => 'cus_mock'])
                 );
    $stripeClient->customers
                 ->method('retrieve')
                 ->willReturn(
                     new PropertySpy('customers.retrieve', ['id' => 'cus_mock'])
                 );

    $mockPlan = $this->createMock('Stripe\\Plan');
    $mockPlan
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'every-1-month-40000-usd-test']
      ]));

    $stripeClient->plans = $this->createMock('Stripe\\Service\\PlanService');
    $stripeClient->plans
      ->method('retrieve')
      ->willReturn($mockPlan);

    // Need a mock intent with id and status, and 
    $mockCharge = $this->createMock('Stripe\\Charge');
    $mockCharge
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'ch_mock'],
        ['captured', TRUE],
        ['status', 'succeeded'],
        ['balance_transaction', 'txn_mock'],
      ]));

    $mockPaymentIntent = $this->createMock('Stripe\\PaymentIntent');
    $mockPaymentIntent
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'pi_mock'],
        ['status', 'succeeded'],
        ['charges', (object) ['data' => [ $mockCharge ]]]
      ]));

    $stripeClient->subscriptions = $this->createMock('Stripe\\Service\\SubscriptionService');
    $stripeClient->subscriptions
        ->method('create')
        ->willReturn(new PropertySpy('subscription.create', [
          'id' => 'sub_mock',
          'current_period_end' => time(),
          'latest_invoice' => [
            'id' => 'in_mock',
            'payment_intent' => $mockPaymentIntent,
          ],
          'pending_setup_intent' => '',
        ]));

    $stripeClient->balanceTransactions = $this->createMock('Stripe\\Service\\BalanceTransactionService');
    $stripeClient->balanceTransactions
    ->method('retrieve')
    ->willReturn(new PropertySpy('balanceTransaction', [
      'id' => 'txn_mock',
      'fee' => 1190, /* means $11.90 */
      'currency' => 'usd',
      'exchange_rate' => NULL,
      'object' => 'charge',
    ]));

    $stripeClient->paymentIntents = $this->createMock('Stripe\\Service\\PaymentIntentService');
    // todo change the status from requires_capture to ?
    //$stripeClient->paymentIntents ->method('update') ->willReturn();

    $stripeClient->invoices = $this->createMock('Stripe\\Service\\InvoiceService');
    $stripeClient->invoices
      ->method('all')
      ->willReturn(['data' => new PropertySpy('Invoice', [
        'amount_due' => 40000,
        'charge' => 'ch_mock',
        'created' => time(),
        'currency' => 'usd',
        'customer' => 'cus_mock',
        'id' => 'in_mock',
        'object' => 'invoice',
        'subscription' => 'sub_mock',
      ])]);

    // Mock Event service.
    $stripeClient->events = $this->createMock('Stripe\\Service\\EventService');
    $mockEvent = [
              'id' => 'evt_mock',
              'object' => 'event',
              'livemode' => false,
              'pending_webhooks' => 0,
              'request' => [ 'id' => NULL ],
              'type' => 'invoice.payment_succeeded',
              'data' => [
                'object' => [
                  'id' => 'in_mock',
                  'object' => 'invoice',
                  'subscription' => 'sub_mock',
                  'customer' => 'cus_mock',
                  'charge' => 'ch_mock',
                  'created' => time(),
                  'amount_due' => 40000,
                ]
              ],
            ];
    $stripeClient->events
      ->method('all')
      ->willReturn(new PropertySpy('events.all',
        [
          'data' => [ $mockEvent ]
        ]));
    $stripeClient->events
      ->method('retrieve')
      ->willReturn(new PropertySpy('events.retrieve', $mockEvent));

    $stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
    $stripeClient->charges
      ->method('retrieve')
      ->willReturn($mockCharge);

    // Setup a recurring contribution for $200 per month.
    $this->setupRecurringTransaction();

    // Submit the payment.
    $payment_extra_params = [
      'is_recur' => 1,
      'contributionRecurID' => $this->contributionRecurID,
      'contributionID' => $this->contributionID,
      'frequency_unit' => $this->frequency_unit,
      'frequency_interval' => $this->frequency_interval,
      'installments' => $this->installments,
    ];
    $this->doPayment($payment_extra_params);

    // Ensure contribution status is set to pending.
    $status_id = civicrm_api3('Contribution', 'getvalue', ['id' => $this->contributionID, 'return' => 'contribution_status_id']);
    $this->assertEquals(2, $status_id);

    // Now check to see if an event was triggered and if so, process it.
    $payment_object = $this->getEvent('invoice.payment_succeeded');
    if ($payment_object) {
      $this->ipn($payment_object);
    }
    // Ensure Contribution status is updated to complete.
    $status_id = civicrm_api3('Contribution', 'getvalue', ['id' => $this->contributionID, 'return' => 'contribution_status_id']);
    $this->assertEquals(1, $status_id);

  }

  /**
   * Retrieve the event with a matching subscription id
   */
  public function getEvent($type) {
    // If the type has subscription in it, then the id is the subscription id
    if (preg_match('/\.subscription\./', $type)) {
      $property = 'id';
    }
    else {
      // Otherwise, we'll find the subscription id in the subscription property.
      $property = 'subscription';
    }
    // Gather all events since this class was instantiated.
    $params['created'] = ['gte' => $this->created_ts];
    //$params['type'] = $type;
    $params['ppid'] = $this->paymentProcessorID;
    $params['output'] = 'raw';

    // Now try to retrieve this transaction.
    $transactions = civicrm_api3('Stripe', 'listevents', $params);
    foreach($transactions['values']['data'] as $transaction) {
      $_ = $transaction->data;
      $_ = $_->object;
      if ($transaction->data->object->$property == $this->processorID) {
        return $transaction;
      }
    }
    return NULL;
  }

  /**
   * Run the webhook/ipn
   *
   */
  public function ipn($event, $verifyRequest = TRUE) {
    $ipnClass = new CRM_Core_Payment_StripeIPN();
    $ipnClass->setEventID($event->id);
    if (!$ipnClass->setEventType($event->type)) {
      // We don't handle this event
      return FALSE;
    };
    $ipnClass->setVerifyData($verifyRequest);
    if (!$verifyRequest) {
      $ipnClass->setData($event->data);
    }
    $ipnClass->setPaymentProcessor($this->paymentProcessorID);
    $ipnClass->setExceptionMode(FALSE);
    if (isset($emailReceipt)) {
      $ipnClass->setSendEmailReceipt($emailReceipt);
    }
    $ipnClass->processWebhook();
  }

  /**
   * Create recurring contribition
   */
  public function setupRecurringTransaction($params = []) {
    $contributionRecur = civicrm_api3('contribution_recur', 'create', array_merge([
      'financial_type_id' => $this->financialTypeID,
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'payment_instrument_id', 'Credit Card'),
      'contact_id' => $this->contactID,
      'amount' => $this->total,
      'sequential' => 1,
      'installments' => $this->installments,
      'frequency_unit' => $this->frequency_unit,
      'frequency_interval' => $this->frequency_interval,
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->paymentProcessorID,
      'api.contribution.create' => [
        'total_amount' => $this->total,
        'financial_type_id' => $this->financialTypeID,
        'contribution_status_id' => 'Pending',
        'contact_id' => $this->contactID,
        'payment_processor_id' => $this->paymentProcessorID,
        'is_test' => 1,
      ],
    ], $params));
    $this->assertEquals(0, $contributionRecur['is_error']);
    $this->contributionRecurID = $contributionRecur['id'];
    $this->contributionID = $contributionRecur['values']['0']['api.contribution.create']['id'];
  }

}

/**
 * This class provides a data structure for mocked stripe responses, and will detect
 * if a property is requested that is not already mocked.
 *
 * This enables us to only need to mock the things we actually use, which
 * hopefully makes the code more readable/maintainable.
 *
 * It implements the same interfaces as StripeObject does.
 *
 *
 */
class PropertySpy implements ArrayAccess, Iterator, Countable {

  /**
   * @var string $outputMode print|log|exception
   *
   * log means Civi::log()->debug()
   * exception means throw a RuntimeException. Use this once your tests are passing,
   * so that in future if the code starts relying on something we have not
   * mocked we can figure it out quickly.
   */
  public static $outputMode = 'print';
  /**
   * @var string $buffer
   *
   * - 'none' output immediately.
   * - 'global' tries to output things chronologically at end when all objects have been killed.
   * - 'local' outputs everything that happened to this object on destruction
   */
  public static $buffer = 'none'; /* none|global|local */
  protected $_name;
  protected $_props;
  protected $localLog = [];
  public static $globalLog = [];
  public static $globalObjects = 0;

  protected $iteratorIdx=0;
  // Iterator
  public function current() {
    // $this->warning("Iterating " . array_keys($this->_props)[$this->key()]);
    return current($this->_props);
  }
  /**
   * Implemetns Countable
   */
  public function count() {
    return \count($this->_props);
  }
  public function key ( ) {
    return key($this->_props);
  }
  public function next() {
    return next($this->_props);
  }
  public function rewind() {
    return reset($this->_props);
  }
  public function valid() {
    return array_key_exists(key($this->_props), $this->_props);
  }

  public function __construct($name, $props) {
    $this->_name = $name;
    foreach ($props as $k => $v) {
      if (is_array($v)) {
        // Iterative spies.
        $v = new static("$name{" . "$k}", $v);
      }
      $this->_props[$k] = $v;
    }
    static::$globalObjects++;
  }
  public function __destruct() {
    static::$globalObjects--;
    if (static::$buffer === 'local') {
      $msg = "PropertySpy: $this->_name\n"
          . json_encode($this->localLog, JSON_PRETTY_PRINT) . "\n";
      if (static::$outputMode === 'print') {
        print $msg;
      }
      elseif (static::$outputMode === 'log') {
        \Civi::log()->debug($msg);
      }
      elseif (static::$outputMode === 'exception') {
        throw new \RuntimeException($msg);
      }
    }
    elseif (static::$buffer === 'global' && static::$globalObjects === 0) {
      // End of run.
      $msg = "PropertySpy:\n" . json_encode(static::$globalLog, JSON_PRETTY_PRINT) . "\n";
      if (static::$outputMode === 'print') {
        print $msg;
      }
      elseif (static::$outputMode === 'log') {
        \Civi::log()->debug($msg);
      }
      elseif (static::$outputMode === 'exception') {
        throw new \RuntimeException($msg);
      }
    }
  }
  protected function warning($msg) {
    if (static::$buffer === 'none') {
      // Immediate output
      if (static::$outputMode === 'print') {
        print "$this->_name $msg\n";
      }
      elseif (static::$outputMode === 'log') {
        Civi::log()->debug("$this->_name $msg\n");
      }
    }
    elseif (static::$buffer === 'global') {
      static::$globalLog[] = "$this->_name $msg";
    }
    elseif (static::$buffer === 'local') {
      $this->localLog[] = $msg;
    }
  }
  public function __get($prop) {
    if ($prop === 'log') {
      throw new \Exception("stop");
    }
    if (array_key_exists($prop, $this->_props)) {
      return $this->_props[$prop];
    }
    $this->warning("->$prop requested but not defined");
    return NULL;
  }
  public function offsetGet($prop) {
    if (array_key_exists($prop, $this->_props)) {
      return $this->_props[$prop];
    }
    $this->warning("['$prop'] requested but not defined");
  }
  public function offsetExists($prop) {
    if (!array_key_exists($prop, $this->_props)) {
      $this->warning("['$prop'] offsetExists requested but not defined");
      return FALSE;
    }
    return TRUE;
  }
  public function __isset($prop) {
    if (!array_key_exists($prop, $this->_props)) {
      $this->warning("isset(->$prop) but not defined");
    }
    return isset($this->_props[$prop]);
  }
  public function offsetSet($prop, $value) {
    $this->warning("['$prop'] offsetSet");
    $this->_props[$prop] = $value;
  }
  public function offsetUnset($prop) {
    $this->warning("['$prop'] offsetUnset");
    unset($this->_props[$prop]);
  }
}

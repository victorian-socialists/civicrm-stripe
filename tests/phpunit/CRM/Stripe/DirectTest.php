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
 * Test a simple, direct payment via Stripe.
 *
 * @group headless
 */
require_once('BaseTest.php');
class CRM_Stripe_DirectTest extends CRM_Stripe_BaseTest {

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  /**
   * DRY code. Sets up the Stripe objects needed to import a subscription
   *
   * The following mock Stripe IDs strings are used:
   *
   * - pm_mock   PaymentMethod
   * - pi_mock   PaymentIntent
   * - cus_mock  Customer
   * - ch_mock   Charge
   * - txn_mock  Balance transaction
   * - sub_mock  Subscription
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   */
  protected function mockStripe($subscriptionParams = []) {
    $subscriptionParams['hasPaidInvoice'] = $subscriptionParams['hasPaidInvoice'] ?? TRUE;

    PropertySpy::$buffer = 'none';
    // Set this to 'print' or 'log' maybe more helpful in debugging but for
    // generally running tests 'exception' suits as we don't expect any output.
    PropertySpy::$outputMode = 'exception';

    $this->assertInstanceOf('CRM_Core_Payment_Stripe', $this->paymentObject);

    // Create a mock stripe client.
    $stripeClient = $this->createMock('Stripe\\StripeClient');
    // Update our CRM_Core_Payment_Stripe object and ensure any others
    // instantiated separately will also use it.
    $this->paymentObject->setMockStripeClient($stripeClient);

    // Mock the Customers service
    $stripeClient->customers = $this->createMock('Stripe\\Service\\CustomerService');
    $stripeClient->customers
      ->method('create')
      ->willReturn(
        new PropertySpy('customers.create', ['id' => 'cus_mock'])
      );
    $stripeClient->customers
      ->method('retrieve')
      ->with($this->equalTo('cus_mock'))
      ->willReturn(
        new PropertySpy('customers.retrieve', ['id' => 'cus_mock'])
      );

    $mockPlan = $this->createMock('Stripe\\Plan');
    $mockPlan
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'every-1-month-' . ($this->total * 100) . '-usd-test'],
        ['amount', $this->total*100],
        ['currency', 'usd'],
        ['interval_count', $this->contributionRecur['frequency_interval']],
        ['interval', $this->contributionRecur['frequency_unit']],
      ]));
    $stripeClient->plans = $this->createMock('Stripe\\Service\\PlanService');
    $stripeClient->plans
      ->method('retrieve')
      ->willReturn($mockPlan);

    $mockSubscriptionParams = [
      'id' => 'sub_mock',
      'object' => 'subscription',
      'customer' => 'cus_mock',
      'current_period_end' => time()+60*60*24,
      'pending_setup_intent' => '',
      'plan' => $mockPlan,
      'start_date' => time(),
    ];
    if ($subscriptionParams['hasPaidInvoice']) {
      // Need a mock intent with id and status
      $mockCharge = $this->createMock('Stripe\\Charge');
      $mockCharge
        ->method('__get')
        ->will($this->returnValueMap([
          ['id', 'ch_mock'],
          ['object', 'charge'],
          ['captured', TRUE],
          ['status', 'succeeded'],
          ['balance_transaction', 'txn_mock'],
          ['invoice', 'in_mock']
        ]));
      $mockChargesCollection = new \Stripe\Collection();
      $mockChargesCollection->data = [$mockCharge];

      $mockPaymentIntent = $this->createMock('Stripe\\PaymentIntent');
      $mockPaymentIntent
        ->method('__get')
        ->will($this->returnValueMap([
          ['id', 'pi_mock'],
          ['status', 'succeeded'],
          ['charges', $mockChargesCollection]
        ]));
      $mockSubscriptionParams['latest_invoice'] = [
        'id' => 'in_mock',
        'payment_intent' => $mockPaymentIntent,
      ];
    }

    $stripeClient->paymentIntents = $this->createMock('Stripe\\Service\\PaymentIntentService');
    $stripeClient->paymentIntents
      ->method('create')
      ->willReturn($mockPaymentIntent);
    $stripeClient->paymentIntents
      ->method('retrieve')
      ->willReturn($mockPaymentIntent);

    $mockPaymentMethodParams = [
      'id' => 'pm_mock',
    ];
    $mockPaymentMethod = new PropertySpy('paymentMethod', $mockPaymentMethodParams);
    $stripeClient->paymentMethods = $this->createMock('Stripe\\Service\\PaymentMethodService');
    $stripeClient->paymentMethods
      ->method('create')
      ->willReturn($mockPaymentMethod);

    $mockSubscription = new PropertySpy('subscription.create', $mockSubscriptionParams);
    $stripeClient->subscriptions = $this->createMock('Stripe\\Service\\SubscriptionService');
    $stripeClient->subscriptions
      ->method('create')
      ->willReturn($mockSubscription);
    $stripeClient->subscriptions
      ->method('retrieve')
      ->with($this->equalTo('sub_mock'))
      ->willReturn($mockSubscription);

    if ($subscriptionParams['hasPaidInvoice']) {
      $stripeClient->balanceTransactions = $this->createMock('Stripe\\Service\\BalanceTransactionService');
      $stripeClient->balanceTransactions
        ->method('retrieve')
        ->with($this->equalTo('txn_mock'))
        ->willReturn(new PropertySpy('balanceTransaction', [
          'id' => 'txn_mock',
          'fee' => 1190, /* means $11.90 */
          'currency' => 'usd',
          'exchange_rate' => NULL,
          'object' => 'balance_transaction',
        ]));

      $mockCharge = new PropertySpy('Charge', [
        'id' => 'ch_mock',
        'object' => 'charge',
        'captured' => TRUE,
        'status' => 'succeeded',
        'balance_transaction' => 'txn_mock',
        'invoice' => 'in_mock'
      ]);
      $stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
      $stripeClient->charges
        ->method('retrieve')
        ->with($this->equalTo('ch_mock'))
        ->willReturn($mockCharge);

      $mockInvoice = new PropertySpy('Invoice', [
        'amount_due' => $this->total * 100,
        'charge' => 'ch_mock', //xxx
        'created' => time(),
        'currency' => 'usd',
        'customer' => 'cus_mock',
        'id' => 'in_mock',
        'object' => 'invoice',
        'subscription' => 'sub_mock',
        'paid' => TRUE
      ]);
      $mockInvoicesCollection = new \Stripe\Collection();
      $mockInvoicesCollection->data = [$mockInvoice];
      $stripeClient->invoices = $this->createMock('Stripe\\Service\\InvoiceService');
      $stripeClient->invoices
        ->method('all')
        ->willReturn($mockInvoicesCollection);
      $stripeClient->invoices
        ->method('retrieve')
        ->with($this->equalTo('in_mock'))
        ->willReturn($mockInvoice);
    }
    else {
      // No invoices
      $mockInvoicesCollection = new \Stripe\Collection();
      $mockInvoicesCollection->data = [];
      $stripeClient->invoices = $this->createMock('Stripe\\Service\\InvoiceService');
      $stripeClient->invoices
        ->method('all')
        ->willReturn($mockInvoicesCollection);
    }
  }

  /**
   * Test making a recurring contribution.
   * @fixme This test currently doesn't work (needs work on mockStripe() to return the right responses for paymentIntents)
   *
  public function testDirectSuccess() {
    $this->setupTransaction();
    $this->mockStripe();
    $this->doPayment();
    $this->assertValidTrxn();
  }*/

  public function testDummy() {
    return;
  }

}

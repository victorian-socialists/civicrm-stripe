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

define('STRIPE_PHPUNIT_TEST', 1);

/**
 * This class provides helper functions for other Stripe Tests. There are no
 * tests in this class.
 *
 * @group headless
 */
class CRM_Stripe_BaseTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  protected $contributionID;
  protected $financialTypeID = 1;
  protected $contact;
  protected $contactID;
  protected $paymentProcessorID;
  protected $paymentProcessor;
  protected $trxn_id;
  protected $processorID;
  protected $cc = '4111111111111111';
  protected $total = '400.00';

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('mjwshared')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    require_once('vendor/stripe/stripe-php/init.php');
    $this->createPaymentProcessor();
    $this->createContact();
    $this->created_ts = time();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Create contact.
   */
  function createContact() {
    if (!empty($this->contactID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Jose',
      'last_name' => 'Lopez'
    ]);;
    $this->contactID = $results['id'];
    $this->contact = (Object) array_pop($results['values']);

    // Now we have to add an email address.
    $email = 'susie@example.org';
    civicrm_api3('email', 'create', [
      'contact_id' => $this->contactID,
      'email' => $email,
      'location_type_id' => 1
    ]);
    $this->contact->email = $email;
  }

  /**
   * Create a stripe payment processor.
   *
   */
  function createPaymentProcessor($params = []) {
    $result = civicrm_api3('Stripe', 'setuptest', $params);
    $processor = array_pop($result['values']);
    $this->paymentProcessor = $processor;
    $this->paymentProcessorID = $result['id'];
  }

  /**
   * Submit to stripe
   */
  public function doPayment($params = []) {
    $mode = 'test';

    \Stripe\Stripe::setApiKey(CRM_Core_Payment_Stripe::getSecretKey($this->paymentProcessor));

    // Send in credit card to get payment method.
    $paymentMethod = \Stripe\PaymentMethod::create([
      'type' => 'card',
      'card' => [
        'number' => $this->cc,
        'exp_month' => 12,
        'exp_year' => date('Y') + 1,
        'cvc' => '123',
      ],
    ]);

    $paymentIntentID = NULL;
    $paymentMethodID = NULL;

    if (!array_key_exists('is_recur', $params)) {
      // Send in payment method to get payment intent.
      $paymentIntentParams = [
        'payment_method_id' => $paymentMethod->id,
        'amount' => $this->total,
        'payment_processor_id' => $this->paymentProcessorID,
        'payment_intent_id' => NULL,
        'description' => NULL,
      ];
      $result = civicrm_api3('StripePaymentintent', 'process', $paymentIntentParams);

      $paymentIntentID = $result['values']['paymentIntent']['id'];
    }
    else {
      $paymentMethodID = $paymentMethod->id;
    }

    $stripe = new CRM_Core_Payment_Stripe($mode, $this->paymentProcessor);
    $params = array_merge([
      'payment_processor_id' => $this->paymentProcessorID,
      'amount' => $this->total,
      'paymentIntentID' => $paymentIntentID,
      'paymentMethodID' => $paymentMethodID,
      'email' => $this->contact->email,
      'contactID' => $this->contact->id,
      'description' => 'Test from Stripe Test Code',
      'currencyID' => 'USD',
      // Avoid missing key php errors by adding these un-needed parameters.
      'qfKey' => NULL,
      'entryURL' => 'http://civicrm.localhost/civicrm/test?foo',
      'query' => NULL,
      'additional_participants' => [],
    ], $params);

    $ret = $stripe->doPayment($params);

    if (array_key_exists('trxn_id', $ret)) {
      $this->trxn_id = $ret['trxn_id'];
    }
    if (array_key_exists('contributionRecurID', $ret)) {
      // Get processor id.
      $sql = "SELECT processor_id FROM civicrm_contribution_recur WHERE id = %0";
      $params = [ 0 => [ $ret['contributionRecurID'], 'Integer' ] ];
      $dao = CRM_Core_DAO::executeQuery($sql, $params);
      if ($dao->N > 0) {
        $dao->fetch();
        $this->processorID = $dao->processor_id;
      }
    }
  }

  /**
   * Confirm that transaction id is legit and went through.
   *
   */
  public function assertValidTrxn() {
    $this->assertNotEmpty($this->trxn_id, "A trxn id was assigned");

    $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $this->paymentProcessorID]));
    $processor->setAPIParams();

    try {
      $results = \Stripe\Charge::retrieve(["id" => $this->trxn_id]);
      $found = TRUE;
    }
    catch (Stripe_Error $e) {
      $found = FALSE;
    }

    $this->assertTrue($found, 'Assigned trxn_id is valid.');

  }
  /**
   * Create contribition
   */
  public function setupTransaction($params = []) {
     $contribution = civicrm_api3('contribution', 'create', array_merge([
      'contact_id' => $this->contactID,
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->contactID,
      'total_amount' => $this->total,
      'financial_type_id' => $this->financialTypeID,
      'contribution_status_id' => 'Pending',
      'contact_id' => $this->contactID,
      'payment_processor_id' => $this->paymentProcessorID,
      'is_test' => 1,
     ], $params));
    $this->assertEquals(0, $contribution['is_error']);
    $this->contributionID = $contribution['id'];
  }

}

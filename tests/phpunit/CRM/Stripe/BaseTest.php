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

  /** @var int */
  protected $contributionID;
  /** @var int */
  protected $financialTypeID = 1;
  /** @var array */
  protected $contact;
  /** @var int */
  protected $contactID;
  /** @var int */
  protected $paymentProcessorID;
  /** @var array of payment processor configuration values */
  protected $paymentProcessor;
  /** @var CRM_Core_Payment_Stripe */
  protected $paymentObject;
  /** @var string */
  protected $trxn_id;
  /** @var string */
  protected $processorID;
  /** @var string */
  protected $cc = '4111111111111111';
  /** @var string */
  protected $total = '400.00';

  public function setUpHeadless() {
  }

  public function setUp(): void {
    parent::setUp();
    // we only need to do the shared library once
    if (!is_dir(__DIR__ . '/../../../../../mjwshared')) {
      civicrm_api3('Extension', 'download', ['key' => 'mjwshared']);
    }
    else {
      civicrm_api3('Extension', 'install', ['keys' => 'mjwshared']);
    }
    civicrm_api3('Extension', 'install', ['keys' => 'com.drastikbydesign.stripe']);
    require_once('vendor/stripe/stripe-php/init.php');
    $this->createPaymentProcessor();
    $this->createContact();
    $this->created_ts = time();
  }

  public function tearDown(): void {
    civicrm_api3('PaymentProcessor', 'delete', ['id' => $this->paymentProcessorID]);
    civicrm_api3('Extension', 'disable', ['keys' => 'com.drastikbydesign.stripe']);
    civicrm_api3('Extension', 'uninstall', ['keys' => 'com.drastikbydesign.stripe']);
    parent::tearDown();
  }

  /**
   *
   */
  protected function returnValueMapOrDie($map): ValueMapOrDie {
    return new ValueMapOrDie($map);
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
    $this->paymentObject = \Civi\Payment\System::singleton()->getById($result['id']);
    // Set params, creates stripeClient
    $this->paymentObject->setAPIParams();
  }

  /**
   * Submit to stripe
   */
  public function doPayment($params = []) {
    // Send in credit card to get payment method. xxx mock here
    $paymentMethod = $this->paymentObject->stripeClient->paymentMethods->create([
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

    if (!isset($params['is_recur'])) {
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

    $ret = $this->paymentObject->doPayment($params);

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

    $processor = \Civi\Payment\System::singleton()->getById($this->paymentProcessorID);
    $processor->setAPIParams();

    try {
      $processor->stripeClient->charges->retrieve($this->trxn_id);
      $found = TRUE;
    }
    catch (Exception $e) {
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
      'payment_processor_id' => $this->paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->contactID,
      'total_amount' => $this->total,
      'financial_type_id' => $this->financialTypeID,
      'contribution_status_id' => 'Pending',
      'is_test' => 1,
     ], $params));
    $this->assertEquals(0, $contribution['is_error']);
    $this->contributionID = $contribution['id'];
  }

}

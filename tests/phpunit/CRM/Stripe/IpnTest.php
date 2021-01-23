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
    $force = TRUE;
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
    // Setup a recurring contribution for $200 per month.
    $this->setupRecurringTransaction();

    // Submit the payment.
    $payment_extra_params = [
      'is_recur' => 1,
      'contributionRecurID' => $this->contributionRecurID,
      'frequency_unit' => $this->frequency_unit,
      'frequency_interval' => $this->frequency_interval,
      'installments' => $this->installments,
    ];
    $this->doPayment($payment_extra_params);

    // Ensure contribution status is set to pending.
    $status_id = civicrm_api3('Contribution', 'getvalue', [ 'id' => $this->contributionID, 'return' => 'contribution_status_id' ]);
    $this->assertEquals(2, $status_id);

    // Now check to see if an event was triggered and if so, process it.
    $payment_object = $this->getEvent('invoice.payment_succeeded');
    if ($payment_object) {
      $this->ipn($payment_object);
    }
    // Ensure Contribution status is updated to complete.
    $status_id = civicrm_api3('Contribution', 'getvalue', [ 'id' => $this->contributionID, 'return' => 'contribution_status_id' ]);
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
    // Give it a few seconds to be processed...
    sleep(5);
    $transactions = civicrm_api3('Stripe', 'listevents', $params );
    foreach($transactions['values']['data'] as $transaction) {
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
  public function ipn($data, $verify = TRUE) {
    // The $_GET['processor_id'] value is normally set by
    // CRM_Core_Payment::handlePaymentMethod
    $_GET['processor_id'] = $this->paymentProcessorID;
    $ipnClass = new CRM_Core_Payment_StripeIPN($data, $verify);
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

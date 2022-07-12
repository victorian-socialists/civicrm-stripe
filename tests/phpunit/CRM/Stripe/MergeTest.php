<?php

use CRM_Stripe_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\CiviEnvBuilder;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
require_once('BaseTest.php');
class CRM_Stripe_MergeTest extends CRM_Stripe_ApiTest {

  /**
   * Test contact merging
   * 
   * So far, only looks at the Civi side of things
   */
  public function testMerge():void {
    // Start the same way as in ApiTest
    $this->mockStripeSubscription(['hasPaidInvoice' => FALSE]);

    $result = civicrm_api3('Stripe', 'importsubscription', [
      'subscription' => 'sub_mock',
      'contact_id' => $this->contactID,
      'ppid' => $this->paymentProcessorID
    ]);

    $this->contributionID = $result['values']['contribution_id'];
    $this->contributionRecurID = $result['values']['recur_id'];
    $customer_id = $result['values']['customer'];

    // Lookup existing contact
    $contact = \Civi\Api4\Contact::get()
      ->addSelect('first_name', 'last_name')
      ->addWhere('id', '=', $this->contactID)
      ->execute()
      ->first();

    // Check contact is right
    $this->checkContribRecur([
      'contact_id' => $this->contactID,
    ]);

    // Get the stripecustomer data
    $customer_params = CRM_Stripe_Customer::getParamsForCustomerId($customer_id);

    // Confirm the contact id is as expected
    $this->assertEquals($this->contactID, $customer_params['contact_id']);

    // Create a new contact
    $res = \Civi\Api4\Contact::create()
       ->addValue('first_name', $contact['first_name'])
       ->addValue('last_name', $contact['last_name'])
       ->execute()
       ->first();

    $new_id = $res['id'];

    // Merge orig contact to new
    civicrm_api3('Contact', 'merge', [
      'to_remove_id' => $this->contactID,
      'to_keep_id' => $new_id,
    ]);

    // Check the orig was deleted
    $res = \Civi\Api4\Contact::get()
      ->addWhere('id', '=', $this->contactID)
      ->addSelect('is_deleted')
      ->execute()
      ->first();
    $this->assertEquals($res['is_deleted'], 1);

    // update saved contact_id
    $this->contactID = $new_id;

    // Check recur contact id was updated
    $this->checkContribRecur([
      'contact_id' => $this->contactID,
    ]);

    // Get the stripecustomer data again
    $customer_params = CRM_Stripe_Customer::getParamsForCustomerId($customer_id);

    // Confirm the contact id has been updated
    // This is would have failed previously
    // The update is handled automatically by having the StripeCustomer entity defined in XML
    $this->assertEquals($this->contactID, $customer_params['contact_id']);

  }

}

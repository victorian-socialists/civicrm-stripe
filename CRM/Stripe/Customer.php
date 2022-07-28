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

use Civi\Api4\Contact;
use Civi\Api4\StripeCustomer;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Stripe_ExtensionUtil as E;

/**
 * Class CRM_Stripe_Customer
 */
class CRM_Stripe_Customer {

  /**
   * Find an existing Stripe customer in the CiviCRM database
   *
   * @param $params
   *
   * @return null|string
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function find($params) {
    $requiredParams = ['processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new PaymentProcessorException('Stripe Customer (find): Missing required parameter: ' . $required);
      }
    }
    if (empty($params['contact_id'])) {
      throw new PaymentProcessorException('Stripe Customer (find): contact_id is required');
    }

    $result = StripeCustomer::get()
      ->addWhere('contact_id', '=', $params['contact_id'])
      ->addWhere('processor_id', '=', $params['processor_id'])
      ->addSelect('customer_id')
      ->execute();

    return $result->count() ? $result->first()['customer_id'] : NULL;
  }

  /**
   * Find the details (contact_id, processor_id) for an existing Stripe customer in the CiviCRM database
   *
   * @param string $stripeCustomerId
   *
   * @return array|null
   */
  public static function getParamsForCustomerId($stripeCustomerId) {
    $result = StripeCustomer::get()
      ->addWhere('customer_id', '=', $stripeCustomerId)
      ->addSelect('contact_id', 'processor_id')
      ->execute()
      ->first();

    // Not sure whether this return for no match is needed, but that's what was being returned previously
    return $result ? $result : ['contact_id' => NULL, 'processor_id' => NULL];
  }

  /**
   * Find the details (contact_id, processor_id) for an existing Stripe customer in the CiviCRM database
   *
   * @param string $stripeCustomerId
   *
   * @return array|null
   */
  public static function getAll($processorId, $options = []) {
    return civicrm_api4('StripeCustomer', 'get', [
      'select' => ['customer_id'],
      'where' => [['processor_id', '=', $processorId]],
    ] + $options, ['customer_id']);
  }

  /**
   * Add a new Stripe customer to the CiviCRM database
   *
   * @param $params
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function add($params) {
    return civicrm_api4('StripeCustomer', 'create', ['values' => $params]);
  }

  /**
   * @param array $params
   * @param \CRM_Core_Payment_Stripe $stripe
   *
   * @return \Stripe\ApiResource
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function create($params, $stripe) {
    $requiredParams = ['contact_id', 'processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new PaymentProcessorException('Stripe Customer (create): Missing required parameter: ' . $required);
      }
    }

    $stripeCustomerParams = self::getStripeCustomerMetadata($params);

    try {
      $stripeCustomer = $stripe->stripeClient->customers->create($stripeCustomerParams);
    }
    catch (Exception $e) {
      $err = CRM_Core_Payment_Stripe::parseStripeException('create_customer', $e, FALSE);
      throw new PaymentProcessorException('Failed to create Stripe Customer: ' . $err['code']);
    }

    // Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID.
    $params = [
      'contact_id' => $params['contact_id'],
      'customer_id' => $stripeCustomer->id,
      'processor_id' => $params['processor_id'],
    ];
    self::add($params);

    return $stripeCustomer;
  }

  /**
   * @param array $params
   * @param \CRM_Core_Payment_Stripe $stripe
   * @param string $stripeCustomerID
   *
   * @return \Stripe\Customer
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function updateMetadata($params, $stripe, $stripeCustomerID) {
    $requiredParams = ['contact_id', 'processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new PaymentProcessorException('Stripe Customer (updateMetadata): Missing required parameter: ' . $required);
      }
    }

    $stripeCustomerParams = self::getStripeCustomerMetadata($params);

    try {
      $stripeCustomer = $stripe->stripeClient->customers->update($stripeCustomerID, $stripeCustomerParams);
    }
    catch (Exception $e) {
      $err = CRM_Core_Payment_Stripe::parseStripeException('create_customer', $e);
      throw new PaymentProcessorException('Failed to update Stripe Customer: ' . $err['code']);
    }
    return $stripeCustomer;
  }

  /**
   * @param array $params
   *   Required: contact_id; Optional: email
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  private static function getStripeCustomerMetadata($params) {
    $contactDisplayName = Contact::get(FALSE)
      ->addSelect('display_name')
      ->addWhere('id', '=', $params['contact_id'])
      ->execute()
      ->first()['display_name'];

    $extVersion = civicrm_api3('Extension', 'getvalue', ['return' => 'version', 'full_name' => E::LONG_NAME]);

    $stripeCustomerParams = [
      'name' => $contactDisplayName,
      // Stripe does not include the Customer Name when exporting payments, just the customer
      // description, so we stick the name in the description.
      'description' => $contactDisplayName . ' (CiviCRM)',
      'email' => $params['email'] ?? '',
      'metadata' => [
        'CiviCRM Contact ID' => $params['contact_id'],
        'CiviCRM URL' => CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid={$params['contact_id']}", TRUE, NULL, TRUE, FALSE, TRUE),
        'CiviCRM Version' => CRM_Utils_System::version() . ' ' . $extVersion,
      ],
    ];
    // This is used for new subscriptions/invoices as the default payment method
    if (isset($params['invoice_settings'])) {
      $stripeCustomerParams['invoice_settings'] = $params['invoice_settings'];
    }
    return $stripeCustomerParams;
  }

  /**
   * Delete a Stripe customer from the CiviCRM database
   *
   * @param array $params
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function delete($params) {
    $requiredParams = ['processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new PaymentProcessorException('Stripe Customer (delete): Missing required parameter: ' . $required);
      }
    }
    if (empty($params['contact_id']) && empty($params['customer_id'])) {
      throw new PaymentProcessorException('Stripe Customer (delete): Missing required parameter: contact_id or customer_id');
    }

    $delete = StripeCustomer::delete()
      ->addWhere('processor_id', '=', $params['processor_id']);

    if (!empty($params['customer_id'])) {
      $delete = $delete->addWhere('customer_id', '=', $params['customer_id']);
    }
    else {
      $delete = $delete->addWhere('contact_id', '=', $params['contact_id']);
    }
    $delete->execute();
  }

  /**
   * Update the metadata at Stripe for a given contactid
   *
   * @param int $contactId
   * @param int $processorId optional
   * @return void
   */
  public static function updateMetadataForContact(int $contactId, int $processorId = NULL): void {
    $customers = StripeCustomer::get()
      ->addWhere('contact_id', '=', $contactId);
    if ($processorId) {
      $customers = $customers->addWhere('processor_id', '=', $processorId);
    }
    $customers = $customers->execute();

    // Could be multiple customer_id's and/or stripe processors
    foreach ($customers as $customer) {
      $stripe = new CRM_Core_Payment_Stripe(NULL, $customer['processor_id']);
      CRM_Stripe_Customer::updateMetadata(
        ['contact_id' => $contactId, 'processor_id' => $customer['processor_id']],
        $stripe,
        $customer['customer_id']
      );
    }
  }

}

<?php

use Civi\Api4\Contact;
use Civi\Api4\Extension;
use Civi\Api4\StripeCustomer;
use CRM_Stripe_ExtensionUtil as E;

class CRM_Stripe_BAO_StripeCustomer extends CRM_Stripe_DAO_StripeCustomer {

  /**
   * @param int $contactID
   * @param string|null $email
   * @param array $invoiceSettings
   * @param string|null $description
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function getStripeCustomerMetadata(int $contactID, ?string $email = NULL, array $invoiceSettings = [], ?string $description = NULL) {
    $contactDisplayName = Contact::get(FALSE)
      ->addSelect('display_name', 'email_primary.email', 'email_billing.email')
      ->addWhere('id', '=', $contactID)
      ->execute()
      ->first()['display_name'];

    $extVersion = Extension::get(FALSE)
      ->addWhere('file', '=', E::SHORT_NAME)
      ->execute()
      ->first()['version'];

    $stripeCustomerParams = [
      'name' => $contactDisplayName,
      // Stripe does not include the Customer Name when exporting payments, just the customer
      // description, so we stick the name in the description.
      'description' => $description ?? $contactDisplayName . ' (CiviCRM)',
      'email' => $email ?? '',
      'metadata' => [
        'CiviCRM Contact ID' => $contactID,
        'CiviCRM URL' => CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid={$contactID}", TRUE, NULL, FALSE, FALSE, TRUE),
        'CiviCRM Version' => CRM_Utils_System::version() . ' ' . $extVersion,
      ],
    ];
    // This is used for new subscriptions/invoices as the default payment method
    if (!empty($invoiceSettings)) {
      $stripeCustomerParams['invoice_settings'] = $invoiceSettings;
    }
    return $stripeCustomerParams;
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
  public static function updateMetadata(array $params, \CRM_Core_Payment_Stripe $stripe, string $stripeCustomerID) {
    $requiredParams = ['contact_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (updateMetadata): Missing required parameter: ' . $required);
      }
    }

    $stripeCustomerParams = CRM_Stripe_BAO_StripeCustomer::getStripeCustomerMetadata($params['contact_id'], $params['email'] ?? NULL, $params['invoice_settings'] ?? [], $params['description'] ?? NULL);

    try {
      $stripeCustomer = $stripe->stripeClient->customers->update($stripeCustomerID, $stripeCustomerParams);
    }
    catch (Exception $e) {
      $err = CRM_Core_Payment_Stripe::parseStripeException('create_customer', $e, FALSE);
      $errorMessage = $stripe->handleErrorNotification($err, $params['error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to update Stripe Customer: ' . $errorMessage);
    }
    return $stripeCustomer;
  }

  /**
   * Update the metadata at Stripe for a given contactid
   *
   * @param int $contactID
   *
   * @return void
   */
  public static function updateMetadataForContact(int $contactID): void {
    $customers = StripeCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->execute();

    // Could be multiple customer_id's and/or stripe processors
    foreach ($customers as $customer) {
      /** @var CRM_Core_Payment_Stripe $stripe */
      \Civi\Api4\StripeCustomer::updateStripe(FALSE)
        ->setPaymentProcessorID($customer['processor_id'])
        ->setContactID($contactID)
        ->setCustomerID($customer['customer_id'])
        ->execute()
        ->first();
      $stripe = \Civi\Payment\System::singleton()->getById($customer['processor_id']);
      CRM_Stripe_BAO_StripeCustomer::updateMetadata(
        ['contact_id' => $contactID, 'processor_id' => $customer['processor_id']],
        $stripe,
        $customer['customer_id']
      );
    }
  }

}

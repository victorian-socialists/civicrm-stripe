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

namespace Civi\Api4\Action\StripeCustomer;

/**
 * @inheritDoc
 */
class UpdateStripe extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Stripe Customer ID
   *
   * @var string
   */
  protected $customerID = '';

  /**
   * CiviCRM Contact ID
   *
   * @var int
   */
  protected $contactID = NULL;

  /**
   * The CiviCRM Payment Processor ID
   *
   * @var int
   */
  protected $paymentProcessorID;

  /**
   * Customer email address
   *
   * @var string
   */
  protected $email = NULL;

  /**
   * Customer description
   *
   * @var string
   */
  protected $description = NULL;

  /**
   * Customer name
   *
   * @var string
   */
  protected $name = NULL;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    if (empty($this->customerID) && empty($this->contactID)) {
      throw new \CRM_Core_Exception('Missing customerID or contactID');
    }
    if (empty($this->paymentProcessorID)) {
      throw new \CRM_Core_Exception('Missing paymentProcessorID');
    }
    if (empty($this->customerID) && !empty($this->contactID)) {
      $this->customerID = \Civi\Api4\StripeCustomer::get(FALSE)
        ->addWhere('contact_id', '=', $this->contactID)
        ->execute()
        ->first()['customer_id'];
    }
    if (empty($this->contactID) && !empty($this->customerID)) {
      $this->contactID = \Civi\Api4\StripeCustomer::get(FALSE)
        ->addWhere('customer_id', '=', $this->customerID)
        ->execute()
        ->first()['contact_id'];
    }

    /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
    $paymentProcessor = \Civi\Payment\System::singleton()->getById($this->paymentProcessorID);
    $stripeCustomer = \CRM_Stripe_BAO_StripeCustomer::updateMetadata(['contact_id' => $this->contactID, 'email' => $this->email, 'description' => $this->description], $paymentProcessor, $this->customerID);

    $result->exchangeArray($stripeCustomer->toArray());
  }

}

<?php

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
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (find): Missing required parameter: ' . $required);
      }
    }
    if (empty($params['contact_id'])) {
      throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (find): contact_id is required');
    }
    $queryParams = [
      1 => [$params['contact_id'], 'String'],
      2 => [$params['processor_id'], 'Positive'],
    ];
    

    return CRM_Core_DAO::singleValueQuery("SELECT id
      FROM civicrm_stripe_customers
      WHERE contact_id = %1 AND processor_id = %2", $queryParams);
  }

  /**
   * Find the details (contact_id, processor_id) for an existing Stripe customer in the CiviCRM database
   *
   * @param string $stripeCustomerId
   *
   * @return array|null
   */
  public static function getParamsForCustomerId($stripeCustomerId) {
    $queryParams = [
      1 => [$stripeCustomerId, 'String'],
    ];

    $dao = CRM_Core_DAO::executeQuery("SELECT contact_id, processor_id
      FROM civicrm_stripe_customers
      WHERE id = %1", $queryParams);
    $dao->fetch();
    return [
      'contact_id' => $dao->contact_id,
      'processor_id' => $dao->processor_id,
    ];
  }

  /**
   * Find the details (contact_id, processor_id) for an existing Stripe customer in the CiviCRM database
   *
   * @param string $stripeCustomerId
   *
   * @return array|null
   */
  public static function getAll($processorId, $options = []) {
    $queryParams = [
      1 => [$processorId, 'Integer'],
    ];

    $limitClause = '';
    if ($limit = CRM_Utils_Array::value('limit', $options)) {
      $limitClause = "LIMIT $limit";
      if ($offset = CRM_Utils_Array::value('offset', $options)) {
        $limitClause .= " OFFSET $offset";
      }
    }

    $customerIds = [];
    $dao = CRM_Core_DAO::executeQuery("SELECT id
      FROM civicrm_stripe_customers
      WHERE processor_id = %1 {$limitClause}", $queryParams);
    while ($dao->fetch()) {
      $customerIds[] = $dao->id;
    }
    return $customerIds;
  }

  /**
   * Add a new Stripe customer to the CiviCRM database
   *
   * @param $params
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function add($params) {
    $requiredParams = ['contact_id', 'customer_id', 'processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (add): Missing required parameter: ' . $required);
      }
    }

    $queryParams = [
      1 => [$params['contact_id'], 'String'],
      2 => [$params['customer_id'], 'String'],
      3 => [$params['processor_id'], 'Integer'],
    ];
    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_customers
          (contact_id, id, processor_id) VALUES (%1, %2, %3)", $queryParams);
  }

  /**
   * @param $params
   *
   * @return \Stripe\ApiResource
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function create($params) {
    $requiredParams = ['contact_id', 'card_token', 'processor_id'];
    // $optionalParams = ['email'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (create): Missing required parameter: ' . $required);
      }
    }

    $contactDisplayName = civicrm_api3('Contact', 'getvalue', [
      'return' => 'display_name',
      'id' => $params['contact_id'],
    ]);

    $stripeCustomerParams = [
      'description' => $contactDisplayName . ' (CiviCRM)',
      'card' => $params['card_token'],
      'email' => CRM_Utils_Array::value('email', $params),
      'metadata' => ['civicrm_contact_id' => $params['contact_id']],
    ];

    try {
      $stripeCustomer = \Stripe\Customer::create($stripeCustomerParams);
    }
    catch (Exception $e) {
      $err = CRM_Core_Payment_Stripe::parseStripeException('create_customer', $e, FALSE);
      $errorMessage = CRM_Core_Payment_Stripe::handleErrorNotification($err, $params['stripe_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Stripe Customer: ' . $errorMessage);
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
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (delete): Missing required parameter: ' . $required);
      }
    }
    if (empty($params['contact_id']) && empty($params['id'])) {
      throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (delete): Missing required parameter: contact_id or id');
    }

    if (!empty($params['id'])) {
      $queryParams = [
        1 => [$params['id'], 'String'],
        2 => [$params['processor_id'], 'Integer'],
      ];
      $sql = "DELETE FROM civicrm_stripe_customers
            WHERE id = %1 AND processor_id = %2";
    }
    else {
      $queryParams = [
        1 => [$params['contact_id'], 'String'],
        2 => [$params['processor_id'], 'Integer'],
      ];
      $sql = "DELETE FROM civicrm_stripe_customers
            WHERE contact_id = %1 AND processor_id = %2";
    }
    CRM_Core_DAO::executeQuery($sql, $queryParams);
  }

}

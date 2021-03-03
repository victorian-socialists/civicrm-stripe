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

use CRM_Stripe_ExtensionUtil as E;

/**
 * Stripe.Importcustomers
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_importcustomers_spec(&$spec) {
  $spec['ppid']['title'] = ts("Use the given Payment Processor ID");
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['limit']['title'] = ts("Limit number of Customers/Subscriptions to be imported");
  $spec['limit']['type'] = CRM_Utils_Type::T_INT;
  $spec['limit']['api.required'] = FALSE;
  $spec['starting_after']['title'] = ts('Start importing customers after this one');
  $spec['starting_after']['type'] = CRM_Utils_Type::T_STRING;
  $spec['starting_after']['api.required'] = FALSE;
}

/**
 * Stripe.Importcustomers API
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\UnknownApiErrorException
 */
function civicrm_api3_stripe_importcustomers($params) {
  $ppid = $params['ppid'];
  $limit = isset($params['limit']) ? $params['limit'] : 100;
  $starting_after = $params['starting_after'];

  // Get the payment processor and activate the Stripe API
  $payment_processor = civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $ppid]);
  $processor = new CRM_Core_Payment_Stripe('', $payment_processor);
  $processor->setAPIParams();

  // Prepare an array to collect the results
  $results = [
    'imported' => [],
    'skipped' => [],
    'errors' => [],
    'continue_after' => NULL
  ];

  // Get customers from Stripe
  $args = ["limit" => $limit];
  if ($starting_after) {
    $args['starting_after'] = $starting_after;
  }

  $customers_stripe = \Stripe\Customer::all($args);

  // Exit if there aren't records to process
  if (!count($customers_stripe->data)) {
    return civicrm_api3_create_success($results);
  }

  // Search the customers in CiviCRM
  $customer_ids = array_map(
    function ($customer) { return $customer->id; },
    $customers_stripe->data
  );
  $customers_stripe_clean = $customers_stripe->data;

  $escaped_customer_ids = CRM_Utils_Type::escapeAll($customer_ids, 'String');
  $filter_item = array_map(
    function ($customer_id) { return "'$customer_id'"; },
    $escaped_customer_ids
  );

  if (count($filter_item)) {
    $select = "SELECT sc.*
    FROM civicrm_stripe_customers AS sc
    WHERE
      sc.id IN (" . join(', ', $filter_item) . ") AND
      sc.contact_id IS NOT NULL";
    $dao = CRM_Core_DAO::executeQuery($select);
    $customers_in_civicrm = $dao->fetchAll();
    $customer_ids = array_map(
      function ($customer) { return $customer['id']; },
      $customers_in_civicrm
    );
  } else {
    $customers_in_civicrm = $customer_ids = [];
  }

  foreach ($customers_stripe_clean as $customer) {
    $results['continue_after'] = $customer->id;
    $contact_id = NULL;

    // Return if contact was found
    if (array_search($customer->id, $customer_ids) !== FALSE) {
      $customer_in_civicrm = array_filter($customers_in_civicrm,
        function ($record) use($customer) { return $record['id'] == $customer->id; }
      );
      $results['skipped'][] = [
        'contact_id' => end($customer_in_civicrm)['contact_id'],
        'email' => $customer->email,
        'stripe_id' => $customer->id,
      ];
      continue;
    }

    $c_params = [ 'ppid' => $params['ppid'], 'customer' => $customer->id  ];
    $c_result = civicrm_api3('Stripe', 'Importcustomer', $c_params );
    $c_value = array_pop($c_result['values']);
    $data = [
        'contact_id' => $c_value['contact_id'],
        'email' => $c_value['email'],
        'stripe_id' => $c_value['stripe_id'],
    ];
    if (array_key_exists('skipped', $c_value)) {
      $index = 'skipped';   
    }
    elseif (array_key_exists('dupes', $c_value)) {
      $index = 'errors';
      $data['message'] = 'More then one matching contact was found.';
    }
    else {
      $index = 'imported';
    }
    $results[$index][] = $data;
  }

  return civicrm_api3_create_success($results);
}

<?php

use CRM_Stripe_ExtensionUtil as E;

/**
 * Stripe.Importcustomers
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
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
  $spec['customer']['title'] = ts('Import a specific customer');
  $spec['customer']['type'] = CRM_Utils_Type::T_STRING;
  $spec['customer']['api.required'] = FALSE;
}

/**
 * Stripe.Importcustomers API
 *
 * @param array $params
 * @return void
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

  if ($params['customer']) {
    $customer = \Stripe\Customer::retrieve($params['customer']);
    $customers_stripe_clean = [$customer];
    $customer_ids = [$customer->id];
  }
  else {
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
  }

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

    // Search contact by email
    if ($customer->email || $customer->name) {

      $re = '/^([^\s]*)\s(.*)$/m';
      preg_match_all($re, $customer->name, $matches, PREG_SET_ORDER, 0);
      $first_name = isset($matches[0][1]) ? $matches[0][1] : "-";
      $last_name = isset($matches[0][2]) ? $matches[0][2] : "-";

        // Case to create customer without email
      if(!$customer->email) {
        $contact_ids = [];
      }
      else {
        $email_result = civicrm_api3('Email', 'get', [
          'sequential' => 1,
          'email' => $customer->email,
        ]);

        // List of contact ids using this email address
        $contacts_by_email = array_map(
          function ($found_email) { return $found_email['contact_id']; },
          $email_result['values']
        );

        if (count($contacts_by_email)) {
          // Only consider non deleted records of individuals
          $undeleted_contacts = civicrm_api3('Contact', 'get', [
            'return' => [ 'id' ],
            'id' => [ 'IN' => $contacts_by_email ],
            'is_deleted' => FALSE,
            'contact_type' => 'Individual',
          ]);

          $contact_ids = array_unique(
            array_values(
              array_map(
                function ($found_contact) { return $found_contact['id']; },
                $undeleted_contacts['values']
              )
            )
          );
        } else {
          $contact_ids = [];
        }

        $data = [
          'email' => $customer->email,
          'stripe_id' => $customer->id,
        ];

        if (property_exists($customer, 'name')) {
          $data['name'] = $customer->name;
        }
      }

      if (count($contact_ids) == 0) {
        // Create the new contact record
        $params_create_contact = [
          'sequential' => 1,
          'contact_type' => 'Individual',
          'source' => 'Stripe > ' . $customer->description,
          'first_name' => $first_name,
          'last_name' => $last_name,
        ];

        if ($customer->email) {
          $params_create_contact['email'] = $customer->email;
        }

        $contact = civicrm_api3('Contact', 'create', $params_create_contact);
        $contact_id = $contact['id'];
        // Report the contact creation
        $tag = 'imported';
        $data['contact_id'] = $contact_id;
      }
      else if (count($contact_ids) == 1) {
        $contact_id = end($contact_ids);

        // Report the contact as found by email
        $tag = 'skipped';
        $data['contact_id'] = $contact_id;
      }
      else {
        $contact_id = end($contact_ids);

        // Report the contact as duplicated
        $tag = 'errors';
        $data['warning'] = E::ts("Number of contact records " .
          "with this email is greater than 1. Contact id: $contact_id " .
          "will be used");
        $data['contact_ids'] = $contact_ids;
      }
    }

    $results[$tag][] = $data;

    // Try to create the Stripe customer record
    if ($contact_id != NULL && $contact_id > 0) {
      // Keep running if it already existed
      try {
        CRM_Stripe_Customer::add(
          [
          'contact_id' => $contact_id,
          'id' => $customer->id,
          'processor_id' => $ppid
          ]
        );
      } catch(Exception $e) {
      }

      // Update the record's 'is live' descriptor and its email
      $is_live = ($payment_processor["is_test"] == 1) ? 0 : 1;

      if ($customer->email) {
        $queryParams = [
          1 => [$customer->email, 'String'],
          2 => [$customer->id, 'String'],
          3 => [$is_live, 'Integer'],
          4 => [$contact_id, 'Integer'],
        ];
        CRM_Core_DAO::executeQuery("UPDATE civicrm_stripe_customers
          SET is_live = %3, email = %1, contact_id = %4
          WHERE id = %2", $queryParams);
      }
      else {
        $queryParams = [
          1 => [$customer->id, 'String'],
          2 => [$is_live, 'Integer'],
          3 => [$contact_id, 'Integer'],
        ];
        CRM_Core_DAO::executeQuery("UPDATE civicrm_stripe_customers
          SET is_live = %2, contact_id = %3
          WHERE id = %1", $queryParams);
      }
    }
  }

  return civicrm_api3_create_success($results);
}

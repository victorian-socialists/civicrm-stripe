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
function _civicrm_api3_stripe_importcustomer_spec(&$spec) {
  $spec['ppid']['title'] = ts("Use the given Payment Processor ID");
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['customer']['title'] = ts('Import a specific customer');
  $spec['customer']['type'] = CRM_Utils_Type::T_STRING;
  $spec['customer']['api.required'] = TRUE;
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
function civicrm_api3_stripe_importcustomer($params) {
  $ppid = $params['ppid'];

  // Get the payment processor and activate the Stripe API
  $payment_processor = civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $ppid]);
  $processor = new CRM_Core_Payment_Stripe('', $payment_processor);
  $processor->setAPIParams();

  $customer = \Stripe\Customer::retrieve($params['customer']);

  $return = [
    'stripe_id' => $customer->id,
    'name' => property_exists($customer, 'name') ? $customer->name : NULL,
    'email' => property_exists($customer, 'email') ? $customer->email : NULL,
  ];
  $results = civicrm_api3('StripeCustomer', 'get', [ 'id' => $customer->id ]);
  if ($results['count'] > 0) {
    $return = array_merge($return, $results['values']);
  }
  else {
    $contact_id = NULL;

    // Search contact by email
    if ($customer->email || $customer->name) {
      $re = '/^([^\s]*)\s(.*)$/m';
      preg_match_all($re, $customer->name, $matches, PREG_SET_ORDER, 0);
      $first_name = isset($matches[0][1]) ? $matches[0][1] : "-";
      $last_name = isset($matches[0][2]) ? $matches[0][2] : "-";

      if(!$customer->email) {
        // No point in searching for an existing contact.
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
      }
      else if (count($contact_ids) == 1) {
        $contact_id = end($contact_ids);
        // Report the contact as found by email
        $return['skipped'] = 1;
      }
      else {
        $contact_id = end($contact_ids);
        // Report the contact as duplicated
        $return['dupes'] = $contact_ids;
      }
    }
    $return['contact_id'] = $contact_id;

    // Try to create the Stripe customer record
    if ($contact_id != NULL && $contact_id > 0) {
      // Keep running if it already existed
      try {
        civicrm_api3('StripeCustomer', 'create',
          [
            'contact_id' => $contact_id,
            'id' => $customer->id,
            'processor_id' => $ppid
          ]
        );
      } catch(Exception $e) {
        // No-op
      }
    }
  }

  return civicrm_api3_create_success($return);
}

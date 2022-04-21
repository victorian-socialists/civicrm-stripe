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
 * Stripe.Importsubscriptions
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_importsubscriptions_spec(&$spec) {
  $spec['ppid']['title'] = E::ts('Use the given Payment Processor ID');
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['limit']['title'] = E::ts('Limit number of Customers/Subscriptions to be imported');
  $spec['limit']['type'] = CRM_Utils_Type::T_INT;
  $spec['limit']['api.required'] = FALSE;
  $spec['starting_after']['title'] = E::ts('Start importing subscriptions after this one');
  $spec['starting_after']['type'] = CRM_Utils_Type::T_STRING;
  $spec['starting_after']['api.required'] = FALSE;
}

/**
 * Stripe.Importsubscriptions API
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\UnknownApiErrorException
 */
function civicrm_api3_stripe_importsubscriptions($params) {
  $limit = isset($params['limit']) ? $params['limit'] : 100;
  $starting_after = $params['starting_after'];

  // Get the payment processor and activate the Stripe API
  /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
  $paymentProcessor = \Civi\Payment\System::singleton()->getById($params['ppid']);

  // Get the subscriptions from Stripe
  $args = [
    'limit' => $limit,
    'status' => 'all',
  ];
  if ($starting_after) {
    $args['starting_after'] = $starting_after;
  }
  $stripe_subscriptions = $paymentProcessor->stripeClient->subscriptions->all($args);

  $stripe_subscription_ids = array_map(
    function ($subscription) { return $subscription->id; },
    $stripe_subscriptions->data
  );

  // Prepare to collect the results
  $results = [
    'imported' => [],
    'skipped' => [],
    'errors' => [],
    'continue_after' => NULL
  ];

  // Exit if there aren't records to process
  if (!count($stripe_subscription_ids)) {
    return civicrm_api3_create_success($results);
  }

  // Get subscriptions generated in CiviCRM
  $recurring_contributions = civicrm_api3('ContributionRecur', 'get', [
    'sequential' => 1,
    'options' => [ 'limit' => 0 ],
    'payment_processor_id' => $params['ppid'],
    'trxn_id' => [ 'IN' => $stripe_subscription_ids ]
  ]);

  $subscriptions_civicrm = [];
  if (is_array($recurring_contributions['values'])) {
    foreach ($recurring_contributions['values'] as $recurring_contribution) {
      $trxn_id = $recurring_contribution['trxn_id'];
      $subscriptions_civicrm[$trxn_id] = [
        'contact_id' => $recurring_contribution['contact_id'],
        'recur_id' => $recurring_contribution['id'],
        'stripe_id' => $trxn_id,
      ];
    }
  }

  foreach ($stripe_subscriptions as $stripe_subscription) {
    $results['continue_after'] = $stripe_subscription->id;

    $new_subscription = [
      'is_test' => $paymentProcessor->getIsTestMode(),
      'payment_processor_id' => $params['ppid'],
      'subscription_id' => $stripe_subscription->id,
    ];

    // Check if the subscription exists in CiviCRM
    if (isset($subscriptions_civicrm[$stripe_subscription->id])) {
      $results['skipped'][] = $subscriptions_civicrm[$stripe_subscription->id];
      $new_subscription['recur_id'] = $subscriptions_civicrm[$stripe_subscription->id]['recur_id'];
    }

    // Search the Stripe customer to get the contact id
    $customer_civicrm = civicrm_api3('StripeCustomer', 'get', [
      'sequential' => 1,
      'id' => $stripe_subscription->customer,
    ]);
    if (isset($customer_civicrm['values'][0]['contact_id'])) {
      $new_subscription['contact_id'] = $customer_civicrm['values'][0]['contact_id'];
    }

    // Return the record with error if the contact wasn't found
    if (!isset($new_subscription['contact_id']) || (! $new_subscription['contact_id'])) {
      $new_subscription['error'] = 'Customer not found';
      $new_subscription['stripe_customer'] = $stripe_subscription->customer;
      $results['errors'][] = $new_subscription;
      continue;
    }

    // Create the subscription
    $created_subscription = civicrm_api3('Stripe', 'Importsubscription', $new_subscription);
    $new_subscription['recur_id'] = $created_subscription['values']['recur_id'];
    $results['imported'][] = $new_subscription;
  }

  return civicrm_api3_create_success($results);
}

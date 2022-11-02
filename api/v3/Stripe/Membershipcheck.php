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
 * This api checks autorenewing Memberships
 *
 * It reports problems but does not change any data in CiviCRM or Stripe
 */

/**
 * Stripe.Membershipcheck API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_membershipcheck_spec(&$spec) {
  $spec['ppid']['title'] = E::ts('Use the given Payment Processor ID');
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['membership_id']['title'] = E::ts('Restrict to this membership id');
  $spec['membership_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * Stripe.Membershipcheck API
 *
 * Note: The Stripe API can either bulk retrieve subscriptions up to 100 in a batch
 * or get individual subscription.  Would be nice to speed this up
 * with a bulk retrieve but our starting is Civi Memberships
 *
 * @param $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_membershipcheck($params) {
  $limit = isset($params['options']['limit']) ? $params['options']['limit'] : 50;
  $offset = isset($params['options']['offset']) ? $params['options']['offset'] : 0;

  // Initialise return
  $return = ['stats' => ['Total' => 0, 'OK' => 0]];

  // Get the payment processor and activate the Stripe API
  /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
  $paymentProcessor = \Civi\Payment\System::singleton()->getById($params['ppid']);

  // Get our memberships
  $memberships = \Civi\Api4\Membership::get()
    ->addWhere('contribution_recur_id', 'IS NOT EMPTY')
    ->addWhere('contribution_recur_id.payment_processor_id', '=', $params['ppid'])
    ->addWhere('is_test', '=', FALSE)
    ->addSelect('id', 'contact_id', 'membership_type_id', 'membership_type_id:name', 'status_id:name', 'contribution_recur_id',
     'contribution_recur_id.id', 'contribution_recur_id.contact_id', 'contribution_recur_id.trxn_id',
     'contribution_recur_id.contribution_status_id:name', 'contribution_recur_id.create_date', 'contribution_recur_id.auto_renew')
    ->setLimit($limit)
    ->setOffset($offset);

  if ($params['membership_id']) {
    $memberships = $memberships->addWhere('id', '=', $params['membership_id']);
  }
  $memberships = $memberships->execute()->indexBy('id');

  // Check each membership is consistent
  // Some of these may be redundant, but we're looking for oddities
  foreach ($memberships as $id => $membership) {

    $info = $membership;
    $msgs = [];

    // Check recur contact_id matches membership contact_id
    if ($membership['contact_id'] != $membership['contribution_recur_id.contact_id']) {
      $msgs[] = 'Recur contact_id does not match Membership contact_id ';
    }

    // Check recur id's match
    if ($membership['contribution_recur_id'] != $membership['contribution_recur_id.id']) {
      $msgs[] = 'Recur id on Membership does not match fetched Recur';
    }

    // Look up StripeCustomer record
    $cus = civicrm_api3('StripeCustomer', 'get', [
      'sequential' => 1,
      'contact_id' => $membership['contact_id'],
      'processor_id' => $params['ppid'],
    ]);

    $customer_id = NULL;
    if ($cus['count'] == 0) {
      $msgs[] = 'StripeCustomer has no records for Membership contact_id';
    }
    elseif ($cus['count'] > 1) {
      $msgs[] = 'StripeCustomer has multiple customer_ids for Membership contact_id';
      foreach ($cus['values'] as $c) {
        $info['customer_ids'][] = $c['id'];
      }
    }
    else {
      $customer_id = $cus['values'][0]['id'];
    }
    $info['customer_id'] = $customer_id;

    // Check stripe subscription id exists
    $trxn_id = $membership['contribution_recur_id.trxn_id'];
    if (!$trxn_id) {
      $msgs[] = 'Recur missing Subscription id in trxn_id';
    }
    else {
      $stripe_subscription = NULL;
      try {
        $stripe_subscription = $paymentProcessor->stripeClient->subscriptions->retrieve($trxn_id);
      }
      catch (Exception $e) {
        if ($membership['contribution_recur_id.contribution_status_id:name'] != 'Cancelled') {
          $msgs[] = 'Subscription lookup failed (recur not cancelled)';
          $info['Subcription lookup error'] = $e->getMessage();
        }
      }

      // Check subscription exists
      if ($stripe_subscription) {
        $info['subscription_customer_id'] = $stripe_subscription->customer;
        $info['subscription_status'] = $stripe_subscription->status;

        // Check customer ids match
        if ($stripe_subscription->customer != $customer_id) {
          $msgs[] = 'Subscription customer does not match Civi StripeCustomer customer_id';

          // Check the StripeCustomer table again to see if the customer_id is known with another contact_id
          $cus = civicrm_api3('StripeCustomer', 'get', [
            'sequential' => 1,
            'id' => $stripe_subscription->customer,
            'processor_id' => $params['ppid'],
          ]);
          if ($cus['count'] == 0) {
            $msgs[] = 'No Civi StripeCustomer for the Subscription customer_id';
          }
          else {
            $otherid = $info['contact_id_of_customer_in_stripecustomer'] = $cus['values'][0]['contact_id'];

            // Gather more info about other contact
            $contacts = \Civi\Api4\Contact::get()
              ->addSelect('is_deleted', 'is_deceased', 'activity.subject')
              ->addJoin('Activity AS activity', 'LEFT', 'ActivityContact')
              ->addWhere('id', '=', $otherid)
              ->addWhere('activity.activity_type_id', '=', 72)
              ->execute();
            foreach ($contacts as $contact) {
              $info['Subscription customer info'][] = (array) $contact;
            }
          }

        }

        // Compare the status of Subscription & Recur
        $stripe_status = $stripe_subscription->status;
        $recur_status = $membership['contribution_recur_id.contribution_status_id:name'];
        switch ($stripe_status) {
          case 'active':
            if ($recur_status != 'In Progress') {
              $msgs[] = 'Subscription is active but Recur is not';
            }
            break;

          case 'canceled':
            // Note spelling difference
            if ($recur_status != 'Cancelled') {
              $msgs[] = 'Subscription canceled but Recur is not';
            }
            break;

          // Might want to break out more status combinations. For now, flag everything else
          default:
            $msgs[] = 'Check status of Subscription vs Recur';
        }

        // @todo: might want to compare amounts, contribution history etc
      }
    }

    // If we have messages, updates the stats for each and save info for return
    // otherwise, just update the stats but don't clutter the output with info
    if ($msgs) {
      foreach ($msgs as $msg) {
        if (!isset($return['stats'][$msg])) {
          $return['stats'][$msg] = 0;
        }
        $return['stats'][$msg]++;
      }
      $info['messages'] = $msgs;
      $return['info'][$id] = $info;
    }
    else {
      $return['stats']['OK']++;
    }
    $return['stats']['Total']++;

  }

  return civicrm_api3_create_success($return, $params, 'Stripe', 'membershipcheck');
}

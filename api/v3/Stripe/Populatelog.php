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
 * Populate the CiviCRM civicrm_system_log with Stripe events.
 *
 * This api will take all stripe events known to Stripe that are of the type
 * invoice.payment_succeeded and add them * to the civicrm_system_log table.
 * It will not add an event that has already been added, so it can be run
 * multiple times. Once added, they can be replayed using the Stripe.Ipn
 * api call.
 */

/**
 * Stripe.Populatelog API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_Populatelog_spec(&$spec) {
  $spec['ppid']['title'] = E::ts('The id of the payment processor.');
  $spec['type']['title'] = E::ts('The event type - defaults to invoice.payment_succeeded.');
  $spec['type']['api.default'] = 'invoice.payment_succeeded';
}

/**
 * Stripe.Populatelog API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_Populatelog($params) {
  if (!$params['ppid']) {
    // By default, select the live stripe processor (we expect there to be only one).
    $paymentProcessors = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Stripe')
      ->execute();
    if ($paymentProcessors->rowCount !== 1) {
      throw new API_Exception("Expected one live Stripe payment processor, but found none or more than one. Please specify ppid=.", 2234);
    }
    else {
      $params['ppid'] = $paymentProcessors->first()['id'];
    }
  }

  $listEventsParams['limit'] = 100;
  $listEventsParams['ppid'] = $params['ppid'];
  $items = [];
  $last_item = NULL;
  while(1) {
    if ($last_item) {
      $listEventsParams['starting_after'] = $last_item['id'];
    }
    $events = civicrm_api3('Stripe', 'Listevents', $listEventsParams)['values'];

    if (count($events) == 0) {
      // No more!
      break;
    }
    $items = array_merge($items, $events);
    $last_item = end($events);

    // Support the standard API3 limit clause
    if (isset($params['options']['limit']) && $params['options']['limit'] > 0 && count($items) >= $params['options']['limit']) {
      break;
    }
  }
  $results = [];
  foreach($items as $item) {
    $id = $item['id'];
    // Insert into System Log if it doesn't exist.
    $like_event_id = '%event_id=' . addslashes($id);
    $sql = "SELECT id FROM civicrm_system_log WHERE message LIKE '$like_event_id'";
    $dao= CRM_Core_DAO::executeQuery($sql);
    if ($dao->N == 0) {
      $message = "payment_notification processor_id=${params['ppid']} event_id=${id}";
      $contact_id = _civicrm_api3_stripe_cid_for_trxn($item['charge']);
      if ($contact_id) {
        $item['contact_id'] = $contact_id;
      }
      $log = new CRM_Utils_SystemLogger();
      $log->alert($message, $item);
      $results[] = $id;
    }
  }
  return civicrm_api3_create_success($results);
}

/**
 * @param string $trxn
 *   Stripe charge ID
 *
 * @return int|null
 */
function _civicrm_api3_stripe_cid_for_trxn($trxn) {
  try {
    $params = ['trxn_id' => $trxn, 'return' => 'contact_id'];
    $result = (int)civicrm_api3('Contribution', 'getvalue', $params);
    return $result;
  }
  catch (Exception $e) {
    return NULL;
  }
}

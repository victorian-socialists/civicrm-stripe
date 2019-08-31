<?php

/**
 * This api provides a list of events generated by Stripe
 *
 * See the Stripe event reference for a full explanation of the options.
 * https://stripe.com/docs/api#events
 */

/**
 * Stripe.ListEvents API specification
 *
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_ListEvents_spec(&$spec) {
  $spec['ppid']['title'] = ts("Use the given Payment Processor ID");
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['type']['title'] = ts("Limit to the given Stripe events type, defaults to invoice.payment_succeeded.");
  $spec['type']['api.default'] = 'invoice.payment_succeeded';
  $spec['limit']['title'] = ts("Limit number of results returned (100 is max)");
  $spec['starting_after']['title'] = ts("Only return results after this event id.");
  $spec['output']['api.default'] = 'brief';
  $spec['output']['title'] = ts("How to format the output, brief or raw. Defaults to brief.");
}

/**
 * Stripe.VerifyEventType
 *
 * @param string $eventType
 * @return bolean True if valid type, false otherwise.
 */
function civicrm_api3_stripe_VerifyEventType($eventType) {

	return in_array($eventType, array(
			'account.external_account.created',
			'account.external_account.deleted',
			'account.external_account.updated',
			'application_fee.created',
			'application_fee.refunded',
			'application_fee.refund.updated',
			'balance.available',
			'bitcoin.receiver.created',
			'bitcoin.receiver.filled',
			'bitcoin.receiver.updated',
			'bitcoin.receiver.transaction.created',
			'charge.captured',
			'charge.failed',
			'charge.pending',
			'charge.refunded',
			'charge.succeeded',
			'charge.updated',
			'charge.dispute.closed',
			'charge.dispute.created',
			'charge.dispute.funds_reinstated',
			'charge.dispute.funds_withdrawn',
			'charge.dispute.updated',
			'charge.refund.updated',
			'coupon.created',
			'coupon.deleted',
			'coupon.updated',
			'customer.created',
			'customer.deleted',
			'customer.updated',
			'customer.discount.created',
			'customer.discount.deleted',
			'customer.discount.updated',
			'customer.source.created',
			'customer.source.deleted',
			'customer.source.updated',
			'customer.subscription.created',
			'customer.subscription.deleted',
			'customer.subscription.trial_will_end',
			'customer.subscription.updated',
			'invoice.created',
			'invoice.payment_failed',
			'invoice.payment_succeeded',
			'invoice.upcoming',
			'invoice.updated',
			'invoiceitem.created',
			'invoiceitem.deleted',
			'invoiceitem.updated',
			'order.created',
			'order.payment_failed',
			'order.payment_succeeded',
			'order.updated',
			'order_return.created',
			'payout.canceled',
			'payout.created',
			'payout.failed',
			'payout.paid',
			'payout.updated',
			'plan.created',
			'plan.deleted',
			'plan.updated',
			'product.created',
			'product.deleted',
			'product.updated',
			'recipient.created',
			'recipient.deleted',
			'recipient.updated',
			'review.closed',
			'review.opened',
			'sku.created',
			'sku.deleted',
			'sku.updated',
			'source.canceled',
			'source.chargeable',
			'source.failed',
			'source.transaction.created',
			'transfer.created',
			'transfer.reversed',
			'transfer.updated',
			'ping',
		)
	);
}

/**
 * Process parameters to determine ppid and sk.
 *
 * @param array $params
 *
 * @return array
 * @throws \API_Exception
 */
function civicrm_api3_stripe_ProcessParams($params) {
  $type = NULL;
  $created = NULL;
  $limit = NULL;
  $starting_after = NULL;
  $sk = NULL;

  if (array_key_exists('created', $params) ) {
    $created = $params['created'];
  }
  if (array_key_exists('limit', $params) ) {
    $limit = $params['limit'];
  }
  if (array_key_exists('starting_after', $params) ) {
    $starting_after = $params['starting_after'];
  }

	// Check to see if we should filter by type.
  if (array_key_exists('type', $params) ) {
    // Validate - since we will be appending this to an URL.
    if (!civicrm_api3_stripe_VerifyEventType($params['type'])) {
      throw new API_Exception("Unrecognized Event Type.", 1236);
    }
    else {
      $type = $params['type'];
    }
  }

  // Created can only be passed in as an array
  if (array_key_exists('created', $params)) {
    $created = $params['created'];
    if (!is_array($created)) {
      throw new API_Exception("Created can only be passed in programatically as an array", 1237);
    }
  }
  return ['type' => $type, 'created' => $created, 'limit' => $limit, 'starting_after' => $starting_after];
}

/**
 * Stripe.ListEvents API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_stripe_Listevents($params) {
  $parsed = civicrm_api3_stripe_ProcessParams($params);
  $type = $parsed['type'];
  $created = $parsed['created'];
  $limit = $parsed['limit'];
  $starting_after = $parsed['starting_after'];

  $args = array();
  if ($type) {
    $args['type'] = $type;
  }
  if ($created) {
    $args['created'] = $created;
  }
  if ($limit) {
    $args['limit'] = $limit;
  }
  if ($starting_after) {
    $args['starting_after'] = $starting_after;
  }

  $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $params['ppid']]));
  $processor->setAPIParams();

  $data_list = \Stripe\Event::all($args);
  if (array_key_exists('error', $data_list)) {
    $err = $data_list['error'];
    throw new API_Exception(/*errorMessage*/ "Stripe returned an error: " . $err->message, /*errorCode*/ $err->type);
  }
  $out = $data_list;
  if ($params['output'] == 'brief') {
    $out = array();
    foreach($data_list['data'] as $data) {
      $item = array(
        'id' => $data['id'],
        'created' => date('Y-m-d H:i:s', $data['created']),
        'livemode' => $data['livemode'],
        'pending_webhooks' => $data['pending_webhooks'],
        'type' => $data['type'],
      );
      if (preg_match('/invoice\.payment_/', $data['type'])) {
        $item['invoice'] = $data['data']['object']->id;
        $item['charge'] = $data['data']['object']->charge;
        $item['customer'] = $data['data']['object']->customer;
        $item['subscription'] = $data['data']['object']->subscription;
        $item['total'] = $data['data']['object']->total;

        // Check if this is in the contributions table.
        $item['processed'] = 'no';
        $results = civicrm_api3('Contribution', 'get', array('trxn_id' => $item['charge']));
        if ($results['count'] > 0) {
          $item['processed'] = 'yes';
        }
      }
      $out[] = $item;
    }
  }
  return civicrm_api3_create_success($out);

}



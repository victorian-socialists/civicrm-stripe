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

/**
 * This api retries all non-processed webhooks for a given payment processor.
 *

/**
 * Stripe.Ipn API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_Retryall_spec(&$spec) {
  $spec['ppid']['title'] = ts("The payment processor to use.");
  $spec['ppid']['required'] = TRUE;
  $spec['limit']['title'] = ts("Limit the number of unprocessed events to retry.");
  $spec['limit']['api.default'] = 25;

}

/**
 * Stripe.Retryall API
 *
 * @param array $params
 *
 * @return array
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_Retryall($params) {
  $limit = $params['limit'];
  $ppid = $params['ppid'];

  $params = [
    'ppid' => $ppid,
    'limit' => $limit,
    'filter_processed' => 1,
    'source' => 'systemlog'
  ];
  $values = [];
  $results = civicrm_api3('Stripe', 'ListEvents', $params);
  foreach($results['values'] as $value) {
    if (!isset($value['system_log_id'])) {
      $values[] = 'system_log_id is not set for charge: ' . $value['charge'];
    }
    else {
      $params = [
        'ppid' => $ppid,
        'id' => $value['system_log_id'],
        'suppressreceipt' => 1,
      ];
      $ipn_results = civicrm_api3('Stripe', 'Ipn', $params);
      if ($ipn_results['is_error'] == 0) {
        $values[] = 'Successfully processed charge: ' . $value['charge'];
      }
      else {
        $values[] = 'Failed to process charge: ' . $value['charge'] . 'Results follows. ' . print_r($ipn_results, TRUE);
      }
    }
  }
  return civicrm_api3_create_success($values);
}

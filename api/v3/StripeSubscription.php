<?php

/**
 * Stripe Customer API
 *
 */

/**
 * StripeCustomer.Get API specification
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_subscription_get_spec(&$spec) {
  $spec['subscription_id']['title'] = ts("Stripe Subscription ID");
  $spec['subscription_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['customer_id']['title'] = ts("Stripe Customer ID");
  $spec['customer_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['contribution_recur_id']['title'] = ts("Contribution Recur ID");
  $spec['contribution_recur_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['is_live']['title'] = ts("Is live processor");
  $spec['is_live']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $spec['processor_id']['title'] = ts("Payment Processor ID");
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['end_time_id']['title'] = ts("End Time");
  $spec['end_time_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * StripeSubscription.Get API
 *  This api will get entries from the civicrm_stripe_subscriptions table
 *
 * @param array $params
 * @see civicrm_api3_create_success
 *
 * @return array
 */
function civicrm_api3_stripe_subscription_get($params) {
  foreach ($params as $key => $value) {
    $index = 1;
    switch ($key) {
      case 'subscription_id':
      case 'customer_id':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'String'];
        $index++;
        break;

      case 'contribution_recur_id':
      case 'processor_id':
      case 'end_time':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'Integer'];
        $index++;
        break;

      case 'is_live':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'Boolean'];
        $index++;
        break;
    }
  }

  $query = "SELECT * FROM civicrm_stripe_subscriptions ";
  if (count($where)) {
    $whereClause = implode(' AND ', $where);
    $query .= "WHERE {$whereClause}";
  }
  $dao = CRM_Core_DAO::executeQuery($query, $whereParam);

  while ($dao->fetch()) {
    $result = [
      'subscription_id' => $dao->subscription_id,
      'customer_id' => $dao->customer_id,
      'contribution_recur_id' => $dao->contribution_recur_id,
      'is_live' => $dao->is_live,
      'processor_id' => $dao->processor_id,
      'end_time' => $dao->end_time,
    ];
    $results[] = $result;
  }
  return civicrm_api3_create_success($results);
}

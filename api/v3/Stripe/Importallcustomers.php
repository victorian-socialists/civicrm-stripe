<?php

use CRM_Stripe_ExtensionUtil as E;

/**
 * Stripe.Importallcustomers
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 */
function _civicrm_api3_stripe_importallcustomers_spec(&$spec) {
  $spec['ppid']['title'] = ts("Use the given Payment Processor ID");
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
}

/**
 * Stripe.Importallcustomers API
 *
 * @param array $params
 * @return void
 */
function civicrm_api3_stripe_importallcustomers($params) {
  $result = civicrm_api3('Stripe', 'importcustomers', [
    'ppid' => $params['ppid']
  ]);

  $imported = $result['values'];

  while ($imported['continue_after']) {
    $starting_after = $imported['continue_after'];

    $result = civicrm_api3('Stripe', 'importcustomers', [
      'ppid' => $params['ppid'],
      'starting_after' => $starting_after,
    ]);

    $additional = $result['values'];

    $imported['imported'] = array_merge($imported['imported'], $additional['imported']);
    $imported['skipped'] = array_merge($imported['skipped'], $additional['skipped']);
    $imported['errors'] = array_merge($imported['errors'], $additional['errors']);

    if ($additional['continue_after']) {
      $imported['continue_after'] = $additional['continue_after'];
    } else {
      unset($imported['continue_after']);
    }
  }

  return civicrm_api3_create_success($imported);
}
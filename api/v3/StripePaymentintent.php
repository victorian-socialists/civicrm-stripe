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
 * StripePaymentintent.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_stripe_paymentintent_create($params) {
  return _civicrm_api3_basic_create('CRM_Stripe_BAO_StripePaymentintent', $params, 'StripePaymentintent');
}

/**
 * StripePaymentintent.delete API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function civicrm_api3_stripe_paymentintent_delete($params) {
  return _civicrm_api3_basic_delete('CRM_Stripe_BAO_StripePaymentintent', $params);
}

/**
 * StripePaymentintent.get API
 *
 * @param array $params
 *
 * @return array API result descriptor
 */
function civicrm_api3_stripe_paymentintent_get($params) {
  return _civicrm_api3_basic_get('CRM_Stripe_BAO_StripePaymentintent', $params, TRUE, 'StripePaymentintent');
}

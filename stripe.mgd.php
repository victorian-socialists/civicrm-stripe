<?php

/**
 * The record will be automatically inserted, updated, or deleted from the
 * database as appropriate. For more details, see "hook_civicrm_managed" at:
 * https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
 */
return [
  0 => [
    'name' => 'Stripe',
    'entity' => 'PaymentProcessorType',
    'params' => [
      'version' => 3,
      'name' => 'Stripe',
      'title' => 'Stripe',
      'description' => 'Stripe Payment Processor',
      'class_name' => 'Payment_Stripe',
      'user_name_label' => 'Publishable key',
      'password_label' => 'Secret Key',
      'signature_label' => 'Webhook Secret',
      'url_site_default' => 'http://unused.com',
      'url_site_test_default' => 'http://unused.com',
      'billing_mode' => 1,
      'payment_type' => 1,
      'is_recur' => 1,
    ],
  ],
];

<?php
/**
 * https://civicrm.org/licensing
 */

use CRM_Stripe_ExtensionUtil as E;

return [
  'stripe_jsdebug' => [
    'name' => 'stripe_jsdebug',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'add' => '5.13',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable Stripe Javascript debugging?'),
    'description' => E::ts('Enables debug logging to browser console for stripe payment processors.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 10,
      ]
    ],
  ]
];

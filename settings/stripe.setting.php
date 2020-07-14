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

return [
  'stripe_oneoffreceipt' => [
    'name' => 'stripe_oneoffreceipt',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 1,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Allow Stripe to send a receipt for one-off payments?'),
    'description' => E::ts('Sets the "email_receipt" parameter on a Stripe Charge so that Stripe can send an email receipt.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 10,
      ]
    ],
  ],
  'stripe_jsdebug' => [
    'name' => 'stripe_jsdebug',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable Stripe Javascript debugging?'),
    'description' => E::ts('Enables debug logging to browser console for stripe payment processors.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 15,
      ]
    ],
  ],
  'stripe_ipndebug' => [
    'name' => 'stripe_ipndebug',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable Stripe IPN (Webhook) debugging?'),
    'description' => E::ts('Enables debugging to CiviCRM log for IPN / webhook issues.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 16,
      ]
    ],
  ],
  'stripe_nobillingaddress' => [
    'name' => 'stripe_nobillingaddress',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Disable billing address fields (Experimental)'),
    'description' => E::ts('Disable the fixed billing address fields block. Historically this was required by CiviCRM but since Stripe 6.x the stripe element collects everything it requires to make payment.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 20,
      ]
    ],
  ],
  'stripe_enable_public_future_recur_start' => [
    'name' => 'stripe_enable_public_future_recur_start',
    'type' => 'Array',
    'html_type' => 'select',
    'default' => [],
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable public selection of future recurring start dates for intervals'),
    'description' => E::ts('Allow public selection of start date for a recurring contribution for intervals'),
    'html_attributes' => [
      'multiple' => TRUE,
      'class' => 'crm-select2',
    ],
    'pseudoconstant' => [
      'optionGroupName' => 'recur_frequency_units',
      'keyColumn' => 'name',
      'optionEditPath' => 'civicrm/admin/options/recur_frequency_units',
    ],
    'settings_pages' => [
      'stripe' => [
        'weight' => 25,
      ]
    ],
  ],
  'stripe_future_recur_start_days' => [
    'name' => 'stripe_future_recur_start_days',
    'type' => 'Array',
    'html_type' => 'select',
    'html_attributes' => [
      'size' => 29,
      'multiple' => TRUE
    ],
    'pseudoconstant' => ['callback' => 'CRM_Stripe_Recur::getRecurStartDays'],
    'default' => [0],
    'title' => E::ts('Restrict allowable days of the month for Recurring Contributions'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Restrict allowable days of the month'),
    'settings_pages' => [
      'stripe' => [
        'weight' => 30,
      ],
    ],
  ],
];

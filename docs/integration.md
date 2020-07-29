# Integrations

## Drupal Webform + Webform_civicrm

The Stripe extension works with Drupal Webform + Webform_civicrm.

#### Setup

You must enable the [contributiontransactlegacy](https://github.com/mjwconsult/civicrm-contributiontransactlegacy) CiviCRM extension which replaces the Contribution.transact API with a working one. In the
future we hope that webform_civicrm switches to a supported API.

Minimum version: 7.x-5.1 or 8.x-5.1

#### Known issues

Test Mode will not work for drupal webform if there are multiple payment processors configured on the webform.

#### Recurring Payments

For recurring payments make sure that:
1. You have either "Number of installments" or "Interval of installments" element on the same "page" as the payment.
2. The elements are either visible or hidden (hidden element) - do NOT select hidden (secure value) as it won't work.


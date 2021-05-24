# CiviCRM Stripe Payment Processor

Integrates the Stripe payment processor (for Credit/Debit cards) into CiviCRM so you can use it to accept Credit / Debit card payments on your site.

* https://stripe.com/

Latest releases can be found in the [CiviCRM extensions directory](https://civicrm.org/extensions/stripe-payment-processor)

**Always read the [Release Notes](https://docs.civicrm.org/stripe/en/latest/releasenotes/) carefully before upgrading!**

Stripe generously supports CiviCRM, providing a portion of every transaction processed by users of CiviCRM through its platform at no cost to the organizations using CiviCRM.

## Documentation

[Available here](https://docs.civicrm.org/stripe/en/latest).

## Configuration
All configuration is in the standard Payment Processors settings area in CiviCRM admin.
You will enter your "Publishable" & "Secret" key given by stripe.com.

## Installation
See Documentation above.

**If using drupal webform or other integrations that use Contribution.transact API you MUST install the [contributiontransactlegacy](https://github.com/mjwconsult/civicrm-contributiontransactlegacy) extension to work around issues with that API.**

The extension will show up in the extensions browser for automated installation.
Otherwise, download and install as you would for any other CiviCRM extension.

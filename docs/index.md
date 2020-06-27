# Stripe Payment Processor for CiviCRM.
Integrates the Stripe payment processor (for Credit/Debit cards) into CiviCRM so you can use it to accept Credit / Debit card payments on your site.

[![Stripe Logo](images/stripe.png)](https://stripe.com/)

View/Download this extension in the [Extension Directory](https://civicrm.org/extensions/stripe-payment-processor).

## Supports
* PSD2 / SCA payments on one-off payments, partial support for recurring payments (may not be able to authorise card in some cases).
* Cancellation of subscriptions from Stripe / CiviCRM.
* Refund of payments from Stripe.

### Does not support
* Updating Stripe subscriptions from CiviCRM.

## Compatibility / Requirements
* CiviCRM 5.24+
* PHP 7.2+
* Jquery 1.10 (Use jquery_update module on Drupal).
* Drupal 7 / Joomla / Wordpress (latest supported release). *Not currently tested with other CMS but it may work.*
* Stripe API version: 2019-12-03+
* Drupal webform_civicrm 7.x-5.0+ (if using webform integration) - see [Integration](integration.md) for more details.

* [MJWShared extension](https://civicrm.org/extensions/mjwshared) version 0.8.

**Please ensure that you are running the ProcessStripe scheduled job every hour or you will have issues with failed/uncaptured payments appearing on customer credit cards and blocking their balance for up to a week!**

## Troubleshooting
Under *Administer->CiviContribute->Stripe Settings* you can find a setting:
* Enable Stripe Javascript debugging?

> This can be switched on to output debug info to the browser console and can be used to debug problems with submitting your payments.

## Support and Maintenance
This extension is supported and maintained with the help and support of the CiviCRM community by:

[![MJW Consulting](images/mjwconsulting.jpg)](https://www.mjwconsult.co.uk)

We offer paid [support and development](https://mjw.pt/support) as well as a [troubleshooting/investigation service](https://mjw.pt/investigation).

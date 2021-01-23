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
* CiviCRM 5.28+
* PHP 7.2+
* Jquery 1.10+ (Use jquery_update module on Drupal 7).
* Drupal 7 / Drupal 8 / Joomla / Wordpress (latest supported release).
* Stripe API version: 2019-12-03+ (recommended: 2020-08-27).
* Drupal webform_civicrm 7.x-5.1+ (if using webform integration) - see [Integration](integration.md) for more details.

#### Required extensions

* [MJWShared extension](https://civicrm.org/extensions/mjwshared) version 0.9.4.
* [SweetAlert extension](https://civicrm.org/extensions/sweetalert) version 1.4+.

#### Recommended extensions

* [Firewall extension](https://civicrm.org/extensions/firewall) version 1.1+.
* [contributiontransactlegacy extension](https://civicrm.org/extensions/contribution-transact-api) version 1.3+.

**Please ensure that you are running the "Stripe: Cleanup" scheduled job every hour or you will have issues with failed/uncaptured payments appearing on customer credit cards and blocking their balance for up to a week!**

## Troubleshooting
Under *Administer->CiviContribute->Stripe Settings* you can find a setting:
* Enable Stripe Javascript debugging?

> This can be switched on to output debug info to the browser console and can be used to debug problems with submitting your payments.

## Support and Maintenance
This extension is supported and maintained with the help and support of the CiviCRM community by:

[![MJW Consulting](images/mjwconsulting.jpg)](https://www.mjwconsult.co.uk)

We offer paid [support and development](https://mjw.pt/support) as well as a [troubleshooting/investigation service](https://mjw.pt/investigation).

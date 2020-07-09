## Information

Releases use the following numbering system:
**{major}.{minor}.{incremental}**

Where:

* major: Major refactoring or rewrite - make sure you read and test very carefully!
* minor: Breaking change in some circumstances, or a new feature. Read carefully and make sure you understand the impact of the change.
* incremental: A "safe" change / improvement. Should *always* be safe to upgrade.

## Release 6.4.2

* Fix [#210](https://lab.civicrm.org/extensions/stripe/-/issues/210): If there are multiple reCaptcha on the page check and validate the one on the Stripe billing form only.
* Update implementation for cancel subscription. Option to notify (default Yes) is now available on the backend cancel subscription form.
* Fix [#218](https://lab.civicrm.org/extensions/stripe/-/issues/218): Ensure disallowed characters in description don't stop contributions from being processed.
* Fixes to system checks (check for sweetalert was showing description for firewall extension).
* Fix [#215](https://lab.civicrm.org/extensions/stripe/-/issues/218) Errors encountered when anonymous users switch payment processors.
* Fix for 5.28 and propertybag.

## Release 6.4.1
**This release REQUIRES that you upgrade mjwshared to 0.8.**

* Fix [#196](https://lab.civicrm.org/extensions/stripe/issues/196): Recurring contributions with incorrect amount per default currency in stripe.
* Fix [#198](https://lab.civicrm.org/extensions/stripe/issues/198): Trigger postInstall hook so we set the revision and don't trigger the upgrader on install.
* Fix [#182](https://lab.civicrm.org/extensions/stripe/issues/182): Failed subscription payment, receipt sent, but contribution not updated when retried.
* Change validator error class to match CiviCRM (use crm-inline-error instead of error css class - see https://github.com/civicrm/civicrm-core/pull/16495)
* Don't specify charset/collation when creating tables on new installs (use the database default).
* Return 200 OK for webhook events we don't handle (normally they won't be sent as the extension specifies what it requires but if configured manually it may receive events that we don't handle).
* Switch to new "recommended" contribution.repeattransaction and payment.create for new IPN payments.
* Implement new doCancelRecur functionality and hide optional notify processor on 5.27+ (this has no impact on older versions of CiviCRM).
* Fix CSRF token issues with civicrmStripeConfirm.js (3ds challenge was not triggering on thankyou page).
* civicrmStripeConfirm.js is now a library available at CRM.payment.confirm and builds on CRM.payment.
* Initial support for \Civi\Payment\PropertyBag.
* Improve handling of network errors when loading Stripe elements and add a new jquery event: *crmBillingFormReloadFailed*.
* Improve user notifications during pre-authentication and confirmation.
* Add check for recommended SweetAlert extension.
* Fix [#208](https://lab.civicrm.org/extensions/stripe/issues/182) use window.alert if SweetAlert is not installed.
* Make sure we reset submitted flag if we are not able to submit the form.
* Fix issues with form validation when you enable the "On behalf of Organisation" block on contribution pages - see https://lab.civicrm.org/extensions/stripe/-/issues/147#note_38994 and https://github.com/civicrm/civicrm-core/pull/17672.

## Release 6.4
**This release REQUIRES that you upgrade mjwshared to 0.7 and your Stripe API version must be 2019-12-03 or newer.**

#### New Features:

* The Stripe "element" now follows the current CMS/CiviCRM locale.
* Add jquery form events:
  * 'crmBillingFormReloadComplete' and document jquery events.
  * 'crmBillingFormNotValid' so 3rd-party integrations can re-enable custom submit buttons etc.
      Add custom property on billing form to allow for custom validations

* Add support for sweetalert library on form validation errors so we popup nice messages when you are missing required fields and for card errors and you click submit.
* Make sure we don't submit the form if we have a reCaptcha and it is not valid.
* Add setting to disable billing address fields.
* Major improvements to form validation before submission - this significantly reduces the number of payments that are authorised but not captured.
* Add a minimum API version so we don't have problems every time Stripe release a new API version.
* Change style of card element

#### Bugfixes:

* Make sure we generate backend contact links for customer metadata (previously they would sometimes get generated as frontend links).
* Fix missing receipts for recurring subscription payment [#122](https://lab.civicrm.org/extensions/stripe/issues/122).
* Fix [#178](https://lab.civicrm.org/extensions/stripe/issues/178) recurring payments for webform_civicrm when "Interval of installments" is selected.
* If Stripe is not using the same currency as the payment was made we need to convert the fees/net amounts back to the CiviCRM currency.
* Fix [#196](https://lab.civicrm.org/extensions/stripe/issues/196) Recurring contributions with incorrect amount per default currency in stripe - if Stripe uses a different currency to CiviCRM the amounts for recurring contributions were not being recorded correctly in CiviCRM.
* Fix [#189](https://lab.civicrm.org/extensions/stripe/issues/189) Error on membership contribution page with autorenew set to automatic.

#### Behind the scenes:

* Further tweaks to get tests working
* Initial steps to modernize the testing infrastructure.
* Add some docblocks to the code.
* Switch to event.code from deprecated event.keyCode.

##### Client side (javascript):

* Add support for a function getTotalAmount that could be used to retrieve amount from form if defined.
* Restrict use of amount when creating paymentIntents.
* Fix issues with stripe js on thankyou pages.
* Call IPN->main() from inside a try catch to allow loops [!94](https://lab.civicrm.org/extensions/stripe/-/merge_requests/94)
* Use minifier extension to minify js/css assets (much easier for development as we don't ship minified files anymore).

## Release 6.3.2 - Security Release

If you are using Stripe on public forms (without authentication) it is **strongly** recommended that you upgrade and consider installing the new **firewall** extension.

Increasingly spammers are finding CiviCRM sites and spamming the linked Stripe account with 1000s of attempted payments
and potentially causing your Stripe account to be temporarily blocked.

#### Changes
* Add support for firewall extension
* Add system check to recommend installing firewall extension
* Add checks and restrictions to AJAX endpoint
* Add cache code to js/css resources so they are reloaded immediately after cache clear.

* [#168](https://lab.civicrm.org/extensions/stripe/issues/168) Improve handling of webhooks with mismatched API versions - now we track the dashboard API version and don't try to explicitly set a webhook API version.
You may still need to delete and re-add your webhook but should not need to next time the API version changes.

#### Features
* [#126](https://lab.civicrm.org/extensions/stripe/issues/126) Stripe element now uses the CMS/CiviCRM locale so it will appear in the same language as the page instead of the browser language.

## Release 6.3.1

* Add crm-error class to stripe card errors block so it is highlighted on non bootstrap themes
* Fix Stripe.ipn API when working with charge.captured/succeeded
* Update documentation to mention contributiontransactlegacy extension
* [#147](https://lab.civicrm.org/extensions/stripe/issues/147) Add workaround and set required billing fields via jquery
* [#153](https://lab.civicrm.org/extensions/stripe/issues/153) Support multiple participant registration and CiviDiscount with zero amount.
* Fix non-stripe submit check - if amount is zero.

## Release 6.3
**This release REQUIRES that you upgrade mjwshared to 0.6 and your Stripe API version to 2019-12-03.**

*If you wish to test the upgrade you can remain on an older version or later but should update the API version as soon as you are happy.*

* **Update required Stripe API version to 2019-12-03**
* Add support for recording partial refunds from Stripe.
* For forms that have multiple submit buttons (eg. Save, Save and New) override the submit handler on all of them *(This fixes some more instances of missing PaymentIntentID on the backend forms).*
* Resolve issues with backend forms and tax amounts *(fixes issues with backend forms that include an additional tax amount)*.
* Resolve issues with money formats that don't use a dot as decimal separator (eg. €1.024,20).
* Update required Stripe API verison to 2019-12-03.
* Fix issues with StripeSubscription.import and mismatched id/customer_id params.
* Fix [#125](https://lab.civicrm.org/extensions/stripe/issues/125) Thousands of failed/spam transactions for charge.failed webhook *(We ignore and return 200 OK to Stripe so it does not retry if there is no customer ID)*.
* Change default to 1 hour to cancel uncaptured payments based on client feedback.
* Update definition of getAmount to match current version in CiviCRM core.
* Pre-fill existing billing postcode if we already have address.
* Fix recurring contribution issue on drupal webform.
* [#148](https://lab.civicrm.org/extensions/stripe/issues/148) Fix Credit or debit card translation.
* Fix [#149](https://lab.civicrm.org/extensions/stripe/issues/149) Cannot submit payment from back end when tax and invoicing disabled.

## Release 6.2.2

* Make sure we detect memberships as auto-renew when they are "forced".
* Make sure we always load the recurring contribution ID properly.

## Release 6.2.1

* [#121](https://lab.civicrm.org/extensions/stripe/issues/121) Fix auto-recurring membership payments.
* Stripe.ListEvents API - properly handle newer way to record trxn_id value.
* Change doRefund signature to match what is in CiviCRM core (fixes a PHP warning).

## Release 6.2

* Track paymentIntents and cancel uncaptured ones after 24 hours.
  > Configurable via scheduled Job.process_stripe and API.
* Refactor to support updating amount and re-confirming once we reach the thankyou page.
* When we don't know the amount, pre-auth the card but don't confirm, then update the amount requested.
  > This resolves, for example, registering multiple participants. Users may receive an additional confirmation step such as 3d secure on the *Thankyou* page if their card issuer requires it.
* Refactor passing of token parameters to use pre_approval_parameters.
  > This should resolve some issues with *PaymentIntent not found*.
* Improve support for refunds in preparation for work in CiviCRM core (#15476-15479).
* Add CiviCRM version info to stripe customer - this is useful when troubleshooting issues on client sites as it is important to know whether a reported issue may have been fixed in a later version.
* Fix [#110](https://lab.civicrm.org/extensions/stripe/issues/110) -Allow submit if amount is 0.
* Fix and record paymentIntents for recurring contributions - show authentication to user on thankyou page.
  > this checks loads 3d secure etc from Stripe if required.
* Don't try to record refund for an uncaptured payment.
  > When an uncaptured payment is cancelled it triggers a charge.refunded event. But we don't want to record this in CiviCRM as it was never "captured" and the payment was never really taken.

## Release 6.1.6

* Fix [#103](https://lab.civicrm.org/extensions/stripe/issues/103) - Allow submission of drupal webform when there are multiple processors on the page (eg. Stripe + Pay later).

## Release 6.1.5

* Send email receipts from Stripe by default (as this was what 5.x did). Add a setting under Administer->CiviContribute->Stripe Settings to enable/disable receipts from Stripe.
* Support recording full refunds from Stripe.

## Release 6.1.4
**This release fixes a MAJOR issue that caused duplicate payments to be taken when a new recurring contribution (subscription) was setup.  All users of 6.x should upgrade.**

* Don't take payment twice on recurring payments (This was happening because a payment was being created via a paymentIntent and subsequently via the first invoice generated by the subscription - currently we don't support 3D secure on subscriptions, this will be fixed in a future release).
* If we get an error when submitting, make sure we run Stripe submit next time as well so we generate a paymentIntent/paymentMethod for the payment (this fixes the issue where only the first submission attempt would succeeed - subsequent submission attempts would fail with "Missing paymentIntentID").
* Validate payment forms using javascript so we don't create multiple uncaptured paymentIntents when the form is not valid (each time the form was submitted and failed because eg. the email address was invalid a new paymentIntent would be created).

## Release 6.1.3

**You need to Fix/Create webhook after installing this update to add the `charge.captured` event to the list of events sent to the webhook.**

* Handle charge.succeeded/charge.captured when no customer_id is provided - fixes 400 errors / missing customer_id.
* Remove invalid setting of customer on paymentIntent (no user impact).
* Small improvements to Stripe Plan code (no user impact).

*Note: You should use [this CiviCRM core patch](https://github.com/civicrm/civicrm-core/pull/15340) if using webform_civicrm 4.28.*

## Release 6.1.2

* Fix [#89](https://lab.civicrm.org/extensions/stripe/issues/89) - Payment Element is not loading for backend "Credit Card Event Registration".
* Fix repeatContribution - pass the found contribution ID instead of using a separate previous_contribution variable - fixes !63

## Release 6.1.1

* Fix issue with charge.succeeded triggering error on recurring contributions

## Release 6.1

*This release fixes a number of bugs/issues identified after the release of 6.0.*

#### Upgrade Advice

**IMPORTANT!** If upgrading to 6.x for the first time make sure you install the [mjwshared](https://lab.civicrm.org/extensions/mjwshared) extension
**BEFORE** you upgrade Stripe.

This release requires an upgrade to version 0.4 of the MJWShared extension.

 **ALL users of 6.0 should upgrade to this release.**

 If upgrading to 6.x for the first time, please upgrade directly to 6.1 (do not install 6.0 first).

#### Changes

* Support cards that do not request a postal/zip code (*fixes [#80](https://lab.civicrm.org/extensions/stripe/issues/80)*).
* Enable payments on backend (*fixes [#79](https://lab.civicrm.org/extensions/stripe/issues/79)*).
* Resolve multiple issues with "more than one" payment processor on the form and stripe failing to submit if it wasn't the first to be selected:
  * Fix issue when script is reloaded by changes of payment processors.
  * Improve handling for multiple processors and pay later.
  * Make sure we clear the paymentIntentID from the session once we've used it (this prevents a browser refresh trying to use an already captured paymentIntent).

## Release 6.0

*Switch to Stripe Elements for SAQ-A compliance on most sites and support the European Secure Customer Authentication (SCA) payments directive.*

**This is a major new release. You cannot rollback once you've upgraded.**

**This extension REQUIRES the [mjwshared](https://lab.civicrm.org/extensions/mjwshared) extension.**

**You MUST update your API version on the stripe dashboard!**

* Use [Stripe Elements](https://stripe.com/payments/elements).
* Use PaymentIntents and comply with the European [SCA directive](https://stripe.com/docs/strong-customer-authentication).
* Require Stripe API Version: 2019-09-09 and ensure that all codepaths specify the API version.
* Switch publishable key/secret key in settings (upgrader does this automatically) so they are now "correct" per CiviCRM settings pages.
* Support cards using 3dsecure and cards not using 3dsecure (workflows with Stripe are slightly different but both are now handled).
* Use minified versions of js/css.
* Improve payment descriptors and customer information that is sent from CiviCRM to Stripe.
* Add basic support for PaymentProcessor.refund API.

#### What is NOT supported:

* CiviCRM Event Cart (requires additional funding, changes should probably be made in CiviCRM core to standardize that workflow rather than adding support via this extension).
* Card payments via the admin backend (this was supported in 5.4.1 but has unresolved issues with Stripe Elements when used via popup forms and is not allowed in most situations when complying with the SCA payments directive unless you are approved to accept "MOTO" payments).

## Release 5.4.1

* Don't overwrite system messages when performing webhook checks.
* Add form to handle creating/updating webhooks instead of automatically during system check (Thanks @artfulrobot)

## Release 5.4

This release fixes multiple bugs and introduces a few small features.

**A major feature for this release is the automatic management of webhooks:**
Note that when you upgrade you may end up with duplicate webhooks in Stripe with slightly different addresses (particularly on Wordpress where the path should be urlencoded).  Just delete the older (duplicate) webhooks manually from your Stripe dashboard.

* Fix drupal webform detection so it doesn't generate a false positive if we also have a webform on the same page.
* Fix Stripe create customer in test mode.
* Fix offline (live) event payments for Wordpress.
* If payment fails and we have no contribution don't crash when trying to create a note.
* Fix null dates returning as December 31, 1969 or 1 Jan 1970 (depending on your
    timezone) - also see the commandline script in `utils/fix-issue-44.php` to
    correct your Contributions data.

* Support Drupal 8 Webform.
* Automatically manage and create webhooks.
* Add StripeCustomer.updatestripemetadata API.
* Add a system check for invalid API key.
* Add StripeCustomer.delete to delete a customer from CiviCRM.
* Add StripeSubscription.import API to import subscriptions into CiviCRM.
* Add Stripe.cleanup API.
* Report all Stripe errors, not just authentication when running status checks.

* Remove `is_live` field from `civicrm_stripe_customer` - we can get this from the payment processor ID.

## Release 5.3.2

* Fix retrieving email receipt parameter on stripe IPN which stopped contributions from being marked as completed.
* Fix webhook check for wordpress so we don't get false positives when everything is configured ok.

## Releae 5.3.1

* Fix issue with event/membership payments failing to record in CiviCRM (introduced in 5.3).

## Release 5.3

**All users should upgrade to 5.3.1 due to an issue with event/membership payments**

There are no database changes in this release but you should update your Stripe webhook API version to 2019-02-19.

### Changes
* Update required Stripe API version from 2018-11-08 to 2019-02-19.
* Update stripe-php library from 6.19.5 to 6.30.4.

### Fixes
* Make sure we clear processor specific metadata from payment form when switching payment processor (fixes https://lab.civicrm.org/extensions/stripe/issues/26).
* Fix saving of fee amount and transaction ID on contribution record.

### Features
* Add a Webhook System Check.
* Send a friendly success response if we receive the test webhook.
* Webhooks now work in test mode.
* Use the parameter on the recurring contribution to decide whether to send out email receipts.

## Release 5.2

*This release introduces a number of new features, standardises the behaviour of recurring contributions/memberships to match standard CiviCRM functionality and does a major cleanup of the backend code to improve stability and allow for new features.*

### Highlights:

* Support Cancel Subscription from CiviCRM and from Stripe.

### Breaking changes:

* The extension now uses the standard CiviCRM Contribution.completetransaction and Contribution.repeattransaction API to handle creation/update of recurring contributions. This means that automatic membership renewal etc. is handled in the standard CiviCRM way instead of using custom code in the Stripe extension. The behaviour *should* be the same but some edge-cases may be fixed while others may appear. Any bugs in this area will now need to be fixed in CiviCRM core - if you want to help with that see https://github.com/civicrm/civicrm-core/pull/11556.
* When recurring contributions were updated by Stripe, they were marked cancelled and a new one created in CiviCRM. This was non-standard behaviour and causes issues with CiviCRM core functionality for membership renewal etc. This has now been changed so only one recurring contribution per subscription will ever exist, which will be updated as necessary during it's lifecycle.
* Different payment amounts are now supported for each contribution in a recurring contribution. Previously they were explicitly rejected by the extension.

### Changes:

* Add http response codes for webhook (invalid parameters now returns 400 Bad Request).
* Major refactor of webhook / events handling (fixes multiple issues, now tested and working on Joomla / Wordpress / Drupal 7).
* Update to latest version of stripe-php library.
* Handle "Customer Deleted" from Stripe.
* Drop use of civicrm_stripe_plans table and just query Stripe each time. This prevents errors when they get out of sync

### Upgrading
**Please upgrade to 5.0 if you are on ANY older version. Then upgrade directly to 5.2. You do not need to install 5.1 first.**

Make sure you run the extension upgrades immediately after updating the code.  There are two MAJOR upgrade steps:
1. Migrate civicrm_stripe_customers table to match on contact_id instead of email address. This can be re-run if necessary using StripeCustomer.updatecontactids API.
2. Migrate data from civicrm_stripe_subscriptions to use the recurring contribution (trxn_id = Stripe subscription ID). This can be re-run if necessary using StripeSubscription.updatetransactionids API.


## Release 5.1
*This was a limited release to selected clients in order to test new functionality.  **Production sites should upgrade to 5.2 directly from 5.0**.*

### Changes:
* Use contact_id as reference in civicrm_stripe_customers and don't require an email address for payment.
* Drop old webhook code / endpoint at https://{yoursitename.org}/civicrm/stripe/webhook. You **MUST** update your webhooks to use the standard CiviCRM endpoint at https://{yoursitename.org}/civicrm/payment/ipn/XX (see [Webhooks and Recurring Payments](/recur.md) for details).


## Release 5.0
*This is the first release with a new maintainer (mattwire https://www.mjwconsult.co.uk) and repository move to https://lab.civicrm.org/extensions/stripe.*

**If upgrading from ANY version before 5.0 you should upgrade to this version first. It should be a safe upgrade for all sites on previous versions unless you are running a customised version of the extension.**

### Highlights:
* Fix all known "Stripe.js token was not passed".
* Tested support for Drupal 7 / Wordpress / Joomla for contributions/event payments.
* Improvements to recurring payments (though you will want to upgrade to 5.2 if using recurring payments as recurring payments has had a major rewrite for 5.2).


# Alpha / Beta releases

## Release 6.0.beta1

*Thanks to Rich Lott (@artfulrobot) for contributing and testing this release.*

* We don't need to confirm the payment until we capture it
* payment method id is not required when passing in an existing payment intent
* Use minified versions of js/css.
* Remove onclick attribute from submit form so that CiviContribute forms do stripe processing before submission
* Description and Customer fields in Stripe backend - fixes #78

## Release 6.0.alpha3

* Support recurring payments with paymentIntents/Elements. Cancel subscription with Stripe when we reach recurring end date
* **Update required Stripe API version to 2019-09-09**
* Handle confirmation pages properly for contribution pages (make sure we pass through paymentIntentID).
* Handle card declined on client side.
* Support creating recurring payment (subscription).
* Handle IPN events for charges / invoices (support cancel/refund etc).
* Add basic support for PaymentProcessor.refund API.
* Remove membership_type_tag from plan name.

## Release 6.0.alpha2

* Support Drupal Webform CiviCRM.
* Support Event Registration.
* Support Confirm/Thankyou pages on contribution pages / events.
* Support cards using 3dsecure and cards not using 3dsecure.

### Not Supported (should be in final 6.0 release):
* Recurring payments.
* Backend payments.

## Release 6.0.alpha1

* ONLY contribution pages with no confirm pages are supported.

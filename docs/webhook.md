# Webhooks

## Overview
Stripe webhooks allow for CiviCRM to be notified of changes in payment status and new payments.

If the webhooks are not working CiviCRM will not be able to update the status of payments and recurring contributions.

## Configuring Webhooks
The extension manages / creates the webhooks. A system check is run to verify if the webhooks are created and have the correct parameters. If not a *Wizard* is provided to create them for you.

To check if webhooks are configured correctly login to your Stripe Dashboard and look at **Developers > Webhooks**

## Notifications

Stripe notifies CiviCRM in the following circumstances:

* A Charge is successful (not normally used as we are already notified during the actual payment process).
* A Charge fails - sometimes a charge may be delayed (eg. for Fraud checks) and later fails.
* A Charge is refunded - if a charge is refunded via the Stripe Dashboard it will update in CiviCRM.

* An invoice is created and paid.
* An invoice payment fails.
* A subscription is cancelled.
* A subscription is updated.

## Unknown events

If CiviCRM receives an event with information it cannot match with an entity in CiviCRM:
  - customer ID => CiviCRM contact.
  - subscription ID => CiviCRM recurring contribution.
  - invoice ID / charge ID => CiviCRM contribution.

The webhook will return `200 OK` and Stripe will assume it was handled successfully. This allows
for the Stripe account to be used with multiple clients (eg. CiviCRM, WooCommerce, Stripe Dashboard etc.)
without triggering webhook errors.

You can enable `Enable Stripe IPN (Webhook) debugging?` in *Administer->CiviContribute->Stripe Settings*
if you would like to see these events in the CiviCRM logs.

Also see [Automatic import of subscriptions / payments](roadmap.md#automatic-import-of-subscriptions--payments) on the roadmap if you'd like to see this improved.

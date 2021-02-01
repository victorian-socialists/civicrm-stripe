# Roadmap of planned features
This roadmap may not always be up to date but gives an idea of what is planned and where funding/support is required.

## Automatic import of subscriptions / payments

If we already have the Stripe customer in CiviCRM we can import subscriptions and one-off contributions that were
created outside of CiviCRM (eg. via WooCommerce, Stripe Dashboard).

### Specification
1. Add a setting to control whether import should happen automatically.
2. Update Stripe IPN code to automatically create:
  - A recurring contribution when an unknown Stripe subscription ID is received.
  - A contribution linked to a recurring contribution when an unknown invoice ID is received.
  - A contribution when an unknown charge ID is received (that does not have an associated invoice ID).
  - A payment linked to a contribution via charge ID / invoice ID.

### Estimate

This would require funding for approximately 12 hours work.

## UI to map customers to CiviCRM contacts

Based on the existing `Stripe.Importcustomers` API we can build a UI in CiviCRM that allows to manually
confirm and map Stripe customer IDs to CiviCRM contacts.

### Specification

1. Build a UI that lists Stripe customers which do not exist in CiviCRM.
2. Provide options to:
  - Confirm the auto-detected mapping.
  - Manually find and map contact.
  - Import all missing subscriptions/contributions for customer (up to certain date?).

### Estimate

This would require funding for approximately 16 hours work.

## Card on File

Stripe supports saving cards and other payment methods. These can be retrieved, re-used and updated.
We would like to provide support for re-using saved cards in CiviCRM.

### Specification

1. Develop a method to deduplicate payment methods (see eg. https://github.com/stripe/stripe-payments-demo/issues/45).
  Cards are duplicated by default and we need to clean this up before we can provide a UI to retrieve cards in CiviCRM.
2. Build a UI to allow for selection of cards to use for making payment.
3. Integrate card-selection UI into payment flow (so for example a form validation failure will remember the card you just entered and verified).

### Estimate

This would require funding for approximately 24 hours work.

## Payment Methods

## Stripe Connect

See https://stripe.com/connect

## Payment Requests (Google / Apple Pay)

See https://lab.civicrm.org/extensions/stripe/-/issues/81

## Stripe ACH/EFT

See https://civicrm.org/make-it-happen/stripe-ach-payments

## Bancontact

See https://stripe.com/docs/payments/bancontact

This is a "Bank Redirect" type of payment.

### Implementation

1. Create a paymentIntent of type "bancontact" and return the client secret.
2. Collect payment method details (name) on the client form.
3. Handle redirect to bancontact to make payment and return to CiviCRM.
4. Handle payment_intent.succeeded webhook to confirm payment.

### Estimate

20 hours. This represents approximately 12 hours for the generic "Bank Redirect" functionality
in the Stripe extension and then 8 hours for the specific payment method "Bancontact".

Adding additional payment methods such as Sofort would then require approximately 8 hours each
to implement.



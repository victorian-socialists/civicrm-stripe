# Setup and configuration
Please make sure you have read and followed the instructions under [Install](install.md) first.

## Scheduled Jobs

### Job.process_stripe

This job automatically cancels abandoned (uncaptured) paymentIntents after 1 hour and cleans up old records in the `civicrm_stripe_paymentintent` table.

* Run: Hourly
* Domain-specific: No. This job only needs to be run on one of the domains for multisite/multidomain setup.

If this job is *not* running then the abandoned payments will show on the clients card for up to one week.

## Receipts

In addition to the receipts that CiviCRM can send, Stripe will send it's own receipt for payment by default.

If you wish to disable this under *Administer->CiviContribute->Stripe Settings* you can find a setting that allows you to disable Stripe from sending receipts:

* Allow Stripe to send a receipt for one-off payments?

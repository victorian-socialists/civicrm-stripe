# API

This extension comes with several APIs to help you troubleshoot problems. These can be run via /civicrm/api or via `cv api Stripe.xxx`.

The api commands are:

## Stripe

* `Stripe.Listevents`: Events are the notifications that Stripe sends to the Webhook. Listevents will list all notifications that have been sent. You can further restrict them with the following parameters:
  * `ppid` - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
  * `type` - Limit to the given Stripe events type. By default, show invoice.payment_succeeded. Change to 'all' to show all.
  * `output` - What information to show. Defaults to 'brief' which provides a summary. Alternatively use raw to get the raw JSON returned by Stripe.
  * `limit` - Limit number of results returned (100 is max, 10 is default).
  * `starting_after` - Only return results after this event id. This can be used for paging purposes - if you want to retreive more than 100 results.
  * `source` - By default, source is set to "stripe" and limited to events reported by Stripe in the last 30 days. If instead you specify "systemlog" you can query the `civicrm_system_log` table for events, which potentially go back farther then 30 days.
  * `subscription` - If you specify a subscription id, results will be limited to events tied to the given subscription id. Furthermore, both the `civicrm_system_log` table will be queried and the results will be supplemented by a list of expected charges based on querying Stripe, allowing you to easily find missing charges for a given subscription.
  * `filter_processed` - Set to 1 if you want to filter out results for contributions that have been properly processed by CiviCRM already.

* `Stripe.Populatelog`: If you are running a version of CiviCRM that supports the SystemLog - then this API call will populate your SystemLog with all of your past Stripe Events. You can safely re-run and not create duplicates. With a populated SystemLog - you can selectively replay events that may have caused errors the first time or otherwise not been properly recorded. Parameters:
  * `ppid` - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.

* `Stripe.Ipn`: Replay a given Stripe Event. Parameters. This will always fetch the chosen Event from Stripe before replaying.
  * `id` - The id from the SystemLog of the event to replay.
  * `evtid` - The Event ID as provided by Stripe.
  * `ppid` - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
  * `noreceipt` - Set to 1 if you want to suppress the generation of receipts or set to 0 or leave out to send receipts normally.

* `Stripe.Cleanup`: Cleanup and remove old database tables/fields that are no longer required.

### Import related.

#### Subscriptions

When importing subscriptions, all invoice/charges assigned to the subscription will also be imported.

* `Stripe.importallsubscriptions` - import all subscriptions for a given payment processor.
  * Parameters:
    * `ppid` : Use the given Payment Processor ID.
  * Example
    * `drush cvapi Stripe.importallsubscriptions ppid=6
  * Doc
    * [Stripe doc](https://stripe.com/docs/api/subscriptions/list)

* `Stripe.importsubscriptions` - import some subscriptions for a given payment processor
  * Parameters:
    * `limit`: Numer of elements to import.
    * `ppid` : Use the given Payment Processor ID.
    * `starting_after` : Not required, begin after the last subscriber.
  * Example
    * `drush cvapi Stripe.importsubscriptions ppid=6 limit=1 starting_after=sub_DDRzfRsxxxx`
  * Doc
    * [Stripe doc](https://stripe.com/docs/api/subscriptions/list)

* `Stripe.importsubscription` - import a single subscription for a given payment processor.
  * Parameters:
    * `ppid` : Use the given Payment Processor ID.
    * `subscription` : the stripe subscription id
  * Example
    * `drush cvapi Stripe.importsubscriptions ppid=6 subscription=sub_DDRzfRsxxxx`
  * Doc
    * [Stripe doc](https://stripe.com/docs/api/subscriptions/list)


#### Customers 

* `Stripe.importallcustomers` - import all customers for a given payment processor.
  * Parameters:
    * `ppid` : Use the given Payment Processor ID.
  * Example
    * `drush cvapi Stripe.importallcustomers ppid=6
  * Doc
    * [Stripe doc](https://stripe.com/docs/api/customers/list)

* `Stripe.importcustomers` - import some customers for a given payment processor
  * Parameters:
    * `limit`: Numer of elements to import.
    * `ppid` : Use the given Payment Processor ID.
    * `starting_after` : Not required, begin after the last customer.
  * Example
    * `drush cvapi Stripe.importcustomers ppid=6 limit=1 starting_after=cus_DDRzfRsxxxx`
  * Doc
    * [Stripe doc](https://stripe.com/docs/api/customers/list)

* `Stripe.importcustomer` - import a single customer for a given payment processor.
  * Parameters:
    * `ppid` : Use the given Payment Processor ID.
    * `customer` : the stripe customer id
  * Example
    * `drush cvapi Stripe.importcustomers ppid=6 customer=cus_DDRzfRsxxxx`
  * Doc
    * [Stripe doc](https://stripe.com/docs/api/customers/list)

#### Charges

* `Stripe.importcharge` - import a single charge.
  * Parameters:
    * `ppid` : Use the given Payment Processor ID.
  * Example:
    * `drush cvapi Stripe.importcharge ppid=6 charge=ch_DDRzfRsxxxx contact_id=1234`
  * [Stripe doc](https://stripe.com/docs/api/charges/list)

## StripeCustomer

* `StripeCustomer.get` - Fetch a customer by passing either civicrm contact id or stripe customer id.
* `StripeCustomer.create` - Create a customer by passing a civicrm contact id.
* `StripeCustomer.delete` - Delete a customer by passing either civicrm contact id or stripe customer id.
* `StripeCustomer.updatecontactids` - Used to migrate civicrm_stripe_customer table to match on contact_id instead of email address.
* `StripeCustomer.updatestripemetadata` - Used to update stripe customers that were created using an older version of the extension (adds name to description and contact_id as a metadata field).

## StripePaymentintents

#### `StripePaymentintents.get`
It can be used for debugging and querying information about attempted / successful payments.

#### `StripePaymentintents.create`
This API is used internally for tracking and managing paymentIntents.
It's not advised that you use this API for anything else.

#### `StripePaymentintents.Process`
This API is used by the client javascript integration and by third-party frontend integrations.
Please contact [MJW Consulting](https://mjw.pt/stripe) if you require more information or are planning to use this API.

Permissions: `access Ajax API` + `make online contributions`

#### `StripePaymentintents.createorupdate`
This API is used by the client javascript integration to create or update the `civicrm_stripe_paymentintent` table.

Permissions: `access Ajax API` + `make online contributions`

## Scheduled Jobs

* `Job.process_stripe` - this cancels uncaptured paymentIntents and removes successful ones from the local database cache after a period of time:

  Parameters:
  * delete_old: Delete old records from database. Specify 0 to disable. Default is "-3 month"
  * cancel_incomplete: Cancel incomplete paymentIntents in your stripe account. Specify 0 to disable. Default is "-1 hour"




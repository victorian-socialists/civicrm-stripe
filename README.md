CiviCRM Stripe Payment Processor
--------------------------------

## Requirements
* PHP 5.6+
* Jquery 1.10 (Use jquery_update module on Drupal).
* Drupal 7 / Joomla / Wordpress (latest supported release).
*Not currently tested with other CMS but it may work.*
* CiviCRM 5.0+
* Stripe API version: ??? 

### How to update Stripe API version
Go to _Account Settings_ -> _API Keys_ tab -> click _Upgrade available_ button.  
More info on how to change:  https://stripe.com/docs/upgrades#how-can-i-upgrade-my-api  

## Configuration
All configuration is in the standard Payment Processors settings area in CiviCRM admin.  
You will enter your "Publishable" & "Secret" key given by stripe.com.  

## Installation
Download and install extension via CiviCRM's "Manage Extensions" page.  

## Webhook and Recurring Contributions

**TODO: Replace with documentation for NEW CiviCRM standard method**

The Webhook.php file is registered to the path of civicrm/stripe/webhook  
You will have to make a Webhook rule in your Stripe.com account and enter this path for recurring charges to end!  
For Drupal:  https://example.com/civicrm/stripe/webhook  
For Joomla:  https://example.com/index.php/component/civicrm/?task=civicrm/stripe/webhook  
For Wordpress:  https://example.com/?page=CiviCRM&q=civicrm/stripe/webhook  

If you have multiple Stripe accounts on your site, you will need to specify the payment processor ID in the webhook URL.
To find the ID, look at the URL when you are editing the payment processor in CiviCRM: it should include `id=XX`, where `XX` is your payment processor ID.
Add a URL parameter of `ppid=XX` to the webhook URL.
For example, for a payment processor ID of 3, use the following:
For Drupal:  https://example.com/civicrm/stripe/webhook?ppid=3
For Joomla:  https://example.com/index.php/component/civicrm/?task=civicrm/stripe/webhook&ppid=3
For Wordpress:  https://example.com/?page=CiviCRM&q=civicrm/stripe/webhook&ppid=3

### Cancelling Recurring Contributions
You can cancel a recurring contribution from the Stripe.com dashboard. Go to Customers and then to the specific customer.
Inside the customer you will see a Subscriptions section. Click Cancel on the subscription you want to cancel.
Stripe.com will cancel the subscription and will send a webhook to your site (if you have set the webhook options correctly).
 Then the stripe_civicrm extension will process the webhook and cancel the Civi recurring contribution.

## API
This extension comes with several APIs to help you troubleshoot problems. These can be run via /civicrm/api or via drush if you are using Drupal (drush cvapi Stripe.XXX).

The api commands are:

 * Listevents: Events are the notifications that Stripe sends to the Webhook. Listevents will list all notifications that have been sent. You can further restrict them with the following parameters:
  * ppid - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
  * type - Limit to the given Stripe events type. By default, show invoice.payment_succeeded. Change to 'all' to show all.
  * output - What information to show. Defaults to 'brief' which provides a summary. Alternatively use raw to get the raw JSON returned by Stripe.
  * limit - Limit number of results returned (100 is max, 10 is default).
  * starting_after - Only return results after this event id. This can be used for paging purposes - if you want to retreive more than 100 results.
 * Populatelog: If you are running a version of CiviCRM that supports the SystemLog - then this API call will populate your SystemLog with all of your past Stripe Events. You can safely re-run and not create duplicates. With a populated SystemLog - you can selectively replay events that may have caused errors the first time or otherwise not been properly recorded. Parameters:
  * ppid - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
 * Ipn: Replay a given Stripe Event. Parameters. This will always fetch the chosen Event from Stripe before replaying.
  * id - The id from the SystemLog of the event to replay.
  * evtid - The Event ID as provided by Stripe.
  * ppid - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
  * noreceipt - Set to 1 if you want to suppress the generation of receipts or set to 0 or leave out to send receipts normally.

# TESTING

### PHPUnit
This extension comes with two PHP Unit tests:

 * Ipn - This unit test ensures that a recurring contribution is properly updated after the event is received from Stripe and that it is properly canceled when cancelled via Stripe.
 * Direct - This unit test ensures that a direct payment to Stripe is properly recorded in the database.

Tests can be run most easily via an installation made through CiviCRM Buildkit (https://github.com/civicrm/civicrm-buildkit) by changing into the extension directory and running:

    phpunit4 tests/phpunit/CRM/Stripe/IpnTest.php
    phpunit4 tests/phpunit/CRM/Stripe/DirectTest.php

### Katalon Tests
See the test/katalon folder for instructions on running full web-browser based automation tests.

Expects a drupal (demo) site installed at http://localhost:8001

1. Login: No expected result, just logs into a Drupal CMS.
1. Enable Stripe Extension: Two payment processors are created, can be done manually but processor labels must match or subsequent tests will fail.
1. Offline Contribution, default PP: A contribution is created for Arlyne Adams with default PP.
1. Offline Contribution, alternate PP: A contribution is created for Arlyne Adams with alternate PP.
1. Offline Membership, default PP: A membership/contribution is created for Arlyne Adams with default PP.
1. Offline Membership, alternate PP: A membership/contribution is created for Arlyne Adams with alternate PP.
1. Offline Event Registration, default PP: A participant record/contribution is created for Arlyne Adams with default PP.
1. Offline Event Registration, alternate PP: A participant record/contribution is created for Arlyne Adams with alternate PP.

ONLINE contribution, event registration tests
ONLINE webform test

### Manual Tests

1. Test webform submission with payment and user-select, single processor.
1. TODO: Are we testing offline contribution with single/multi-processor properly when stripe is/is not default with katalon tests?

1. Test online contribution page on Wordpress.
1. Test online contribution page on Joomla.
1. Test online event registration.
1. Test online event registration (cart checkout).

## Credits / Maintenance

### Original Author
Joshua Walker - http://drastikbydesign.com - https://drupal.org/user/433663  

### Maintainer
---------------
Peter Hartmann - https://blog.hartmanncomputer.com

### Other Credits
-------------
For bug fixes, new features, and documentation, thanks to:
rgburton, Swingline0, BorislavZlatanov, agh1, jmcclelland, mattwire

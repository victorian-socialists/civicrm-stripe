# Integrations

## Drupal Webform + Webform_civicrm

The Stripe extension works with Drupal Webform + Webform_civicrm.

#### Setup

!!! note "Drupal 7"
    You must enable the [contributiontransactlegacy](https://github.com/mjwconsult/civicrm-contributiontransactlegacy) CiviCRM extension which replaces the Contribution.transact API with a working one. In the
    future we hope that webform_civicrm switches to a supported API.

Minimum version: 7.x-5.5 or 8.x-5.0

#### Known issues

Test Mode will not work for drupal webform if there are multiple payment processors configured on the webform.

#### Recurring Payments

For recurring payments make sure that:

1. You have either "Number of installments" or "Interval of installments" element on the same "page" as the payment.
2. The elements are either visible or hidden (hidden element) - do NOT select hidden (secure value) as it won't work.

## Custom integrations

These notes cover how to create Stripe payments and subscriptions without using CiviCRM's Contribution pages.

### Create a new recurring contribution / subscription.

In your front end, collect the card details using Stripe Elements, and using Stripe's JS APIs get a `paymentMethodID`.

In the back end:

#### Create a pending ContributionRecur and a pending Order (Contribution).

e.g.

```php
<?php
$contributionRecurID = civicrm_api3('ContributionRecur', 'create', [
  'amount'                 => 1.00,
  'contact_id'             => 12345,
  'contribution_status_id' => "Pending",
  'currency'               => 'GBP',
  'financial_type_id'      => 1,
  'frequency_interval'     => 1,
  'frequency_unit'         => "month",
  'is_test'                => 1,
  'payment_processor_id'   => $stripeTestPayProcessorID,
])['id'];

$orderCreateParams = [
  'receive_date'           => date('Y-m-d H:i:s'),
  'total_amount'           => 1.00,
  'contact_id'             => 12345,
  "payment_instrument_id"  => 1,
  'currency'               => 'GBP',
  'financial_type_id'      => 1,
  'contribution_recur_id'  => $contributionRecurID,
  'contribution_status_id' => 'Pending',
  'is_test'                => 1,
  'line_items' => [
    [
      'line_item' => [[
        'line_total'        => 1
        'unit_price'        => 1,
        "price_field_id"    => 1,
        'financial_type_id' => 1,
        'qty'               => 1,
      ]]
    ]
  ],
];
civicrm_api3('Order', 'create', $orderCreateParams);
```

#### Call `CRM_Core_Payment_Stripe::doPayment()`

Then assemble the inputs for Stripe's `doPayment()` in a PropertyBag and call it.

This will create all the Stripe-specific structures that are needed: a
Customer, a Plan and a Subscription. It will also save the card
(paymentMethodID) to the customer.

The subscription, (unless you set a future start date) will have an invoice,
and that invoice will have a payment intent.

The payment intent is automatically captured, if possible.

e.g.

```php
<?php
/** @var CRM_Core_Payment_Stripe */
$paymentProcessor = getTheStripePaymentObject();
/** @var array */
$input = getTheInputData();

$doPaymentParams = new \Civi\Payment\PropertyBag();
$doPaymentParams->setCustomProperty('paymentMethodID', $input['paymentMethodID']);
$doPaymentParams->setContactID($input['contactID']);
$doPaymentParams->setContributionID($input['contributionID']);
$doPaymentParams->setIsRecur(TRUE);
$doPaymentParams->setRecurFrequencyUnit('month');
$doPaymentParams->setContributionRecurID($input['contributionRecurID']);
$doPaymentParams->setAmount($input['amount']);
$doPaymentParams->setEmail($input['email']);
$doPaymentParams->setDescription($input['description']);

$result = $paymentProcessor->doPayment($doPaymentParams);
```

##### Observations I suspect need discussion

The function `endDoPayment()` in [CRM_Core_Payment_MJWTrait.php](https://lab.civicrm.org/extensions/mjwshared/-/blob/master/CRM/Core/Payment/MJWTrait.php#L596)
is used to filter and construct the return params for `doPayment()` as agreed in [dev/financial#141](https://lab.civicrm.org/dev/financial/-/issues/141).

But.. note that the results may already be out of date because the related objects (contribution etc.)
may have been updated by webhook events received from Stripe.

- To get the real result you may need to disregard the data returned from
  `doPayment` and reload the objects, just using their IDs. From there you can
  see whether you have a payment intent yet, and what state it is in.

#### Check for SCA requirements

If you’re lucky (and if you didn't set a future start date), everything will
have gone through. But often you’ll need to do Secure Customer Authentication (SCA).

This is identified by the payment intent having a status of `requires_action`
and you’ll need to pass `paymentIntentID` and `paymentIntentClientSecret` back to
your Javascript which will need to use the Stripe APIs to handle that.

In this case, after handling the SCA, assuming successful, the payment intent
at Stripe will have moved on, possibly to status `needs_capture`, or
`completed`. CiviCRM will be told about this by an IPN call. As we need to
allow for the `needs_capture` case, your Javascript must now call
`StripePaymentintent.process`, passing the `paymentIntentID`. This will see
what needs doing, do it, and update CiviCRM records.

#### Things that have changed since this was written.

API3 `StripePaymentIntent.Process` now allows you to implement Stripe [setupIntents](https://stripe.com/docs/api/setup_intents)
which allow you to capture the user authentication (eg. 3DSecure) without taking payment.
This is used for creating subscriptions and for delayed payments when you don't know the exact amount until you've completed "checkout".

See civicrmStripe.js for an example implementation. Note the return values in our implementation are more consistent that paymentIntents.


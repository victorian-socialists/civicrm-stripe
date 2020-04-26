# Recurring Contributions

A CiviCRM **Recurring Contribution** is the equivalent of a Stripe **Subscription**.

The CiviCRM Recurring Contribution `trxn_id` = Stripe `subscription ID`.

When you create a recurring contribution in CiviCRM using the Stripe payment processor it is linked via the trxn_id to a Stripe subscription.

!!! tip "If you are using recurring contributions make sure you have webhooks configured correctly"
    See [Webhooks](/webhook)

## Cancelling Recurring Contributions
You can cancel a recurring contribution from the Stripe Dashboard or from within CiviCRM.

#### In Stripe

1. Go to Customers and then to the specific customer.
1. Inside the customer you will see a Subscriptions section.
1. Click Cancel on the subscription you want to cancel.
1. Stripe.com will cancel the subscription, send a webhook to your site and the recurring contribution will be marked as "Cancelled" in CiviCRM.

![Cancel Subscription in Stripe](/images/stripedashboard_cancelsubscription.png)

#### In CiviCRM
1. Click the "Cancel" link next to the recurring contribution.
1. Select the option to *Send cancellation request to Stripe?* and click Cancel.
1. Stripe.com will cancel the subscription, send a webhook to your site and the recurring contribution will be marked as "Cancelled" in CiviCRM.

![Cancel Subscription in CiviCRM](/images/backend_cancelrecur.png)

## Recurring statuses

Recurring contributions start in the status "Pending".

The Stripe subscription status is mapped to the recurring contribution status as follows:

STATUS_INCOMPLETE => Pending
STATUS_ACTIVE => In Progress
STATUS_TRIALING => In Progress
STATUS_PAST_DUE => Overdue
STATUS_CANCELED / STATUS_UNPAID / STATUS_INCOMPLETE_EXPIRED => Cancelled

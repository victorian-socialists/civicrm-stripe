# Contributions, Payments and Customers

A CiviCRM **Contribution** is the equivalent of a Stripe **Invoice**.

A CiviCRM **Payment** is the equivalent of a Stripe **Charge**.

A CiviCRM **Contact** is the equivalent of a Stripe **Customer**.

## Invoices and Charges?

For a **one-off contribution** an invoice is *NOT* created, so we have to use the Stripe `Charge ID`. In this case we set the contribution `trxn_id` = Stripe `Charge ID`.

For a **recurring contribution** an invoice is created for each contribution:

* We set the contribution `trxn_id` = Stripe `Invoice ID`.
* We set individual payments on that contribution (which could be a payment, a failed payment, a refund) to have `trxn_id` = Stripe `Charge ID`

## Payment Metadata

When we create a contribution in CiviCRM (Stripe Invoice/Charge) we add some metadata to that payment.
* The statement descriptor contains a parsable `contactID-contributionID` and then part of the description.
* The description contains the description, a parsable `contactID-contributionID` and then the CiviCRM (unique) invoice ID.
![Stripe Payment](/images/stripedashboard_paymentdetail.png)

## Customer Metadata

Every time we create a new contribution/recurring contribution we create/update a Stripe customer with the following metadata:
* Contact Name, Email address
* Description (`CiviCRM: ` + site name).
* Contact ID
* Link to CiviCRM contact record
![Stripe Customer](/images/stripedashboard_customerdetail.png)

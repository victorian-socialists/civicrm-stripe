# Payment Methods

The Stripe extension implements the following payment methods:

## Credit / Debit Card

### Card Element

See: [Stripe Card Element](https://stripe.com/docs/payments/payment-methods/overview#cards).

![card element](images/element_card.png)

This is enabled by default. No additional configuration is required.

It is supported for all payment types.

### Payment Request Button (Apple/Google/Microsoft Pay)

See: [Stripe PaymentRequest Button](https://stripe.com/docs/stripe-js/elements/payment-request-button).

![paymentrequest element](images/element_paymentrequest.png)

#### Configuration

In *Administer->CiviContribute->Stripe Settings* you need to set `Country where your account is registered` to
 the two character country code of your account (eg. "US").

#### Supported configurations

The PaymentRequest element will be displayed automatically instead of the card element if:
1. The client browser has support for the paymentRequest button.
2. The payment is a *one-off*. Recurring payments are not supported and the element will not be displayed in this case.

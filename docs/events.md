# Events and custom form integrations

Most of the functionality to take card details, validate the form etc. happens on the javascript/jquery side before the form is submitted.

If you are customising the frontend form you may need to respond to events triggered by the Stripe extension.

## Available Events

### crmBillingFormNotValid
This event is triggered when the form has been submitted but fails because of a validation error on the form.
It is most useful for re-enabling form elements that were disabled during submission.

Example code:
```javascript
    $form.on('crmBillingFormNotValid', e => {
      console.log("resetting submit button as form not submitted");
      $customSubmitButton.prop('disabled', false).text($customSubmitButton.data('text'));
    });
```

### crmBillingFormReloadComplete
This event is triggered when the form has completed reloading and is ready for use (Stripe element visible etc.).
It is useful for clearing any "loading" indicators and unfreezing form elements.

### crmStripeBillingFormReloadComplete
This event is triggered when the form has completed reloading, is ready for use and the selected payment processor is Stripe.

### crmBillingFormReloadFailed
This event is triggered when the form fails to complete reloading and is NOT ready for use. It usually happens if there
is a problem (such as a network issue) when loading the Stripe elements.
If this event is triggered the user will see an error message popup and appear in the card-errors div.

> Could not load payment element - Is there a problem with your network connection?

The payment elements will be hidden.

## Custom validation / Form Data

#### crmBillingFormValid
If you want to do some validation of the form and prevent Stripe from submitting you can set the boolean data property
on the form.

Example code:
```javascript
    $('#my-custom-submit-button').on('click', e => {
      e.preventDefault();
      $form.data('crmBillingFormValid', true);
      if (myCustomValidation() === false) {
        $form.data('crmBillingFormValid', false);
      }
    });
```

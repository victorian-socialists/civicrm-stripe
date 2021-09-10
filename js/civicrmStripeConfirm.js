/**
 * This handles confirmation actions on the "Thankyou" pages for
 * contribution/event workflows.
 */
(function($, ts) {

  var script = {
    name: 'stripe',
    stripe: null,
    scriptLoading: false,

    /**
     * Handle the response from the server for the payment/setupIntent
     * @param paymentIntentProcessResponse
     */
    handleIntentServerResponse: function(paymentIntentProcessResponse) {
      CRM.payment.debugging(script.name, 'handleIntentServerResponse');
      if (paymentIntentProcessResponse.requires_action) {
        // Use Stripe.js to handle required card action
        if (CRM.vars[script.name].hasOwnProperty('paymentIntentID')) {
          script.handlePaymentIntentAction(paymentIntentProcessResponse);
        }
        else if (CRM.vars[script.name].hasOwnProperty('setupIntentID')) {
          script.handleCardConfirm();
        }
      }
      else if (paymentIntentProcessResponse.requires_payment_method) {
        CRM.payment.debugging(script.name, 'Payment failed - requires_payment_method');
        CRM.payment.swalFire({
          title: '',
          text: ts('The payment failed - please try a different payment method.'),
          icon: 'error'
        }, '', true);
      }
      else if (paymentIntentProcessResponse.success) {
        // All good, nothing more to do
        CRM.payment.debugging(script.name, 'success - payment captured');
        CRM.payment.swalFire({
          title: ts('Payment successful'),
          icon: 'success'
        }, '', true);
      }
      else {
        CRM.payment.debugging(script.name, 'Payment Failed - unknown error');
        CRM.payment.swalFire({
          title: '',
          text: ts('The payment failed - unknown error.'),
          icon: 'error'
        }, '', true);
      }
    },

    /**
     * Handle the next action for the paymentIntent
     * @param paymentIntentProcessResponse
     */
    handlePaymentIntentAction: function(paymentIntentProcessResponse) {
      switch (CRM.vars[script.name].paymentIntentMethod) {
        case 'automatic':
          script.stripe.handleCardPayment(paymentIntentProcessResponse.paymentIntentClientSecret)
            .then(function(handleCardPaymentResult) {
              if (handleCardPaymentResult.error) {
                // Show error in payment form
                CRM.payment.debugging(script.name, handleCardPaymentResult.error.message);
                CRM.payment.swalFire({
                  title: '',
                  text: handleCardPaymentResult.error.message,
                  icon: 'error'
                }, '', true);
              }
              else {
                // The card action has been handled
                CRM.payment.debugging(script.name, 'handleCardPayment success');
                script.handleCardConfirm();
              }
            });
          break;

        case 'manual':
          script.stripe.handleCardAction(paymentIntentProcessResponse.paymentIntentClientSecret)
            .then(function(handleCardActionResult) {
              if (handleCardActionResult.error) {
                // Show error in payment form
                CRM.payment.debugging(script.name, handleCardActionResult.error.message);
                CRM.payment.swalFire({
                  title: '',
                  text: handleCardActionResult.error.message,
                  icon: 'error'
                }, '', true);
              }
              else {
                // The card action has been handled
                CRM.payment.debugging(script.name, 'handleCardAction success');
                script.handleCardConfirm();
              }
            });
          break;
      }
    },

    /**
     * Handle the card confirm (eg. 3dsecure) for paymentIntent / setupIntent
     */
    handleCardConfirm: function() {
      CRM.payment.debugging(script.name, 'handle card confirm');
      if (CRM.vars[script.name].hasOwnProperty('paymentIntentID')) {
        // Send paymentMethod.id to server
        CRM.api3('StripePaymentintent', 'Process', {
          payment_intent_id: CRM.vars[script.name].paymentIntentID,
          capture: true,
          payment_processor_id: CRM.vars[script.name].id,
          description: document.title,
          csrfToken: CRM.vars[script.name].csrfToken
        })
          .done(function(paymentIntentProcessResponse) {
            CRM.payment.swalClose();
            // Handle server response (see Step 3)
            CRM.payment.debugging(script.name, 'StripePaymentintent.Process done');
            if (paymentIntentProcessResponse.is_error) {
              // Triggered for api3_create_error or Exception
              CRM.payment.swalFire({
                title: '',
                text: paymentIntentProcessResponse.error_message,
                icon: 'error'
              }, '', true);
            }
            else {
              paymentIntentProcessResponse = paymentIntentProcessResponse.values;
              script.handleIntentServerResponse(paymentIntentProcessResponse);
            }
          })
          .fail(function(object) {
            var error = ts('Unknown error');
            if (object.hasOwnProperty('statusText') && (object.statusText !== 'OK')) {
              // A PHP exit can return 200 "OK" but we don't want to display "OK" as the error!
              if (object.statusText === 'parsererror') {
                error = ts('Configuration error - unable to process paymentIntent');
              }
              else {
                error = object.statusText;
              }
            }
            CRM.payment.debugging(script.name, error);
            CRM.payment.swalFire({
              title: '',
              text: error,
              icon: 'error'
            }, '', true);
          });
      }
      else if (CRM.vars[script.name].hasOwnProperty('setupIntentID')) {
        if (CRM.vars[script.name].setupIntentNextAction.type === 'use_stripe_sdk') {
          CRM.payment.swalClose();
          script.stripe.confirmCardSetup(CRM.vars[script.name].setupIntentClientSecret)
            .then(function(confirmCardSetupResult) {
              // Handle confirmCardSetupResult.error or confirmCardSetupResult.setupIntent
              if (confirmCardSetupResult.error) {
                // Record error and display message to user
                CRM.api3('StripePaymentintent', 'createorupdate', {
                  stripe_intent_id: CRM.vars[script.name].setupIntentID,
                  status: 'error',
                  csrfToken: CRM.vars[script.name].csrfToken
                });

                CRM.payment.debugging(script.name, confirmCardSetupResult.error.message);
                CRM.payment.swalFire({
                  title: '',
                  text: confirmCardSetupResult.error.message,
                  icon: 'error'
                }, '', true);
              }
              else {
                // Record success and display message to user
                CRM.api3('StripePaymentintent', 'createorupdate', {
                  stripe_intent_id: CRM.vars[script.name].setupIntentID,
                  status: confirmCardSetupResult.setupIntent.status,
                  csrfToken: CRM.vars[script.name].csrfToken
                });

                CRM.payment.debugging(script.name, 'success - payment captured');
                CRM.payment.swalFire({
                  title: ts('Payment successful'),
                  icon: 'success'
                }, '', true);
              }
            });
        }
      }
      else {
        CRM.payment.debugging(script.name, 'No paymentIntentID or setupIntentID.');
        CRM.payment.swalClose();
      }
    },

    /**
     * Check and load Stripe scripts
     */
    checkAndLoad: function() {
      if (typeof CRM.vars[script.name] === 'undefined') {
        debugging('CRM.vars' + script.name + ' not defined!');
        return;
      }

      if (typeof Stripe === 'undefined') {
        if (script.scriptLoading) {
          return;
        }
        script.scriptLoading = true;
        CRM.payment.debugging(script.name, 'Stripe.js is not loaded!');

        $.ajax({
          url: 'https://js.stripe.com/v3',
          dataType: 'script',
          cache: true,
          timeout: 5000
        })
          .done(function(data) {
            script.scriptLoading = false;
            CRM.payment.debugging(script.name, 'Script loaded and executed.');
            if (script.stripe === null) {
              script.stripe = Stripe(CRM.vars[script.name].publishableKey);
            }
            script.handleCardConfirm();
          })
          .fail(function() {
            script.scriptLoading = false;
            CRM.payment.debugging(script.name, 'Failed to load Stripe.js');
          });
      }
    }

  };

  if (typeof CRM.payment === 'undefined') {
    CRM.payment = {};
  }

  CRM.payment.stripeConfirm = script;

  CRM.payment.debugging(script.name, 'civicrmStripeConfirm loaded');

  if (typeof CRM.vars[script.name] === 'undefined') {
    CRM.payment.debugging(script.name, 'CRM.vars' + script.name + ' not defined!');
    return;
  }
  // intentStatus is the same for paymentIntent/setupIntent
  switch (CRM.vars[script.name].intentStatus) {
    case 'succeeded':
    case 'canceled':
      CRM.payment.debugging(script.name, 'paymentIntent: ' + CRM.vars[script.name].intentStatus);
      return;
  }

  CRM.payment.swalFire({
    title: ts('Please wait...'),
    allowOutsideClick: false,
    willOpen: function() {
      Swal.showLoading(Swal.getConfirmButton());
    }
  }, '', false);


  document.addEventListener('DOMContentLoaded', function() {
    CRM.payment.debugging(script.name, 'DOMContentLoaded');
    script.checkAndLoad();
  });

}(CRM.$, CRM.ts('com.drastikbydesign.stripe')));

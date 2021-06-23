/**
 * This handles confirmation actions on the "Thankyou" pages for
 * contribution/event workflows.
 */
(function($, ts) {

  var confirm = {
    scriptName: 'stripeconfirm',
    stripe: null,
    stripeLoading: false,

    /**
     * Handle the response from the server for the payment/setupIntent
     * @param paymentIntentProcessResponse
     */
    handleIntentServerResponse: function(paymentIntentProcessResponse) {
      CRM.payment.debugging(confirm.scriptName, 'handleIntentServerResponse');
      if (paymentIntentProcessResponse.requires_action) {
        // Use Stripe.js to handle required card action
        if (CRM.vars.stripe.hasOwnProperty('paymentIntentID')) {
          confirm.handlePaymentIntentAction(paymentIntentProcessResponse);
        }
        else if (CRM.vars.stripe.hasOwnProperty('setupIntentID')) {
          confirm.handleCardConfirm();
        }
      }
      else if (paymentIntentProcessResponse.requires_payment_method) {
        CRM.payment.debugging(confirm.scriptName, 'Payment failed - requires_payment_method');
        confirm.swalFire({
          title: '',
          text: ts('The payment failed - please try a different payment method.'),
          icon: 'error'
        }, '', true);
      }
      else if (paymentIntentProcessResponse.succeeded) {
        // All good, nothing more to do
        CRM.payment.debugging(confirm.scriptName, 'success - payment captured');
        confirm.swalFire({
          title: ts('Payment successful'),
          icon: 'success'
        }, '', true);
      }
      else {
        CRM.payment.debugging(confirm.scriptName, 'Payment Failed - unknown error');
        confirm.swalFire({
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
      switch (CRM.vars.stripe.paymentIntentMethod) {
        case 'automatic':
          confirm.stripe.handleCardPayment(paymentIntentProcessResponse.paymentIntentClientSecret)
            .then(function(handleCardPaymentResult) {
              if (handleCardPaymentResult.error) {
                // Show error in payment form
                CRM.payment.debugging(confirm.scriptName, handleCardPaymentResult.error.message);
                confirm.swalFire({
                  title: '',
                  text: handleCardPaymentResult.error.message,
                  icon: 'error'
                }, '', true);
              }
              else {
                // The card action has been handled
                CRM.payment.debugging(confirm.scriptName, 'handleCardPayment success');
                confirm.handleCardConfirm();
              }
            });
          break;

        case 'manual':
          confirm.stripe.handleCardAction(paymentIntentProcessResponse.paymentIntentClientSecret)
            .then(function(handleCardActionResult) {
              if (handleCardActionResult.error) {
                // Show error in payment form
                CRM.payment.debugging(confirm.scriptName, handleCardActionResult.error.message);
                confirm.swalFire({
                  title: '',
                  text: handleCardActionResult.error.message,
                  icon: 'error'
                }, '', true);
              }
              else {
                // The card action has been handled
                CRM.payment.debugging(confirm.scriptName, 'handleCardAction success');
                confirm.handleCardConfirm();
              }
            });
          break;
      }
    },

    /**
     * Handle the card confirm (eg. 3dsecure) for paymentIntent / setupIntent
     */
    handleCardConfirm: function() {
      CRM.payment.debugging(confirm.scriptName, 'handle card confirm');
      if (CRM.vars.stripe.hasOwnProperty('paymentIntentID')) {
        // Send paymentMethod.id to server
        CRM.api3('StripePaymentintent', 'Process', {
          payment_intent_id: CRM.vars.stripe.paymentIntentID,
          capture: true,
          payment_processor_id: CRM.vars.stripe.id,
          description: document.title,
          csrfToken: CRM.vars.stripe.csrfToken
        })
          .done(function(paymentIntentProcessResponse) {
            confirm.swalClose();
            // Handle server response (see Step 3)
            CRM.payment.debugging(confirm.scriptName, 'StripePaymentintent.Process done');
            if (paymentIntentProcessResponse.is_error) {
              // Triggered for api3_create_error or Exception
              confirm.swalFire({
                title: '',
                text: paymentIntentProcessResponse.error_message,
                icon: 'error'
              }, '', true);
            }
            else {
              paymentIntentProcessResponse = paymentIntentProcessResponse.values;
              confirm.handleIntentServerResponse(paymentIntentProcessResponse);
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
            CRM.payment.debugging(confirm.scriptName, error);
            confirm.swalFire({
              title: '',
              text: error,
              icon: 'error'
            }, '', true);
          });
      }
      else if (CRM.vars.stripe.hasOwnProperty('setupIntentID')) {
        if (CRM.vars.stripe.setupIntentNextAction.type === 'use_stripe_sdk') {
          confirm.swalClose();
          confirm.stripe.confirmCardSetup(CRM.vars.stripe.setupIntentClientSecret)
            .then(function(confirmCardSetupResult) {
              // Handle confirmCardSetupResult.error or confirmCardSetupResult.setupIntent
              if (confirmCardSetupResult.error) {
                // Record error and display message to user
                CRM.api3('StripePaymentintent', 'createorupdate', {
                  stripe_intent_id: CRM.vars.stripe.setupIntentID,
                  status: 'error',
                  csrfToken: CRM.vars.stripe.csrfToken
                });

                CRM.payment.debugging(confirm.scriptName, confirmCardSetupResult.error.message);
                confirm.swalFire({
                  title: '',
                  text: confirmCardSetupResult.error.message,
                  icon: 'error'
                }, '', true);
              }
              else {
                // Record success and display message to user
                CRM.api3('StripePaymentintent', 'createorupdate', {
                  stripe_intent_id: CRM.vars.stripe.setupIntentID,
                  status: confirmCardSetupResult.setupIntent.status,
                  csrfToken: CRM.vars.stripe.csrfToken
                });

                CRM.payment.debugging(confirm.scriptName, 'success - payment captured');
                confirm.swalFire({
                  title: ts('Payment successful'),
                  icon: 'success'
                }, '', true);
              }
            });
        }
      }
      else {
        CRM.payment.debugging(confirm.scriptName, 'No paymentIntentID or setupIntentID.');
        confirm.swalClose();
      }
    },

    /**
     * Check and load Stripe scripts
     */
    checkAndLoad: function() {
      if (typeof Stripe === 'undefined') {
        if (confirm.stripeLoading) {
          return;
        }
        confirm.stripeLoading = true;
        CRM.payment.debugging(confirm.scriptName, 'Stripe.js is not loaded!');

        $.ajax({
          url: 'https://js.stripe.com/v3',
          dataType: 'script',
          cache: true,
          timeout: 5000
        })
          .done(function(data) {
            confirm.stripeLoading = false;
            CRM.payment.debugging(confirm.scriptName, 'Script loaded and executed.');
            if (confirm.stripe === null) {
              confirm.stripe = Stripe(CRM.vars.stripe.publishableKey);
            }
            confirm.handleCardConfirm();
          })
          .fail(function() {
            confirm.stripeLoading = false;
            CRM.payment.debugging(confirm.scriptName, 'Failed to load Stripe.js');
          });
      }
    },

    /**
     * Wrapper around Swal.fire()
     * @param {array} parameters
     * @param {string} scrollToElement
     * @param {boolean} fallBackToAlert
     */
    swalFire: function(parameters, scrollToElement, fallBackToAlert) {
      if (typeof Swal === 'function') {
        if (scrollToElement.length > 0) {
          parameters.onAfterClose = function() { window.scrollTo($(scrollToElement).position()); };
        }
        Swal.fire(parameters);
      }
      else if (fallBackToAlert) {
        window.alert(parameters.title + ' ' + parameters.text);
      }
    },

    /**
     * Wrapper around Swal.close()
     */
    swalClose: function() {
      if (typeof Swal === 'function') {
        Swal.close();
      }
    }

  };

  if (typeof CRM.payment === 'undefined') {
    CRM.payment = {};
  }

  CRM.payment.stripeConfirm = confirm;

  CRM.payment.debugging(confirm.scriptName, 'civicrmStripeConfirm loaded');

  if (typeof CRM.vars.stripe === 'undefined') {
    CRM.payment.debugging(confirm.scriptName, 'CRM.vars.stripe not defined! Not a Stripe processor?');
    return;
  }
  // intentStatus is the same for paymentIntent/setupIntent
  switch (CRM.vars.stripe.intentStatus) {
    case 'succeeded':
    case 'canceled':
      CRM.payment.debugging(confirm.scriptName, 'paymentIntent: ' + CRM.vars.stripe.intentStatus);
      return;
  }

  confirm.swalFire({
    title: ts('Please wait...'),
    allowOutsideClick: false,
    onBeforeOpen: function() {
      Swal.showLoading();
    }
  }, '', false);


  document.addEventListener('DOMContentLoaded', function() {
    CRM.payment.debugging(confirm.scriptName, 'DOMContentLoaded');
    confirm.checkAndLoad();
  });

}(CRM.$, CRM.ts('com.drastikbydesign.stripe')));

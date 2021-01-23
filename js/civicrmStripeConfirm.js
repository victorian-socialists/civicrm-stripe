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
     * @param result
     */
    handleIntentServerResponse: function(result) {
      CRM.payment.debugging(confirm.scriptName, 'handleServerResponse');
      if (result.error) {
        // Show error from server on payment form
        CRM.payment.debugging(confirm.scriptName, result.error.message);
        confirm.swalFire({
          title: '',
          text: result.error.message,
          icon: 'error'
        }, '', true);
      }
      else
        if (result.requires_action) {
          // Use Stripe.js to handle required card action
          if (CRM.vars.stripe.hasOwnProperty('paymentIntentID')) {
            confirm.handlePaymentIntentAction(result);
          }
          else if (CRM.vars.stripe.hasOwnProperty('setupIntentID')) {
            confirm.handleCardConfirm();
          }
        }
        else {
          // All good, nothing more to do
          CRM.payment.debugging(confirm.scriptName, 'success - payment captured');
          confirm.swalFire({
            title: ts('Payment successful'),
            icon: 'success'
          }, '', true);
        }
    },

    /**
     * Handle the next action for the paymentIntent
     * @param response
     */
    handlePaymentIntentAction: function(response) {
      switch (CRM.vars.stripe.paymentIntentMethod) {
        case 'automatic':
          confirm.stripe.handleCardPayment(response.paymentIntentClientSecret)
            .then(function(result) {
              if (result.error) {
                // Show error in payment form
                confirm.handleCardConfirm();
              }
              else {
                // The card action has been handled
                CRM.payment.debugging(confirm.scriptName, 'handleCardPayment success');
                confirm.handleCardConfirm();
              }
            });
          break;

        case 'manual':
          confirm.stripe.handleCardAction(response.paymentIntentClientSecret)
            .then(function(result) {
              if (result.error) {
                // Show error in payment form
                confirm.handleCardConfirm();
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
          id: CRM.vars.stripe.id,
          description: document.title,
          csrfToken: CRM.vars.stripe.csrfToken
        })
          .done(function(result) {
            confirm.swalClose();
            // Handle server response (see Step 3)
            confirm.handleIntentServerResponse(result);
          })
          .fail(function() {
            confirm.swalClose();
          });
      }
      else if (CRM.vars.stripe.hasOwnProperty('setupIntentID')) {
        if (CRM.vars.stripe.setupIntentNextAction.type === 'use_stripe_sdk') {
          confirm.swalClose();
          confirm.stripe.confirmCardSetup(CRM.vars.stripe.setupIntentClientSecret)
            .then(function(result) {
              if (result.error) {
                CRM.api3('StripePaymentintent', 'createorupdate', {
                  stripe_intent_id: CRM.vars.stripe.setupIntentID,
                  status: 'error',
                  csrfToken: CRM.vars.stripe.csrfToken
                });
              }
              else {
                CRM.api3('StripePaymentintent', 'createorupdate', {
                  stripe_intent_id: CRM.vars.stripe.setupIntentID,
                  status: result.setupIntent.status,
                  csrfToken: CRM.vars.stripe.csrfToken
                });
              }
              confirm.handleIntentServerResponse(result);
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
            debugging('Failed to load Stripe.js');
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

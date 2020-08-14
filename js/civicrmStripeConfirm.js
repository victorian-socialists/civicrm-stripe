/**
 * This handles confirmation actions on the "Thankyou" pages for
 * contribution/event workflows.
 */
(function($, ts) {

  var confirm = {
    scriptName: 'stripeconfirm',
    stripe: null,
    stripeLoading: false,

    handleServerResponse: function(result) {
      CRM.payment.debugging(confirm.scriptName, 'handleServerResponse');
      if (result.error) {
        // Show error from server on payment form
        CRM.payment.debugging(confirm.scriptName, result.error.message);
        confirm.swalFire({
          title: result.error.message,
          icon: 'error'
        }, '', true);
      }
      else
        if (result.requires_action) {
          // Use Stripe.js to handle required card action
          confirm.handleAction(result);
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

    handleAction: function(response) {
      switch (CRM.vars.stripe.paymentIntentMethod) {
        case 'automatic':
          confirm.stripe.handleCardPayment(response.payment_intent_client_secret)
            .then(function (result) {
              if (result.error) {
                // Show error in payment form
                confirm.handleCardConfirm();
              }
              else {
                // The card action has been handled
                CRM.payment.debugging(confirm.scriptName, 'card payment success');
                confirm.handleCardConfirm();
              }
            });
          break;

        case 'manual':
          confirm.stripe.handleCardAction(response.payment_intent_client_secret)
            .then(function (result) {
              if (result.error) {
                // Show error in payment form
                confirm.handleCardConfirm();
              }
              else {
                // The card action has been handled
                CRM.payment.debugging(confirm.scriptName, 'card action success');
                confirm.handleCardConfirm();
              }
            });
          break;
      }
    },

    handleCardConfirm: function() {
      CRM.payment.debugging(confirm.scriptName, 'handle card confirm');
      confirm.swalFire({
        title: ts('Please wait...'),
        allowOutsideClick: false,
        onBeforeOpen: function() {
          Swal.showLoading();
        }
      }, '', false);
      // Send paymentMethod.id to server
      var url = CRM.url('civicrm/stripe/confirm-payment');
      $.post(url, {
        payment_intent_id: CRM.vars.stripe.paymentIntentID,
        capture: true,
        id: CRM.vars.stripe.id,
        description: document.title,
        csrfToken: CRM.vars.stripe.csrfToken
      })
        .done(function (result) {
          confirm.swalClose();
          // Handle server response (see Step 3)
          confirm.handleServerResponse(result);
        })
        .fail(function() {
          confirm.swalClose();
        });
    },

    checkAndLoad: function () {
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
  switch (CRM.vars.stripe.paymentIntentStatus) {
    case 'succeeded':
    case 'cancelled':
      CRM.payment.debugging(confirm.scriptName, 'paymentIntent: ' + CRM.vars.stripe.paymentIntentStatus);
      return;
  }

  document.addEventListener('DOMContentLoaded', function() {
    CRM.payment.debugging(confirm.scriptName, 'DOMContentLoaded');

    confirm.checkAndLoad();
    confirm.handleCardConfirm();
  });

}(CRM.$, CRM.ts('com.drastikbydesign.stripe')));

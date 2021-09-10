/**
 * JS Integration between CiviCRM & Stripe.
 */
(function($, ts) {

  var script = {
    name: 'stripe',
    stripe: null,
    elements: {
      card: null,
      paymentRequestButton: null
    },
    scriptLoading: false,
    paymentProcessorID: null,

    paymentData: {
      clientSecret: null,
      paymentRequest: null
    },

    /**
     * Called when payment details have been entered and validated successfully
     *
     * @param type
     * @param object
     */
    successHandler: function(type, object) {
      script.debugging(type + ': success - submitting form');

      // Insert the token ID into the form so it gets submitted to the server
      var hiddenInput = document.createElement('input');
      hiddenInput.setAttribute('type', 'hidden');
      hiddenInput.setAttribute('name', type);
      hiddenInput.setAttribute('value', object.id);
      CRM.payment.form.appendChild(hiddenInput);

      CRM.payment.resetBillingFieldsRequiredForJQueryValidate();

      // Submit the form
      CRM.payment.form.submit();
    },

    /**
     * Get a list of jQuery elements for all possible Stripe elements that could be loaded
     *
     * @returns {{paymentrequest: (*|jQuery|HTMLElement), card: (*|jQuery|HTMLElement)}}
     */
    getJQueryPaymentElements: function() {
      return {
        card: $('div#card-element'),
        paymentrequest: $('div#paymentrequest-element')
      };
    },

    /**
     * Hide any visible payment elements
     */
    hideJQueryPaymentElements: function() {
      var jQueryPaymentElements = script.getJQueryPaymentElements();
      for (var elementName in jQueryPaymentElements) {
        var element = jQueryPaymentElements[elementName];
        element.hide();
      }
    },

    /**
     * Destroy any payment elements we have already created
     */
    destroyPaymentElements: function() {
      for (var elementName in script.elements) {
        var element = script.elements[elementName];
        if (element !== null) {
          script.debugging("destroying " + elementName + " element");
          element.destroy();
          script.elements[elementName] = null;
        }
      }
      $('.is_recur-section #stripe-recurring-start-date').remove();
    },

    /**
     * Check that payment elements are valid
     * @returns {boolean}
     */
    checkPaymentElementsAreValid: function() {
      var jQueryPaymentElements = script.getJQueryPaymentElements();
      for (var elementName in jQueryPaymentElements) {
        var element = jQueryPaymentElements[elementName];
        if ((element.length !== 0) && (element.children().length !== 0)) {
          script.debugging(elementName + ' element found');
          return true;
        }
      }
      script.debugging('no valid elements found');
      return false;
    },

    /**
     * Handle the "Card" submission to Stripe
     *
     * @param submitEvent
     */
    handleSubmitCard: function(submitEvent) {
      script.debugging('handle submit card');
      stripe.createPaymentMethod('card', script.elements.card)
        .then(function (createPaymentMethodResult) {
          if (createPaymentMethodResult.error) {
            // Show error in payment form
            CRM.payment.displayError(createPaymentMethodResult.error.message, true);
          }
          else {
            // For recur, additional participants we do NOT know the final amount so must create a paymentMethod and only create the paymentIntent
            //   once the form is finally submitted.
            // We should never get here with amount=0 as we should be doing a "nonStripeSubmit()" instead. This may become needed when we save cards
            var totalAmount = CRM.payment.getTotalAmount();
            if (totalAmount) {
              totalAmount = totalAmount.toFixed(2);
            }
            if (CRM.payment.getIsRecur() || CRM.payment.isEventAdditionalParticipants() || (totalAmount === null)) {
              CRM.api3('StripePaymentintent', 'createorupdate', {
                stripe_intent_id: createPaymentMethodResult.paymentMethod.id,
                description: document.title,
                payment_processor_id: CRM.vars[script.name].id,
                amount: totalAmount,
                currency: CRM.payment.getCurrency(CRM.vars[script.name].currency),
                status: 'payment_method',
                csrfToken: CRM.vars[script.name].csrfToken,
                extra_data: CRM.payment.getBillingEmail() + CRM.payment.getBillingName()
              })
                .done(function (result) {
                  // Handle server response (see Step 3)
                  CRM.payment.swalClose();
                  // Submit the form, if we need to do 3dsecure etc. we do it at the end (thankyou page) once subscription etc has been created
                  script.successHandler('paymentMethodID', {id: result.values[result.id].stripe_intent_id});
                })
                .fail(function () {
                  CRM.payment.swalClose();
                  CRM.payment.displayError(ts('Unknown error'), true);
                });
            }
            else {
              // Send paymentMethod.id to server
              CRM.payment.debugging(script.name, 'Waiting for pre-auth');
              CRM.payment.swalFire({
                title: ts('Please wait'),
                text: ts(' while we pre-authorize your card...'),
                allowOutsideClick: false,
                willOpen: function () {
                  Swal.showLoading(Swal.getConfirmButton());
                }
              }, '', false);
              CRM.api3('StripePaymentintent', 'Process', {
                payment_method_id: createPaymentMethodResult.paymentMethod.id,
                amount: CRM.payment.getTotalAmount().toFixed(2),
                currency: CRM.payment.getCurrency(CRM.vars[script.name].currency),
                payment_processor_id: CRM.vars[script.name].id,
                description: document.title,
                csrfToken: CRM.vars[script.name].csrfToken,
                extra_data: CRM.payment.getBillingEmail() + CRM.payment.getBillingName()
              })
                .done(function (paymentIntentProcessResponse) {
                  CRM.payment.swalClose();
                  CRM.payment.debugging(script.name, 'StripePaymentintent.Process done');
                  if (paymentIntentProcessResponse.is_error) {
                    // Triggered for api3_create_error or Exception
                    CRM.payment.displayError(paymentIntentProcessResponse.error_message, true);
                  }
                  else {
                    paymentIntentProcessResponse = paymentIntentProcessResponse.values;
                    if (paymentIntentProcessResponse.requires_action) {
                      // Use Stripe.js to handle a pending card action (eg. 3d-secure)
                      script.paymentData.clientSecret = paymentIntentProcessResponse.paymentIntentClientSecret;
                      stripe.handleCardAction(paymentData.clientSecret)
                        .then(function (cardActionResult) {
                          if (cardActionResult.error) {
                            // Show error in payment form
                            CRM.payment.displayError(cardActionResult.error.message, true);
                          }
                          else {
                            // The card action has been handled
                            // The PaymentIntent can be confirmed again on the server
                            script.successHandler('paymentIntentID', cardActionResult.paymentIntent);
                          }
                        });
                    }
                    else {
                      // All good, we can submit the form
                      script.successHandler('paymentIntentID', paymentIntentProcessResponse.paymentIntent);
                    }
                  }
                })
                .fail(function (failObject) {
                  script.stripePaymentIntentProcessFail(failObject);
                });
            }
          }
        });
    },

    /**
     * Handle the "PaymentRequest" submission to Stripe
     *
     * @param submitEvent
     */
    handleSubmitPaymentRequestButton: function(submitEvent) {
      script.debugging('handle submit paymentRequestButton');

      // Send paymentMethod.id to server
      script.debugging('Waiting for pre-auth');
      CRM.payment.swalFire({
        title: ts('Please wait'),
        text: ts(' preparing your payment...'),
        allowOutsideClick: false,
        willOpen: function () {
          Swal.showLoading(Swal.getConfirmButton());
        }
      }, '', false);
      CRM.api3('StripePaymentintent', 'Process', {
        amount: CRM.payment.getTotalAmount().toFixed(2),
        currency: CRM.payment.getCurrency(CRM.vars[script.name].currency),
        payment_processor_id: CRM.vars[script.name].id,
        description: document.title,
        csrfToken: CRM.vars[script.name].csrfToken
      })
        .done(function (paymentIntentProcessResponse) {
          CRM.payment.swalClose();
          script.debugging('StripePaymentintent.Process done');
          if (paymentIntentProcessResponse.is_error) {
            // Triggered for api3_create_error or Exception
            CRM.payment.displayError(paymentIntentProcessResponse.error_message, true);
          }
          else {
            paymentIntentProcessResponse = paymentIntentProcessResponse.values;
            // Trigger the paymentRequest dialog
            script.paymentData.clientSecret = paymentIntentProcessResponse.paymentIntentClientSecret;
            script.paymentData.paymentRequest.show();
            // From here the on 'paymentmethod' of the paymentRequest handles completion/failure
          }
        })
        .fail(function (failObject) {
          script.stripePaymentIntentProcessFail(failObject);
        });
    },

    /**
     * Display a helpful error message if call to StripePaymentintent.Process fails
     * @param {object} failObject
     * @returns {boolean}
     */
    stripePaymentIntentProcessFail: function(failObject) {
      var error = ts('Unknown error');
      if (failObject.hasOwnProperty('statusText') && (failObject.statusText !== 'OK')) {
        // A PHP exit can return 200 "OK" but we don't want to display "OK" as the error!
        if (failObject.statusText === 'parsererror') {
          error = ts('Configuration error - unable to process paymentIntent');
        }
        else {
          error = failObject.statusText;
        }
      }
      CRM.payment.displayError(error, true);
      return true;
    },

    /**
     * Payment processor is not Stripe - cleanup
     */
    notScriptProcessor: function() {
      script.debugging('New payment processor is not ' + script.name + ', clearing CRM.vars.' + script.name);
      script.destroyPaymentElements();
      delete (CRM.vars[script.name]);
      $(CRM.payment.getBillingSubmit()).show();
    },

    /**
     * Check environment and trigger loadBillingBlock()
     */
    checkAndLoad: function() {
      if (typeof CRM.vars[script.name] === 'undefined') {
        script.debugging('CRM.vars' + script.name + ' not defined!');
        return;
      }

      if (typeof Stripe === 'undefined') {
        if (script.scriptLoading) {
          return;
        }
        script.scriptLoading = true;
        script.debugging('Stripe.js is not loaded!');

        $.ajax({
          url: 'https://js.stripe.com/v3',
          dataType: 'script',
          cache: true,
          timeout: 5000,
          crossDomain: true
        })
          .done(function(data) {
            script.scriptLoading = false;
            script.debugging("Script loaded and executed.");
            script.loadBillingBlock();
          })
          .fail(function() {
            script.scriptLoading = false;
            script.debugging('Failed to load Stripe.js');
            script.triggerEventCrmBillingFormReloadFailed();
          });
      }
      else {
        script.loadBillingBlock();
      }
    },

    /**
     * This actually loads the billingBlock and the chosen Stripe element
     */
    loadBillingBlock: function() {
      script.debugging('loadBillingBlock');

      // When switching payment processors we need to make sure these are empty
      script.paymentData.clientSecret = null;
      script.paymentData.paymentRequest = null;

      var oldPaymentProcessorID = script.paymentProcessorID;
      script.paymentProcessorID = CRM.payment.getPaymentProcessorSelectorValue();
      script.debugging('payment processor old: ' + oldPaymentProcessorID + ' new: ' + script.paymentProcessorID + ' id: ' + CRM.vars[script.name].id);
      if ((script.paymentProcessorID !== null) && (script.paymentProcessorID !== parseInt(CRM.vars[script.name].id))) {
        script.debugging('not ' + script.name);
        return script.notScriptProcessor();
      }

      script.debugging('New ID: ' + CRM.vars[script.name].id + ' pubKey: ' + CRM.vars[script.name].publishableKey);

      stripe = Stripe(CRM.vars[script.name].publishableKey);

      script.debugging('locale: ' + CRM.vars[script.name].locale);
      var stripeElements = stripe.elements({locale: CRM.vars[script.name].locale});

      // By default we load paymentRequest button if we can, fallback to card element
      script.createElementPaymentRequest(stripeElements);
    },

    /**
     * This is called once Stripe elements have finished loading onto the form
     */
    doAfterStripeElementsHaveLoaded: function() {
      CRM.payment.setBillingFieldsRequiredForJQueryValidate();

      // If another submit button on the form is pressed (eg. apply discount)
      //  add a flag that we can set to stop payment submission
      CRM.payment.form.dataset.submitdontprocess = 'false';

      CRM.payment.addHandlerNonPaymentSubmitButtons();

      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].addEventListener('click', submitButtonClick);
      }

      function submitButtonClick(clickEvent) {
        // Take over the click function of the form.
        if (typeof CRM.vars[script.name] === 'undefined') {
          // Submit the form
          return CRM.payment.doStandardFormSubmit();
        }
        script.debugging('clearing submitdontprocess');
        CRM.payment.form.dataset.submitdontprocess = 'false';

        // Run through our own submit, that executes Stripe submission if
        // appropriate for this submit.
        return script.submit(clickEvent);
      }

      // Remove the onclick attribute added by CiviCRM.
      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].removeAttribute('onclick');
      }

      CRM.payment.addSupportForCiviDiscount();

      // For CiviCRM Webforms.
      if (CRM.payment.getIsDrupalWebform()) {
        // We need the action field for back/submit to work and redirect properly after submission

        $('[type=submit]').click(function() {
          CRM.payment.addDrupalWebformActionElement(this.value);
        });
        // If enter pressed, use our submit function
        CRM.payment.form.addEventListener('keydown', function (keydownEvent) {
          if (keydownEvent.code === 'Enter') {
            CRM.payment.addDrupalWebformActionElement(this.value);
            script.submit(keydownEvent);
          }
        });

        $('#billingcheckbox:input').hide();
        $('label[for="billingcheckbox"]').hide();
      }

      if (script.checkPaymentElementsAreValid()) {
        CRM.payment.triggerEvent('crmBillingFormReloadComplete');
        CRM.payment.triggerEvent('crmStripeBillingFormReloadComplete');
      }
      else {
        script.debugging('Failed to load payment elements');
        script.triggerEventCrmBillingFormReloadFailed();
      }
    },

    submit: function(submitEvent) {
      submitEvent.preventDefault();
      script.debugging('submit handler');

      if (CRM.payment.form.dataset.submitted === 'true') {
        return;
      }
      CRM.payment.form.dataset.submitted = 'true';

      if (!CRM.payment.validateCiviDiscount()) {
        return false;
      }

      if (!CRM.payment.validateForm()) {
        return false;
      }

      var cardError = CRM.$('#card-errors').text();
      if (CRM.$('#card-element.StripeElement--empty').length && (CRM.payment.getTotalAmount() !== 0.0)) {
        script.debugging('card details not entered!');
        if (!cardError) {
          cardError = ts('Please enter your card details');
        }
        CRM.payment.swalFire({
          icon: 'warning',
          text: '',
          title: cardError
        }, '#card-element', true);
        CRM.payment.triggerEvent('crmBillingFormNotValid');
        CRM.payment.form.dataset.submitted = 'false';
        return false;
      }

      if (CRM.$('#card-element.StripeElement--invalid').length) {
        if (!cardError) {
          cardError = ts('Please check your card details!');
        }
        script.debugging('card details not valid!');
        CRM.payment.swalFire({
          icon: 'error',
          text: '',
          title: cardError
        }, '#card-element', true);
        CRM.payment.triggerEvent('crmBillingFormNotValid');
        CRM.payment.form.dataset.submitted = 'false';
        return false;
      }

      if (!CRM.payment.validateReCaptcha()) {
        return false;
      }

      if (typeof CRM.vars[script.name] === 'undefined') {
        script.debugging('Submitting - not a ' + script.name + ' processor');
        return true;
      }

      var scriptProcessorId = parseInt(CRM.vars[script.name].id);
      var chosenProcessorId = null;

      // Handle multiple payment options and Stripe not being chosen.
      if (CRM.payment.getIsDrupalWebform()) {
        // this element may or may not exist on the webform, but we are dealing with a single (stripe) processor enabled.
        if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
          chosenProcessorId = scriptProcessorId;
        }
        else {
          chosenProcessorId = parseInt(CRM.payment.form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').value);
        }
      }
      else {
        // Most forms have payment_processor-section but event registration has credit_card_info-section
        if ((CRM.payment.form.querySelector(".crm-section.payment_processor-section") !== null) ||
          (CRM.payment.form.querySelector(".crm-section.credit_card_info-section") !== null)) {
          scriptProcessorId = CRM.vars[script.name].id;
          if (CRM.payment.form.querySelector('input[name="payment_processor_id"]:checked') !== null) {
            chosenProcessorId = parseInt(CRM.payment.form.querySelector('input[name="payment_processor_id"]:checked').value);
          }
        }
      }

      // If any of these are true, we are not using the stripe processor:
      // - Is the selected processor ID pay later (0)
      // - Is the Stripe processor ID defined?
      // - Is selected processor ID and stripe ID undefined? If we only have stripe ID, then there is only one (stripe) processor on the page
      if ((chosenProcessorId === 0) || (scriptProcessorId === null) ||
        ((chosenProcessorId === null) && (scriptProcessorId === null))) {
        script.debugging('Not a ' + script.name + ' transaction, or pay-later');
        return CRM.payment.doStandardFormSubmit();
      }
      else {
        script.debugging(script.name + ' is the selected payprocessor');
      }

      // Don't handle submits generated by non-stripe processors
      if (typeof CRM.vars[script.name].publishableKey === 'undefined') {
        script.debugging('submit missing stripe-pub-key element or value');
        return true;
      }
      // Don't handle submits generated by the CiviDiscount button.
      if (CRM.payment.form.dataset.submitdontprocess === 'true') {
        script.debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      if (CRM.payment.getIsDrupalWebform()) {
        // If we have selected Stripe but amount is 0 we don't submit via Stripe
        if ($('#billing-payment-block').is(':hidden')) {
          script.debugging('no payment processor on webform');
          return true;
        }

        // If we have more than one processor (user-select) then we have a set of radio buttons:
        var $processorFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
        if ($processorFields.length) {
          if ($processorFields.filter(':checked')
            .val() === '0' || $processorFields.filter(':checked').val() === 0) {
            script.debugging('no payment processor selected');
            return true;
          }
        }
      }

      var totalAmount = CRM.payment.getTotalAmount();
      if (totalAmount === 0.0) {
        script.debugging("Total amount is 0");
        return CRM.payment.doStandardFormSubmit();
      }

      // Disable the submit button to prevent repeated clicks
      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].setAttribute('disabled', true);
      }

      // When we click the stripe element we already have the elementType.
      // But clicking an alternative submit button we have to work it out.
      var elementType = 'card';
      if (submitEvent.hasOwnProperty(elementType)) {
        elementType = submitEvent.elementType;
      }
      else
        if (script.paymentData.paymentRequest !== null) {
          elementType = 'paymentRequestButton';
        }
      // Create a token when the form is submitted.
      switch (elementType) {
        case 'card':
          script.handleSubmitCard(submitEvent);
          break;

        case 'paymentRequestButton':
          script.handleSubmitPaymentRequestButton(submitEvent);
          break;
      }

      if ($('#stripe-recurring-start-date').is(':hidden')) {
        $('#stripe-recurring-start-date').remove();
      }

      return true;
    },

    createElementCard: function(stripeElements) {
      script.debugging('try to create card element');
      var style = {
        base: {
          fontSize: '1.1em', fontWeight: 'lighter'
        }
      };

      var elementsCreateParams = {style: style, value: {}};

      var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars[script.name].billingAddressID);
      if (postCodeElement) {
        var postCode = document.getElementById('billing_postal_code-' + CRM.vars[script.name].billingAddressID).value;
        script.debugging('existing postcode: ' + postCode);
        elementsCreateParams.value.postalCode = postCode;
      }

      // Cleanup any classes leftover from previous switching payment processors
      script.getJQueryPaymentElements().card.removeClass();
      // Create an instance of the card Element.
      script.elements.card = stripeElements.create('card', elementsCreateParams);
      script.elements.card.mount('#card-element');
      script.debugging("created new card element", script.elements.card);

      if (postCodeElement) {
        // Hide the CiviCRM postcode field so it will still be submitted but will contain the value set in the stripe card-element.
        if (document.getElementById('billing_postal_code-5').value) {
          document.getElementById('billing_postal_code-5')
            .setAttribute('disabled', true);
        }
        else {
          document.getElementsByClassName('billing_postal_code-' + CRM.vars[script.name].billingAddressID + '-section')[0].setAttribute('hidden', true);
        }
      }

      // All containers start as display: none and are enabled on demand
      document.getElementById('card-element').style.display = 'block';

      script.elements.card.addEventListener('change', function (event) {
        script.cardElementChanged(event);
      });

      script.doAfterStripeElementsHaveLoaded();
    },

    createElementPaymentRequest: function(stripeElements) {
      script.debugging('try to create paymentRequest element');
      if (CRM.payment.supportsRecur() || CRM.payment.isEventAdditionalParticipants()) {
        script.debugging('paymentRequest element is not supported on this form');
        script.createElementCard(stripeElements);
        return;
      }
      var paymentRequest = null;
      try {
        paymentRequest = stripe.paymentRequest({
          country: CRM.vars[script.name].country,
          currency: CRM.vars[script.name].currency.toLowerCase(),
          total: {
            label: document.title,
            amount: 0
          },
          requestPayerName: true,
          requestPayerEmail: true
        });
      }
      catch (err) {
        if (err.name === 'IntegrationError') {
          script.debugging('Cannot enable paymentRequestButton: ' + err.message);
          script.createElementCard(stripeElements);
          return;
        }
      }

      paymentRequest.canMakePayment()
        .catch(function (result) {
          script.createElementCard(stripeElements);
          return;
        })
        .then(function (result) {
          if (!result) {
            script.debugging('No available paymentMethods for paymentRequest');
            script.createElementCard(stripeElements);
            return;
          }
          script.debugging('paymentRequest paymentMethods: ' + JSON.stringify(result));
          // Mount paymentRequestButtonElement to the DOM
          script.paymentData.paymentRequest = paymentRequest;
          script.elements.paymentRequestButton = stripeElements.create('paymentRequestButton', {
            paymentRequest: paymentRequest,
            style: {
              paymentRequestButton: {
                // One of 'default', 'book', 'buy', or 'donate'
                type: 'default',
                // One of 'dark', 'light', or 'light-outline'
                theme: 'dark',
                // Defaults to '40px'. The width is always '100%'.
                height: '64px'
              }
            }
          });

          script.elements.paymentRequestButton.on('click', function (clickEvent) {
            script.debugging('PaymentRequest clicked');
            paymentRequest.update({
              total: {
                label: document.title,
                amount: CRM.payment.getTotalAmount() * 100
              }
            });
            script.debugging('clearing submitdontprocess');
            CRM.payment.form.dataset.submitdontprocess = 'false';

            CRM.payment.form.dataset.submitted = 'false';

            // Run through our own submit, that executes Stripe submission if
            // appropriate for this submit.
            script.submit(clickEvent);
          });

          paymentRequest.on('paymentmethod', function (paymentRequestEvent) {
            try {
              // Confirm the PaymentIntent without handling potential next actions (yet).
              stripe.confirmCardPayment(
                script.paymentData.clientSecret,
                {payment_method: paymentRequestEvent.paymentMethod.id},
                {handleActions: false}
              ).then(function (confirmResult) {
                if (confirmResult.error) {
                  // Report to the browser that the payment failed, prompting it to
                  // re-show the payment interface, or show an error message and close
                  // the payment interface.
                  paymentRequestEvent.complete('fail');
                }
                else {
                  // Report to the browser that the confirmation was successful, prompting
                  // it to close the browser payment method collection interface.
                  paymentRequestEvent.complete('success');
                  // Check if the PaymentIntent requires any actions and if so let Stripe.js
                  // handle the flow.
                  if (confirmResult.paymentIntent.status === "requires_action") {
                    // Let Stripe.js handle the rest of the payment flow.
                    stripe.confirmCardPayment(script.paymentData.clientSecret)
                      .then(function (result) {
                        if (result.error) {
                          // The payment failed -- ask your customer for a new payment method.
                          script.debugging('confirmCardPayment failed');
                          CRM.payment.displayError(ts('The payment failed - please try a different payment method.'), true);
                        }
                        else {
                          // The payment has succeeded.
                          script.successHandler('paymentIntentID', result.paymentIntent);
                        }
                      });
                  }
                  else {
                    // The payment has succeeded.
                    script.successHandler('paymentIntentID', confirmResult.paymentIntent);
                  }
                }
              });
            }
            catch (err) {
              if (err.name === 'IntegrationError') {
                script.debugging(err.message);
              }
              paymentRequestEvent.complete('fail');
            }
          });

          if (result) {
            script.elements.paymentRequestButton.mount('#paymentrequest-element');
            document.getElementById('paymentrequest-element').style.display = 'block';
            $(CRM.payment.submitButtons).hide();
          }
          else {
            document.getElementById('paymentrequest-element').style.display = 'none';
          }

          script.doAfterStripeElementsHaveLoaded();
        });
    },

    cardElementChanged: function(event) {
      if (event.empty) {
        $('div#card-errors').hide();
      }
      else
        if (event.error) {
          CRM.payment.displayError(event.error.message, false);
        }
        else
          if (event.complete) {
            $('div#card-errors').hide();
            var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars[script.name].billingAddressID);
            if (postCodeElement) {
              postCodeElement.value = event.value.postalCode;
            }
          }
    },

    /**
     * Output debug information
     * @param {string} errorCode
     */
    debugging: function(errorCode) {
      CRM.payment.debugging(script.name, errorCode);
    },

    /**
     * Trigger the crmBillingFormReloadFailed event and notify the user
     */
    triggerEventCrmBillingFormReloadFailed: function() {
      CRM.payment.triggerEvent('crmBillingFormReloadFailed');
      script.hideJQueryPaymentElements();
      CRM.payment.displayError(ts('Could not load payment element - Is there a problem with your network connection?'), true);
    }
  };

  // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
  window.onbeforeunload = null;

  if (CRM.payment.hasOwnProperty(script.name)) {
    return;
  }

  // Currently this just flags that we've already loaded
  var crmPaymentObject = {};
  crmPaymentObject[script.name] = true;
  $.extend(CRM.payment, crmPaymentObject);

  // Re-prep form when we've loaded a new payproc via ajax or via webform
  $(document).ajaxComplete(function (event, xhr, settings) {
    if (CRM.payment.isAJAXPaymentForm(settings.url)) {
      CRM.payment.debugging(script.name, 'triggered via ajax');
      load();
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    CRM.payment.debugging(script.name, 'DOMContentLoaded');
    load();
  });

  /**
   * Called on every load of this script (whether billingblock loaded via AJAX or DOMContentLoaded)
   */
  function load() {
    if (window.civicrmStripeHandleReload) {
      // Call existing instance of this, instead of making new one.
      CRM.payment.debugging(script.name, "calling existing HandleReload.");
      window.civicrmStripeHandleReload();
    }
  }

  /**
   * This function boots the UI.
   */
  window.civicrmStripeHandleReload = function() {
    CRM.payment.scriptName = script.name;
    CRM.payment.debugging(script.name, 'HandleReload');

    // Get the form containing payment details
    CRM.payment.form = CRM.payment.getBillingForm();
    if (typeof CRM.payment.form.length === 'undefined' || CRM.payment.form.length === 0) {
      CRM.payment.debugging(script.name, 'No billing form!');
      return;
    }

    // If we are reloading start with the form submit buttons visible
    // They may get hidden later depending on the element type.
    $(CRM.payment.getBillingSubmit()).show();

    // Load Stripe onto the form.
    var cardElement = document.getElementById('card-element');
    if ((typeof cardElement !== 'undefined') && (cardElement)) {
      if (!cardElement.children.length) {
        CRM.payment.debugging(script.name, 'checkAndLoad from document.ready');
        script.checkAndLoad();
      }
      else {
        CRM.payment.debugging(script.name, 'already loaded');
      }
    }
    else {
      script.notScriptProcessor();
      CRM.payment.triggerEvent('crmBillingFormReloadComplete');
    }
  };

}(CRM.$, CRM.ts('com.drastikbydesign.stripe')));

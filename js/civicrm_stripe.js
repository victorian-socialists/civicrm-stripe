/**
 * JS Integration between CiviCRM & Stripe.
 */
(function($, ts) {
  // On initial load...
  var scriptName = 'civicrmStripe';
  var stripe = null;
  var elements = {
    card: null,
    paymentRequestButton: null
  };
  var form;
  var submitButtons;
  var stripeLoading = false;
  var paymentProcessorID = null;

  var paymentData = {
    clientSecret: null,
    paymentRequest: null
  };

  // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
  window.onbeforeunload = null;

  if (CRM.payment.hasOwnProperty('Stripe')) {
    return;
  }
  // todo: turn this script into a proper object on CRM.payment
  var civicrmStripe = {Stripe: true};
  $.extend(CRM.payment, civicrmStripe);

  // Re-prep form when we've loaded a new payproc via ajax or via webform
  $(document).ajaxComplete(function(event, xhr, settings) {
    // /civicrm/payment/form? occurs when a payproc is selected on page
    // /civicrm/contact/view/participant occurs when payproc is first loaded on event credit card payment
    // On wordpress these are urlencoded
    if (CRM.payment.isAJAXPaymentForm(settings.url)) {
      debugging('triggered via ajax');
      load();
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    debugging('DOMContentLoaded');
    load();
  });

  function load() {
    if (window.civicrmStripeHandleReload) {
      // Call existing instance of this, instead of making new one.
      debugging("calling existing civicrmStripeHandleReload.");
      window.civicrmStripeHandleReload();
    }
  }

  /**
   * This function boots the UI.
   */
  window.civicrmStripeHandleReload = function() {
    debugging('civicrmStripeHandleReload');

    // Get the form containing payment details
    form = CRM.payment.getBillingForm();
    if (typeof form.length === 'undefined' || form.length === 0) {
      debugging('No billing form!');
      return;
    }

    var submitButtons = CRM.payment.getBillingSubmit();

    // Load Stripe onto the form.
    var cardElement = document.getElementById('card-element');
    if ((typeof cardElement !== 'undefined') && (cardElement)) {
      if (!cardElement.children.length) {
        debugging('checkAndLoad from document.ready');
        checkAndLoad();
      }
      else {
        debugging('already loaded');
      }
    }
    else {
      notStripe();
      triggerEvent('crmBillingFormReloadComplete');
    }
  };

  function successHandler(type, object) {
    debugging(type + ': success - submitting form');

    // Insert the token ID into the form so it gets submitted to the server
    var hiddenInput = document.createElement('input');
    hiddenInput.setAttribute('type', 'hidden');
    hiddenInput.setAttribute('name', type);
    hiddenInput.setAttribute('value', object.id);
    form.appendChild(hiddenInput);

    resetBillingFieldsRequiredForJQueryValidate();

    // Submit the form
    form.submit();
  }

  function nonStripeSubmit() {
    // Disable the submit button to prevent repeated clicks
    for (i = 0; i < submitButtons.length; ++i) {
      submitButtons[i].setAttribute('disabled', true);
    }
    resetBillingFieldsRequiredForJQueryValidate();
    return form.submit();
  }

  /**
   * Display a stripe element error
   *
   * @param {string} errorMessage - the stripe error object
   * @param {boolean} notify - whether to popup a notification as well as display on the form.
   */
  function displayError(errorMessage, notify) {
    // Display error.message in your UI.
    debugging('error: ' + errorMessage);
    // Inform the user if there was an error
    var errorElement = document.getElementById('card-errors');
    errorElement.style.display = 'block';
    errorElement.textContent = errorMessage;
    form.dataset.submitted = 'false';
    if (typeof submitButtons !== 'undefined') {
      for (i = 0; i < submitButtons.length; ++i) {
        submitButtons[i].removeAttribute('disabled');
      }
    }
    triggerEvent('crmBillingFormNotValid');
    if (notify) {
      swalClose();
      swalFire({
        icon: 'error',
        text: errorMessage,
        title: ''
      }, '#card-element', true);
    }
  }

  function getJQueryPaymentElements() {
    return {
      card: $('div#card-element'),
      paymentrequest: $('div#paymentrequest-element')
    };
  }

  /**
   * Hide any visible payment elements
   */
  function hideJQueryPaymentElements() {
    var jQueryPaymentElements = getJQueryPaymentElements();
    for (var elementName in jQueryPaymentElements) {
      var element = jQueryPaymentElements[elementName];
      element.hide();
    }
  }

  /**
   * Destroy any payment elements we have already created
   */
  function destroyPaymentElements() {
    for (var elementName in elements) {
      var element = elements[elementName];
      if (element !== null) {
        debugging("destroying " + elementName + " element");
        element.destroy();
        elements[elementName] = null;
      }
    }
  }

  /**
   * Check that payment elements are valid
   * @returns {boolean}
   */
  function checkPaymentElementsAreValid() {
    var jQueryPaymentElements = getJQueryPaymentElements();
    for (var elementName in jQueryPaymentElements) {
      var element = jQueryPaymentElements[elementName];
      if ((element.length !== 0) && (element.children().length !== 0)) {
        debugging(elementName + ' element found');
        return true;
      }
    }
    debugging('no valid elements found');
    return false;
  }

  function handleSubmitCard(submitEvent) {
    debugging('handle submit card');
    stripe.createPaymentMethod('card', elements.card).then(function (createPaymentMethodResult) {
      if (createPaymentMethodResult.error) {
        // Show error in payment form
        displayError(createPaymentMethodResult.error.message, true);
      }
      else {
        // For recur, additional participants we do NOT know the final amount so must create a paymentMethod and only create the paymentIntent
        //   once the form is finally submitted.
        // We should never get here with amount=0 as we should be doing a "nonStripeSubmit()" instead. This may become needed when we save cards
        if (CRM.payment.getIsRecur() || CRM.payment.isEventAdditionalParticipants() || (CRM.payment.getTotalAmount() === 0.0)) {
          CRM.api3('StripePaymentintent', 'createorupdate', {
            stripe_intent_id: createPaymentMethodResult.paymentMethod.id,
            description: document.title,
            payment_processor_id: CRM.vars.stripe.id,
            amount: CRM.payment.getTotalAmount().toFixed(2),
            currency: CRM.payment.getCurrency(CRM.vars.stripe.currency),
            status: 'payment_method',
            csrfToken: CRM.vars.stripe.csrfToken,
            extra_data: CRM.payment.getBillingEmail() + CRM.payment.getBillingName()
          })
            .done(function (result) {
              // Handle server response (see Step 3)
              swalClose();
              // Submit the form, if we need to do 3dsecure etc. we do it at the end (thankyou page) once subscription etc has been created
              successHandler('paymentMethodID', {id: result.values[result.id].stripe_intent_id});
            })
            .fail(function() {
              swalClose();
              displayError('Unknown error', true);
            });
        }
        else {
          // Send paymentMethod.id to server
          debugging('Waiting for pre-auth');
          swalFire({
            title: ts('Please wait'),
            text: ts(' while we pre-authorize your card...'),
            allowOutsideClick: false,
            onBeforeOpen: function() {
              Swal.showLoading();
            }
          }, '', false);
          CRM.api3('StripePaymentintent', 'Process', {
            payment_method_id: createPaymentMethodResult.paymentMethod.id,
            amount: CRM.payment.getTotalAmount().toFixed(2),
            currency: CRM.payment.getCurrency(CRM.vars.stripe.currency),
            id: CRM.vars.stripe.id,
            description: document.title,
            csrfToken: CRM.vars.stripe.csrfToken,
            extra_data: CRM.payment.getBillingEmail() + CRM.payment.getBillingName()
          })
            .done(function (paymentIntentProcessResponse) {
              swalClose();
              debugging('StripePaymentintent.Process done');
              if (paymentIntentProcessResponse.is_error) {
                // Triggered for api3_create_error or Exception
                displayError(paymentIntentProcessResponse.error_message, true);
              }
              else {
                paymentIntentProcessResponse = paymentIntentProcessResponse.values;
                if (paymentIntentProcessResponse.requires_action) {
                  // Use Stripe.js to handle a pending card action (eg. 3d-secure)
                  paymentData.clientSecret = paymentIntentProcessResponse.paymentIntentClientSecret;
                  stripe.handleCardAction(paymentData.clientSecret)
                    .then(function(cardActionResult) {
                      if (cardActionResult.error) {
                        // Show error in payment form
                        displayError(cardActionResult.error.message, true);
                      } else {
                        // The card action has been handled
                        // The PaymentIntent can be confirmed again on the server
                        successHandler('paymentIntentID', cardActionResult.paymentIntent);
                      }
                    });
                }
                else {
                  // All good, we can submit the form
                  successHandler('paymentIntentID', paymentIntentProcessResponse.paymentIntent);
                }
              }
            })
            .fail(function(object) {
              // Triggered when http code !== 200 (eg. 400 Bad request)
              var error = 'Unknown error';
              if (object.hasOwnProperty('statusText')) {
                error = object.statusText;
              }
              displayError(error, true);
            });
        }
      }
    });
  }

  function handleSubmitPaymentRequestButton(submitEvent) {
    debugging('handle submit paymentRequestButton');

    // Send paymentMethod.id to server
    debugging('Waiting for pre-auth');
    swalFire({
      title: ts('Please wait'),
      text: ts(' preparing your payment...'),
      allowOutsideClick: false,
      onBeforeOpen: function() {
        Swal.showLoading();
      }
    }, '', false);
    CRM.api3('StripePaymentintent', 'Process', {
      amount: CRM.payment.getTotalAmount().toFixed(2),
      currency: CRM.payment.getCurrency(CRM.vars.stripe.currency),
      id: CRM.vars.stripe.id,
      description: document.title,
      csrfToken: CRM.vars.stripe.csrfToken
    })
      .done(function(paymentIntentProcessResponse) {
        swalClose();
        debugging('StripePaymentintent.Process done');
        if (paymentIntentProcessResponse.is_error) {
          // Triggered for api3_create_error or Exception
          displayError(paymentIntentProcessResponse.error_message, true);
        }
        else {
          paymentIntentProcessResponse = paymentIntentProcessResponse.values;
          // Trigger the paymentRequest dialog
          paymentData.clientSecret = paymentIntentProcessResponse.paymentIntentClientSecret;
          paymentData.paymentRequest.show();
          // From here the on 'paymentmethod' of the paymentRequest handles completion/failure
        }
      })
      .fail(function(object) {
        // Triggered when http code !== 200 (eg. 400 Bad request)
        var error = 'Unknown error';
        if (object.hasOwnProperty('statusText')) {
          error = object.statusText;
        }
        displayError(error, true);
      });
  }

  /**
   * Payment processor is not Stripe - cleanup
   */
  function notStripe() {
    debugging("New payment processor is not Stripe, clearing CRM.vars.stripe");
    destroyPaymentElements();
    $('.is_recur-section #stripe-recurring-start-date').remove();
    delete(CRM.vars.stripe);
  }

  function checkAndLoad() {
    if (typeof CRM.vars.stripe === 'undefined') {
      debugging('CRM.vars.stripe not defined! Not a Stripe processor?');
      return;
    }

    if (typeof Stripe === 'undefined') {
      if (stripeLoading) {
        return;
      }
      stripeLoading = true;
      debugging('Stripe.js is not loaded!');

      $.ajax({
        url: 'https://js.stripe.com/v3',
        dataType: 'script',
        cache: true,
        timeout: 5000,
        crossDomain: true
      })
        .done(function(data) {
          stripeLoading = false;
          debugging("Script loaded and executed.");
          loadStripeBillingBlock();
          triggerEvent('crmBillingFormReloadComplete');
          triggerEvent('crmStripeBillingFormReloadComplete');
        })
        .fail(function() {
          stripeLoading = false;
          debugging('Failed to load Stripe.js');
          triggerEventCrmBillingFormReloadFailed();
        });
    }
    else {
      loadStripeBillingBlock();
      if (checkPaymentElementsAreValid()) {
        triggerEvent('crmStripeBillingFormReloadComplete');
      }
      else {
        debugging('Failed to load payment elements');
        triggerEventCrmBillingFormReloadFailed();
      }
    }
  }

  function loadStripeBillingBlock() {
    debugging('loadStripeBillingBlock');

    // When switching payment processors we need to make sure these are empty
    paymentData.clientSecret = null;
    paymentData.paymentRequest = null;

    var oldPaymentProcessorID = paymentProcessorID;
    paymentProcessorID = CRM.payment.getPaymentProcessorSelectorValue();
    debugging('payment processor old: ' + oldPaymentProcessorID + ' new: ' + paymentProcessorID + ' id: ' + CRM.vars.stripe.id);
    if ((paymentProcessorID !== null) && (paymentProcessorID !== parseInt(CRM.vars.stripe.id))) {
      debugging('not stripe');
      return notStripe();
    }

    debugging('New Stripe ID: ' + CRM.vars.stripe.id + ' pubKey: ' + CRM.vars.stripe.publishableKey);
    stripe = Stripe(CRM.vars.stripe.publishableKey);

    debugging('locale: ' + CRM.vars.stripe.locale);
    var stripeElements = stripe.elements({locale: CRM.vars.stripe.locale});

    // By default we load paymentRequest button if we can, fallback to card element
    if (createElementPaymentRequest(stripeElements) === false) {
      createElementCard(stripeElements);
    }

    setBillingFieldsRequiredForJQueryValidate();
    submitButtons = CRM.payment.getBillingSubmit();

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    form.dataset.submitdontprocess = 'false';

    // Find submit buttons which should not submit payment
    var nonPaymentSubmitButtons = form.querySelectorAll('[type="submit"][formnovalidate="1"], ' +
      '[type="submit"][formnovalidate="formnovalidate"], ' +
      '[type="submit"].cancel, ' +
      '[type="submit"].webform-previous'), i;
    for (i = 0; i < nonPaymentSubmitButtons.length; ++i) {
      nonPaymentSubmitButtons[i].addEventListener('click', submitDontProcess(nonPaymentSubmitButtons[i]));
    }

    function submitDontProcess(element) {
      debugging('adding submitdontprocess: ' + element.id);
      form.dataset.submitdontprocess = 'true';
    }

    for (i = 0; i < submitButtons.length; ++i) {
      submitButtons[i].addEventListener('click', submitButtonClick);
    }

    function submitButtonClick(clickEvent) {
      // Take over the click function of the form.
      if (typeof CRM.vars.stripe === 'undefined') {
        // Submit the form
        return nonStripeSubmit();
      }
      debugging('clearing submitdontprocess');
      form.dataset.submitdontprocess = 'false';

      // Run through our own submit, that executes Stripe submission if
      // appropriate for this submit.
      return submit(clickEvent);
    }

    // Remove the onclick attribute added by CiviCRM.
    for (i = 0; i < submitButtons.length; ++i) {
      submitButtons[i].removeAttribute('onclick');
    }

    addSupportForCiviDiscount();

    // For CiviCRM Webforms.
    if (CRM.payment.getIsDrupalWebform()) {
      // We need the action field for back/submit to work and redirect properly after submission

      $('[type=submit]').click(function() {
        addDrupalWebformActionElement(this.value);
      });
      // If enter pressed, use our submit function
      form.addEventListener('keydown', function (keydownEvent) {
        if (keydownEvent.code === 'Enter') {
          addDrupalWebformActionElement(this.value);
          submit(keydownEvent);
        }
      });

      $('#billingcheckbox:input').hide();
      $('label[for="billingcheckbox"]').hide();
    }
  }

  function submit(submitEvent) {
    submitEvent.preventDefault();
    debugging('submit handler');

    if (form.dataset.submitted === 'true') {
      return;
    }
    form.dataset.submitted = 'true';

    if (($(form).valid() === false) || $(form).data('crmBillingFormValid') === false) {
      debugging('Form not valid');
      $('div#card-errors').hide();
      swalFire({
        icon: 'error',
        text: ts('Please check and fill in all required fields!'),
        title: ''
      }, '#crm-container', true);
      triggerEvent('crmBillingFormNotValid');
      form.dataset.submitted = 'false';
      return false;
    }

    var cardError = CRM.$('#card-errors').text();
    if (CRM.$('#card-element.StripeElement--empty').length && (CRM.payment.getTotalAmount() !== 0.0)) {
      debugging('card details not entered!');
      if (!cardError) {
        cardError = ts('Please enter your card details');
      }
      swalFire({
        icon: 'warning',
        text: '',
        title: cardError
      }, '#card-element', true);
      triggerEvent('crmBillingFormNotValid');
      form.dataset.submitted = 'false';
      return false;
    }

    if (CRM.$('#card-element.StripeElement--invalid').length) {
      if (!cardError) {
        cardError = ts('Please check your card details!');
      }
      debugging('card details not valid!');
      swalFire({
        icon: 'error',
        text: '',
        title: cardError
      }, '#card-element', true);
      triggerEvent('crmBillingFormNotValid');
      form.dataset.submitted = 'false';
      return false;
    }

    if (!validateReCaptcha()) {
      return false;
    }

    if (typeof CRM.vars.stripe === 'undefined') {
      debugging('Submitting - not a stripe processor');
      return true;
    }

    var stripeProcessorId = parseInt(CRM.vars.stripe.id);
    var chosenProcessorId = null;

    // Handle multiple payment options and Stripe not being chosen.
    // @fixme this needs refactoring as some is not relevant anymore (with stripe 6.0)
    if (CRM.payment.getIsDrupalWebform()) {
      // this element may or may not exist on the webform, but we are dealing with a single (stripe) processor enabled.
      if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
        chosenProcessorId = stripeProcessorId;
      } else {
        chosenProcessorId = parseInt(form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').value);
      }
    }
    else {
      // Most forms have payment_processor-section but event registration has credit_card_info-section
      if ((form.querySelector(".crm-section.payment_processor-section") !== null) ||
        (form.querySelector(".crm-section.credit_card_info-section") !== null)) {
        stripeProcessorId = CRM.vars.stripe.id;
        if (form.querySelector('input[name="payment_processor_id"]:checked') !== null) {
          chosenProcessorId = parseInt(form.querySelector('input[name="payment_processor_id"]:checked').value);
        }
      }
    }

    // If any of these are true, we are not using the stripe processor:
    // - Is the selected processor ID pay later (0)
    // - Is the Stripe processor ID defined?
    // - Is selected processor ID and stripe ID undefined? If we only have stripe ID, then there is only one (stripe) processor on the page
    if ((chosenProcessorId === 0) || (stripeProcessorId === null) ||
      ((chosenProcessorId === null) && (stripeProcessorId === null))) {
      debugging('Not a Stripe transaction, or pay-later');
      return nonStripeSubmit();
    }
    else {
      debugging('Stripe is the selected payprocessor');
    }

    // Don't handle submits generated by non-stripe processors
    if (typeof CRM.vars.stripe.publishableKey === 'undefined') {
      debugging('submit missing stripe-pub-key element or value');
      return true;
    }
    // Don't handle submits generated by the CiviDiscount button.
    if (form.dataset.submitdontprocess === 'true') {
      debugging('non-payment submit detected - not submitting payment');
      return true;
    }

    if (CRM.payment.getIsDrupalWebform()) {
      // If we have selected Stripe but amount is 0 we don't submit via Stripe
      if ($('#billing-payment-block').is(':hidden')) {
        debugging('no payment processor on webform');
        return true;
      }

      // If we have more than one processor (user-select) then we have a set of radio buttons:
      var $processorFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
      if ($processorFields.length) {
        if ($processorFields.filter(':checked').val() === '0' || $processorFields.filter(':checked').val() === 0) {
          debugging('no payment processor selected');
          return true;
        }
      }
    }

    var totalAmount = CRM.payment.getTotalAmount();
    if (totalAmount === 0.0) {
      debugging("Total amount is 0");
      return nonStripeSubmit();
    }

    // Disable the submit button to prevent repeated clicks
    for (i = 0; i < submitButtons.length; ++i) {
      submitButtons[i].setAttribute('disabled', true);
    }

    // When we click the stripe element we already have the elementType.
    // But clicking an alternative submit button we have to work it out.
    var elementType = 'card';
    if (submitEvent.hasOwnProperty(elementType)) {
      elementType = submitEvent.elementType;
    }
    else if (paymentData.paymentRequest !== null) {
      elementType = 'paymentRequestButton';
    }
    // Create a token when the form is submitted.
    switch(elementType) {
      case 'card':
        handleSubmitCard(submitEvent);
        break;

      case 'paymentRequestButton':
        handleSubmitPaymentRequestButton(submitEvent);
        break;
    }

    if ($('#stripe-recurring-start-date').is(':hidden')) {
      $('#stripe-recurring-start-date').remove();
    }

    return true;
  }

  function createElementCard(stripeElements) {
    debugging('try to create card element');
    var style = {
      base: {
        fontSize: '1.1em', fontWeight: 'lighter'
      }
    };

    var elementsCreateParams = {style: style, value: {}};

    var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars.stripe.billingAddressID);
    if (postCodeElement) {
      var postCode = document.getElementById('billing_postal_code-' + CRM.vars.stripe.billingAddressID).value;
      debugging('existing postcode: ' + postCode);
      elementsCreateParams.value.postalCode = postCode;
    }

    // Cleanup any classes leftover from previous switching payment processors
    getJQueryPaymentElements().card.removeClass();
    // Create an instance of the card Element.
    elements.card = stripeElements.create('card', elementsCreateParams);
    elements.card.mount('#card-element');
    debugging("created new card element", elements.card);

    if (postCodeElement) {
      // Hide the CiviCRM postcode field so it will still be submitted but will contain the value set in the stripe card-element.
      if (document.getElementById('billing_postal_code-5').value) {
        document.getElementById('billing_postal_code-5')
          .setAttribute('disabled', true);
      }
      else {
        document.getElementsByClassName('billing_postal_code-' + CRM.vars.stripe.billingAddressID + '-section')[0].setAttribute('hidden', true);
      }
    }

    // All containers start as display: none and are enabled on demand
    document.getElementById('card-element').style.display = 'block';

    elements.card.addEventListener('change', function (event) {
      cardElementChanged(event);
    });
  }

  function createElementPaymentRequest(stripeElements) {
    debugging('try to create paymentRequest element');
    if (CRM.payment.supportsRecur() || CRM.payment.isEventAdditionalParticipants()) {
      debugging('paymentRequest element is not supported on this form');
      return false;
    }
    var paymentRequest = null;
    try {
      paymentRequest = stripe.paymentRequest({
        country: CRM.vars.stripe.country,
        currency: CRM.vars.stripe.currency.toLowerCase(),
        total: {
          label: document.title,
          amount: 0
        },
        requestPayerName: true,
        requestPayerEmail: true
      });
    } catch(err) {
      if (err.name === 'IntegrationError') {
        debugging('Cannot enable paymentRequestButton: ' + err.message);
        return false;
      }
    }

    // Check the availability of the Payment Request API first.
    paymentRequest.canMakePayment()
      .catch(function(result) {
        return false;
      })
      .then(function(result) {
        paymentData.paymentRequest = paymentRequest;
        elements.paymentRequestButton = stripeElements.create('paymentRequestButton', {
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

        elements.paymentRequestButton.on('click', function(clickEvent) {
          debugging('PaymentRequest clicked');
          paymentRequest.update({
            total: {
              label: document.title,
              amount: CRM.payment.getTotalAmount() * 100
            }
          });
          debugging('clearing submitdontprocess');
          form.dataset.submitdontprocess = 'false';

          form.dataset.submitted = 'false';

          // Run through our own submit, that executes Stripe submission if
          // appropriate for this submit.
          submit(clickEvent);
        });

        paymentRequest.on('paymentmethod', function(paymentRequestEvent) {
          try {
            // Confirm the PaymentIntent without handling potential next actions (yet).
            stripe.confirmCardPayment(
              paymentData.clientSecret,
              {payment_method: paymentRequestEvent.paymentMethod.id},
              {handleActions: false}
            ).then(function(confirmResult) {
              if (confirmResult.error) {
                // Report to the browser that the payment failed, prompting it to
                // re-show the payment interface, or show an error message and close
                // the payment interface.
                paymentRequestEvent.complete('fail');
              } else {
                // Report to the browser that the confirmation was successful, prompting
                // it to close the browser payment method collection interface.
                paymentRequestEvent.complete('success');
                // Check if the PaymentIntent requires any actions and if so let Stripe.js
                // handle the flow.
                if (confirmResult.paymentIntent.status === "requires_action") {
                  // Let Stripe.js handle the rest of the payment flow.
                  stripe.confirmCardPayment(paymentData.clientSecret).then(function(result) {
                    if (result.error) {
                      // The payment failed -- ask your customer for a new payment method.
                      debugging('confirmCardPayment failed');
                      displayError('The payment failed - please try a different payment method.', true);
                    } else {
                      // The payment has succeeded.
                      successHandler('paymentIntentID', result.paymentIntent);
                    }
                  });
                } else {
                  // The payment has succeeded.
                  successHandler('paymentIntentID', confirmResult.paymentIntent);
                }
              }
            });
          } catch(err) {
            if (err.name === 'IntegrationError') {
              debugging(err.message);
            }
            paymentRequestEvent.complete('fail');
          }
        });

        if (result) {
          elements.paymentRequestButton.mount('#paymentrequest-element');
          document.getElementById('paymentrequest-element').style.display = 'block';
        } else {
          document.getElementById('paymentrequest-element').style.display = 'none';
        }
      });
  }

  /**
   * Validate a reCaptcha if it exists on the form.
   * Ideally we would use grecaptcha.getResponse() but the reCaptcha is already render()ed by CiviCRM
   *   so we don't have clientID and can't be sure we are checking the reCaptcha that is on our form.
   *
   * @returns {boolean}
   */
  function validateReCaptcha() {
    if (typeof grecaptcha === 'undefined') {
      // No reCaptcha library loaded
      debugging('reCaptcha library not loaded');
      return true;
    }
    if ($(form).find('[name=g-recaptcha-response]').length === 0) {
      // no reCaptcha on form - we check this first because there could be reCaptcha on another form on the same page that we don't want to validate
      debugging('no reCaptcha on form');
      return true;
    }
    if ($(form).find('[name=g-recaptcha-response]').val().length > 0) {
      // We can't use grecaptcha.getResponse because there might be multiple reCaptchas on the page and we might not be the first one.
      debugging('recaptcha is valid');
      return true;
    }
    debugging('recaptcha active and not valid');
    $('div#card-errors').hide();
    swalFire({
      icon: 'warning',
      text: '',
      title: ts('Please complete the reCaptcha')
    }, '.recaptcha-section', true);
    triggerEvent('crmBillingFormNotValid');
    form.dataset.submitted = 'false';
    return false;
  }

  function cardElementChanged(event) {
    if (event.empty) {
      $('div#card-errors').hide();
    }
    else if (event.error) {
      displayError(event.error.message, false);
    }
    else if (event.complete) {
      $('div#card-errors').hide();
      var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars.stripe.billingAddressID);
      if (postCodeElement) {
        postCodeElement.value = event.value.postalCode;
      }
    }
  }

  function addSupportForCiviDiscount() {
    // Add a keypress handler to set flag if enter is pressed
    cividiscountElements = form.querySelectorAll('input#discountcode');
    var cividiscountHandleKeydown = function(event) {
        if (event.code === 'Enter') {
          event.preventDefault();
          debugging('adding submitdontprocess');
          form.dataset.submitdontprocess = 'true';
        }
    };

    for (i = 0; i < cividiscountElements.length; ++i) {
      cividiscountElements[i].addEventListener('keydown', cividiscountHandleKeydown);
    }
  }

  function resetBillingFieldsRequiredForJQueryValidate() {
    // The "name" parameter on a group of checkboxes where at least one must be checked must be the same or validation will require all of them!
    // Reset the name of the checkboxes before submitting otherwise CiviCRM will not get the checkbox values.
    $('div#priceset input[type="checkbox"], fieldset.crm-profile input[type="checkbox"], #on-behalf-block input[type="checkbox"]').each(function() {
      if ($(this).attr('data-name') !== undefined) {
        $(this).attr('name', $(this).attr('data-name'));
      }
    });
  }

  function setBillingFieldsRequiredForJQueryValidate() {
    // CustomField checkboxes in profiles do not get the "required" class.
    // This should be fixed in CRM_Core_BAO_CustomField::addQuickFormElement but requires that the "name" is fixed as well.
    $('div.label span.crm-marker').each(function() {
      $(this).closest('div').next('div').find('input[type="checkbox"]').addClass('required');
    });

    // The "name" parameter on a set of checkboxes where at least one must be checked must be the same or validation will require all of them!
    // Checkboxes for custom fields are added as quickform "advcheckbox" which seems to require a unique name for each checkbox. But that breaks
    //   jQuery validation because each checkbox in a required group must have the same name.
    // We store the original name and then change it. resetBillingFieldsRequiredForJQueryValidate() must be called before submit.
    // Most checkboxes get names like: "custom_63[1]" but "onbehalf" checkboxes get "onbehalf[custom_63][1]". We change them to "custom_63" and "onbehalf[custom_63]".
    $('div#priceset input[type="checkbox"], fieldset.crm-profile input[type="checkbox"], #on-behalf-block input[type="checkbox"]').each(function() {
      var name = $(this).attr('name');
      $(this).attr('data-name', name);
      $(this).attr('name', name.replace('[' + name.split('[').pop(), ''));
    });

    // @todo remove once min version is 5.28 (https://github.com/civicrm/civicrm-core/pull/17672)
    var is_for_organization = $('#is_for_organization');
    if (is_for_organization.length) {
      setValidateOnBehalfOfBlock();
      is_for_organization.on('change', function() {
        setValidateOnBehalfOfBlock();
      });
    }
    function setValidateOnBehalfOfBlock() {
      if (is_for_organization.is(':checked')) {
        $('#onBehalfOfOrg select.crm-select2').removeClass('crm-no-validate');
      }
      else {
        $('#onBehalfOfOrg select.crm-select2').addClass('crm-no-validate');
      }
    }

    var validator = $(form).validate();
    validator.settings.errorClass = 'crm-inline-error alert-danger';
    validator.settings.ignore = '.select2-offscreen, [readonly], :hidden:not(.crm-select2), .crm-no-validate';
    validator.settings.ignoreTitle = true;

    // Default email validator accepts test@example but on test@example.org is valid (https://jqueryvalidation.org/jQuery.validator.methods/)
    $.validator.methods.email = function(value, element) {
      // Regex from https://html.spec.whatwg.org/multipage/input.html#valid-e-mail-address
      return this.optional(element) || /^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/.test(value);
    };
  }

  function addDrupalWebformActionElement(submitAction) {
    var hiddenInput = null;
    if (document.getElementById('action') !== null) {
      hiddenInput = document.getElementById('action');
    }
    else {
      hiddenInput = document.createElement('input');
    }
    hiddenInput.setAttribute('type', 'hidden');
    hiddenInput.setAttribute('name', 'op');
    hiddenInput.setAttribute('id', 'action');
    hiddenInput.setAttribute('value', submitAction);
    form.appendChild(hiddenInput);
  }

  /**
   * Output debug information
   * @param {string} errorCode
   */
  function debugging(errorCode) {
    CRM.payment.debugging(scriptName, errorCode);
  }

  /**
   * Trigger a jQuery event
   * @param {string} event
   */
  function triggerEvent(event) {
    debugging('Firing Event: ' + event);
    $(form).trigger(event);
  }

  /**
   * Trigger the crmBillingFormReloadFailed event and notify the user
   */
  function triggerEventCrmBillingFormReloadFailed() {
    triggerEvent('crmBillingFormReloadFailed');
    hideJQueryPaymentElements();
    displayError(ts('Could not load payment element - Is there a problem with your network connection?'), true);
  }

  /**
   * Wrapper around Swal.fire()
   * @param {array} parameters
   * @param {string} scrollToElement
   * @param {boolean} fallBackToAlert
   */
  function swalFire(parameters, scrollToElement, fallBackToAlert) {
    if (typeof Swal === 'function') {
      if (scrollToElement.length > 0) {
        parameters.onAfterClose = function() { window.scrollTo($(scrollToElement).position()); };
      }
      Swal.fire(parameters);
    }
    else if (fallBackToAlert) {
      window.alert(parameters.title + ' ' + parameters.text);
    }
  }

  /**
   * Wrapper around Swal.close()
   */
  function swalClose() {
    if (typeof Swal === 'function') {
      Swal.close();
    }
  }

}(CRM.$, CRM.ts('com.drastikbydesign.stripe')));

/**
 * @file
 * JS Integration between CiviCRM & Stripe.
 */
CRM.$(function($) {

  var stripe;
  var card;
  var form;
  var submitButton;
  var stripeLoading = false;

  function paymentIntentSuccessHandler(paymentIntent) {
    debugging('paymentIntent confirmation success');

    // Insert the token ID into the form so it gets submitted to the server
    var hiddenInput = document.createElement('input');
    hiddenInput.setAttribute('type', 'hidden');
    hiddenInput.setAttribute('name', 'paymentIntentID');
    hiddenInput.setAttribute('value', paymentIntent.id);
    form.appendChild(hiddenInput);

    // Submit the form
    form.submit();
  }

  function displayError(result) {
    // Display error.message in your UI.
    debugging('error: ' + result.error.message);
    // Inform the user if there was an error
    var errorElement = document.getElementById('card-errors');
    errorElement.style.display = 'block';
    errorElement.textContent = result.error.message;
    document.querySelector('#billing-payment-block').scrollIntoView();
    window.scrollBy(0, -50);
    submitButton.removeAttribute('disabled');
  }

  function handleCardPayment() {
    debugging('handle card payment');
    stripe.createPaymentMethod('card', card).then(function (result) {
      if (result.error) {
        // Show error in payment form
        displayError(result);
      }
      else {
        // Send paymentMethod.id to server
        var url = CRM.url('civicrm/stripe/confirm-payment');
        $.post(url, {
          payment_method_id: result.paymentMethod.id,
          amount: getTotalAmount(),
          currency: CRM.vars.stripe.currency,
          id: CRM.vars.stripe.id,
        }).then(function (result) {
          // Handle server response (see Step 3)
          handleServerResponse(result);
        });
      }
    });
  }

  function handleServerResponse(result) {
    debugging('handleServerResponse');
    if (result.error) {
      // Show error from server on payment form
      displayError(result);
    } else if (result.requires_action) {
      // Use Stripe.js to handle required card action
      handleAction(result);
    } else {
      // All good, we can submit the form
      paymentIntentSuccessHandler(result.paymentIntent);
    }
  }

  function handleAction(response) {
    stripe.handleCardAction(
      response.payment_intent_client_secret
    ).then(function(result) {
      if (result.error) {
        // Show error in payment form
        displayError(result);
      } else {
        // The card action has been handled
        // The PaymentIntent can be confirmed again on the server
        paymentIntentSuccessHandler(result.paymentIntent);
      }
    });
  }

  // Prepare the form.
  var onclickAction = null;
  $(document).ready(function() {
    // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
    window.onbeforeunload = null;

    // Load Stripe onto the form.
    checkAndLoad();

    // Store and remove any onclick Action currently assigned to the form.
    // We will re-add it if the transaction goes through.
    //onclickAction = submitButton.getAttribute('onclick');
    //submitButton.removeAttribute('onclick');

    // Quickform doesn't add hidden elements via standard method. On a form where payment processor may
    //  be loaded via initial form load AND ajax (eg. backend live contribution page with payproc dropdown)
    //  the processor metadata elements will appear twice (once on initial load, once via AJAX).  The ones loaded
    //  via initial load will not be removed when AJAX loaded ones are added and the wrong stripe-pub-key etc will
    //  be submitted.  This removes all elements with the class "payproc-metadata" from the form each time the
    //  dropdown is changed.
    $('select#payment_processor_id').on('change', function() {
      $('input.payproc-metadata').remove();
    });
  });

  // Re-prep form when we've loaded a new payproc
  $( document ).ajaxComplete(function( event, xhr, settings ) {
    // /civicrm/payment/form? occurs when a payproc is selected on page
    // /civicrm/contact/view/participant occurs when payproc is first loaded on event credit card payment
    // On wordpress these are urlencoded
    if ((settings.url.match("civicrm(\/|%2F)payment(\/|%2F)form") != null)
      || (settings.url.match("civicrm(\/|\%2F)contact(\/|\%2F)view(\/|\%2F)participant") != null)) {
      // See if there is a payment processor selector on this form
      // (e.g. an offline credit card contribution page).
      if ($('#payment_processor_id').length > 0) {
        // There is. Check if the selected payment processor is different
        // from the one we think we should be using.
        var ppid = $('#payment_processor_id').val();
        if (ppid != CRM.vars.stripe.id) {
          debugging('payment processor changed to id: ' + ppid);
          // It is! See if the new payment processor is also a Stripe
          // Payment processor. First, find out what the stripe
          // payment processor type id is (we don't want to update
          // the stripe pub key with a value from another payment processor).
          CRM.api3('PaymentProcessorType', 'getvalue', {
            "sequential": 1,
            "return": "id",
            "name": "Stripe"
          }).done(function(result) {
            // Now, see if the new payment processor id is a stripe
            // payment processor.
            var stripe_pp_type_id = result['result'];
            CRM.api3('PaymentProcessor', 'getvalue', {
              "sequential": 1,
              "return": "password",
              "id": ppid,
              "payment_processor_type_id": stripe_pp_type_id,
            }).done(function(result) {
              var pub_key = result['result'];
              if (pub_key) {
                // It is a stripe payment processor, so update the key.
                debugging("Setting new stripe key to: " + pub_key);
                CRM.vars.stripe.publishableKey = pub_key;
              }
              else {
                debugging("New payment processor is not Stripe, setting stripe-pub-key to null");
                CRM.vars.stripe.publishableKey = null;
              }
              // Now reload the billing block.
              checkAndLoad();
            });
          });
        }
      }
      checkAndLoad();
    }
  });

  function checkAndLoad() {
    if (typeof CRM.vars.stripe === 'undefined') {
      debugging('CRM.vars.stripe not defined!');
      return;
    }

    if (typeof Stripe === 'undefined') {
      if (stripeLoading) {
        return;
      }
      stripeLoading = true;
      debugging('Stripe.js is not loaded!');

      $.getScript("https://js.stripe.com/v3", function () {
        debugging("Script loaded and executed.");
        stripeLoading = false;
        loadStripeBillingBlock();
      });
    }
    else {
      loadStripeBillingBlock();
    }
  }

  function loadStripeBillingBlock() {
    stripe = Stripe(CRM.vars.stripe.publishableKey);
    var elements = stripe.elements();

    var style = {
      base: {
        fontSize: '20px',
      },
    };

    // Create an instance of the card Element.
    card = elements.create('card', {style: style});
    card.mount('#card-element');

    // Hide the CiviCRM postcode field so it will still be submitted but will contain the value set in the stripe card-element.
    document.getElementsByClassName('billing_postal_code-' + CRM.vars.stripe.billingAddressID + '-section')[0].setAttribute('hidden', true);
    card.addEventListener('change', function(event) {
      updateFormElementsFromCreditCardDetails(event);
    });

      // Get the form containing payment details
    form = getBillingForm();
    if (typeof form.length === 'undefined' || form.length === 0) {
      debugging('No billing form!');
      return;
    }
    submitButton = getBillingSubmit();

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    form.dataset.submitdontprocess = false;

    // Find submit buttons which should not submit payment
    var nonPaymentSubmitButtons = form.querySelectorAll('[type="submit"][formnovalidate="1"], ' +
      '[type="submit"][formnovalidate="formnovalidate"], ' +
      '[type="submit"].cancel, ' +
      '[type="submit"].webform-previous'), i;
    for (i = 0; i < nonPaymentSubmitButtons.length; ++i) {
      nonPaymentSubmitButtons[i].addEventListener('click', function () {
        debugging('adding submitdontprocess');
        form.dataset.submitdontprocess = true;
      });
    }

    submitButton.addEventListener('click', function(event) {
      // Take over the click function of the form.
      debugging('clearing submitdontprocess');
      form.dataset.submitdontprocess = false;

      // Run through our own submit, that executes Stripe submission if
      // appropriate for this submit.
      return submit(event);
    });

    // Remove the onclick attribute added by CiviCRM.
    submitButton.removeAttribute('onclick');

    addSupportForCiviDiscount();

    // For CiviCRM Webforms.
    if (getIsDrupalWebform()) {
      // We need the action field for back/submit to work and redirect properly after submission

      $('[type=submit]').click(function() {
        addDrupalWebformActionElement(this.value);
      });
      // If enter pressed, use our submit function
      form.addEventListener('keydown', function (e) {
        if (e.keyCode === 13) {
          addDrupalWebformActionElement(this.value);
          submit(event);
        }
      });

      $('#billingcheckbox:input').hide();
      $('label[for="billingcheckbox"]').hide();
    }

    function submit(event) {
      event.preventDefault();
      debugging('submit handler');

      if (form.dataset.submitted === true) {
        debugging('form already submitted');
        return false;
      }

      var stripeProcessorId;
      var chosenProcessorId;

      if (typeof CRM.vars.stripe !== 'undefined') {
        stripeProcessorId = CRM.vars.stripe.id;
      }

      // Handle multiple payment options and Stripe not being chosen.
      // @fixme this needs refactoring as some is not relevant anymore (with stripe 6.0)
      if (getIsDrupalWebform()) {
        stripeProcessorId = CRM.vars.stripe.id;
        // this element may or may not exist on the webform, but we are dealing with a single (stripe) processor enabled.
        if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
          chosenProcessorId = stripeProcessorId;
        } else {
          chosenProcessorId = form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').val();
        }
      }
      else {
        // Most forms have payment_processor-section but event registration has credit_card_info-section
        if ((form.querySelector(".crm-section.payment_processor-section") !== null)
          || (form.querySelector(".crm-section.credit_card_info-section") !== null)) {
          stripeProcessorId = CRM.vars.stripe.id;
          if (form.querySelector('input[name="payment_processor_id"]:checked') !== null) {
            chosenProcessorId = form.querySelector('input[name="payment_processor_id"]:checked').value;
          }
        }
      }

      // If any of these are true, we are not using the stripe processor:
      // - Is the selected processor ID pay later (0)
      // - Is the Stripe processor ID defined?
      // - Is selected processor ID and stripe ID undefined? If we only have stripe ID, then there is only one (stripe) processor on the page
      if ((chosenProcessorId === 0)
        || (stripeProcessorId == null)
        || ((chosenProcessorId == null) && (stripeProcessorId == null))) {
        debugging('Not a Stripe transaction, or pay-later');
        return true;
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
      if (form.dataset.submitdontprocess === true) {
        debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      if (getIsDrupalWebform()) {
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

      var totalFee = getTotalAmount();
      if (totalFee == '0') {
        debugging("Total amount is 0");
        return true;
      }

      // Lock to prevent multiple submissions
      if (form.dataset.submitted === true) {
        // Previously submitted - don't submit again
        alert('Form already submitted. Please wait.');
        return false;
      } else {
        // Mark it so that the next submit can be ignored
        form.dataset.submitted = true;
      }

      // Disable the submit button to prevent repeated clicks
      submitButton.setAttribute('disabled', true);

      // Create a token when the form is submitted.
      handleCardPayment();

      return true;
    }
  }

  function getIsDrupalWebform() {
    // form class for drupal webform: webform-client-form (drupal 7); webform-submission-form (drupal 8)
    if (form !== null) {
      return form.classList.contains('webform-client-form') || form.classList.contains('webform-submission-form');
    }
    return false;
  }

  function getBillingForm() {
    // If we have a stripe billing form on the page
    var billingFormID = $('div#card-element').closest('form').prop('id');
    if (!billingFormID.length) {
      // If we have multiple payment processors to select and stripe is not currently loaded
      billingFormID = $('input[name=hidden_processor]').closest('form').prop('id');
    }
    // We have to use document.getElementById here so we have the right elementtype for appendChild()
    return document.getElementById(billingFormID);
  }

  function getBillingSubmit() {
    var submit = null;
    if (getIsDrupalWebform()) {
      submit = form.querySelector('[type="submit"].webform-submit');
      if (!submit) {
        // drupal 8 webform
        submit = form.querySelector('[type="submit"].webform-button--submit');
      }
    }
    else {
      submit = form.querySelector('[type="submit"].validate');
    }
    return submit;
  }

  function getTotalAmount() {
    var totalFee = null;
    if (typeof calculateTotalFee == 'function') {
      // This is ONLY triggered in the following circumstances on a CiviCRM contribution page:
      // - With a priceset that allows a 0 amount to be selected.
      // - When Stripe is the ONLY payment processor configured on the page.
      totalFee = calculateTotalFee();
    }
    else if (getIsDrupalWebform()) {
      // This is how webform civicrm calculates the amount in webform_civicrm_payment.js
      $('.line-item:visible', '#wf-crm-billing-items').each(function() {
        totalFee += parseFloat($(this).data('amount'));
      });
    }
    return totalFee;
  }

  function updateFormElementsFromCreditCardDetails(event) {
    if (!event.complete) {
      return;
    }
    document.getElementById('billing_postal_code-' + CRM.vars.stripe.billingAddressID).value = event.value.postalCode;
  }

  function addSupportForCiviDiscount() {
    // Add a keypress handler to set flag if enter is pressed
    cividiscountElements = form.querySelectorAll('input#discountcode');
    for (i = 0; i < cividiscountElements.length; ++i) {
      cividiscountElements[i].addEventListener('keydown', function (e) {
        if (e.keyCode === 13) {
          e.preventDefault();
          debugging('adding submitdontprocess');
          form.dataset.submitdontprocess = true;
        }
      });
    }
  }

  function debugging (errorCode) {
    // Uncomment the following to debug unexpected returns.
    if ((typeof(CRM.vars.stripe) === 'undefined') || (Boolean(CRM.vars.stripe.jsDebug) === true)) {
      console.log(new Date().toISOString() + ' civicrm_stripe.js: ' + errorCode);
    }
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

});

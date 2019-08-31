/**
 * @file
 * JS Integration between CiviCRM & Stripe.
 */
CRM.$(function($) {

  var stripe;
  var card;
  var form;
  var submitButton;

  function stripeTokenHandler(token) {
    debugging('stripeTokenHandler');
    // Insert the token ID into the form so it gets submitted to the server
    var hiddenInput = document.createElement('input');
    hiddenInput.setAttribute('type', 'hidden');
    hiddenInput.setAttribute('name', 'stripeToken');
    hiddenInput.setAttribute('value', token.id);
    form.appendChild(hiddenInput);

    // Submit the form
    form.submit();
  }

  function createToken() {
    debugging('createToken');
    stripe.createToken(card).then(function(result) {
      if (result.error) {
        debugging('createToken failed');
        // Inform the user if there was an error
        var errorElement = document.getElementById('card-errors');
        errorElement.style.display = 'block';
        errorElement.textContent = result.error.message;
        submitButton.removeAttribute('disabled');
        document.querySelector('#billing-payment-block').scrollIntoView();
        window.scrollBy(0, -50);
      } else {
        // Send the token to your server
        stripeTokenHandler(result.token);
      }
    });
  }

  // Prepare the form.
  var onclickAction = null;
  $(document).ready(function() {
    // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
    window.onbeforeunload = null;
    // Load Stripe onto the form.
    loadStripeBillingBlock();

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
        if (ppid != $('#stripe-id').val()) {
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
                $('#stripe-pub-key').val(pub_key);
              }
              else {
                debugging("New payment processor is not Stripe, setting stripe-pub-key to null");
                $('#stripe-pub-key').val(null);
              }
              // Now reload the billing block.
              loadStripeBillingBlock();
            });
          });
        }
      }
      loadStripeBillingBlock();
    }
  });

  function loadStripeBillingBlock() {
    // Setup Stripe.Js
    var $stripePubKey = $('#stripe-pub-key');

    if (!$stripePubKey.length) {
      return;
    }

    stripe = Stripe($('#stripe-pub-key').val());
    var elements = stripe.elements();

    var style = {
      base: {
        fontSize: '20px',
      },
    };

    // Create an instance of the card Element.
    card = elements.create('card', {style: style});
    card.mount('#card-element');

    // Get the form containing payment details
    form = getBillingForm();
    if (!form.length) {
      debugging('No billing form!');
      return;
    }
    submitButton = getBillingSubmit();

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    form.dataset.submitdontprocess = false;

    var button = document.createElement("input");
    button.type = "submit";
    button.value = "im a button";
    button.classList.add('cancel');
    form.appendChild(button);

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

    // Add a keypress handler to set flag if enter is pressed
    //form.querySelector('input#discountcode').keypress( function(e) {
//      if (e.which === 13) {
  //      form.dataset.submitdontprocess = true;
    //  }
    //});

    // For CiviCRM Webforms.
    if (getIsDrupalWebform()) {
      // We need the action field for back/submit to work and redirect properly after submission
      if (!($('#action').length)) {
        form.append($('<input type="hidden" name="op" id="action" />'));
      }
      var $actions = form.querySelector('[type=submit]');
      $('[type=submit]').click(function() {
        $('#action').val(this.value);
      });
      // If enter pressed, use our submit function
      form.keypress(function(event) {
        if (event.which === 13) {
          $('#action').val(this.value);
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

      // Handle multiple payment options and Stripe not being chosen.
      if (getIsDrupalWebform()) {
        stripeProcessorId = $('#stripe-id').val();
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
          stripeProcessorId = $('#stripe-id').val();
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
      if (!$('input#stripe-pub-key').length || !($('input#stripe-pub-key').val())) {
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

      // This is ONLY triggered in the following circumstances on a CiviCRM contribution page:
      // - With a priceset that allows a 0 amount to be selected.
      // - When Stripe is the ONLY payment processor configured on the page.
      if (typeof calculateTotalFee == 'function') {
        var totalFee = calculateTotalFee();
        if (totalFee == '0') {
          debugging("Total amount is 0");
          return true;
        }
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
      createToken();
      debugging('Created Stripe token');

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
    var billingFormID = $('input#stripe-pub-key').closest('form').prop('id');
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
      if (!submit.length) {
        // drupal 8 webform
        submit = form.querySelector('[type="submit"].webform-button--submit');
      }
    }
    else {
      submit = form.querySelector('[type="submit"].validate');
    }
    return submit;
  }

  function debugging (errorCode) {
    // Uncomment the following to debug unexpected returns.
    console.log(new Date().toISOString() + ' civicrm_stripe.js: ' + errorCode);
  }

});

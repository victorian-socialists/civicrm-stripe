/* custom js for public selection of future recurring start dates
 * only show option when recurring is selected
 * start by removing any previously injected similar field
 */
(function ($, ts) {
  'use strict';
  var scriptName = 'stripeRecurStart';
  var recurSection = '.is_recur-section';
  var recurMode = 'recur';
  var stripeRecurringStartDateSection = null;

  $(document).on('crmStripeBillingFormReloadComplete', function() {
    CRM.payment.debugging(scriptName, 'crmStripeBillingFormReloadComplete');

    if (!$(recurSection).length) {
      recurSection = '#allow_auto_renew';
      recurMode = 'membership';
    }

    if ($('#stripe-recurring-start-date').length) {
      stripeRecurringStartDateSection = $('#stripe-recurring-start-date');
    }
    if (!$(recurSection).length) {
      // I'm not on the right kind of page, just remove the extra field
      $('#stripe-recurring-start-date').remove();
    }

    // Remove/insert the recur start date element just below the recur selections
    $(recurSection + ' #stripe-recurring-start-date').remove();
    $(recurSection).after(stripeRecurringStartDateSection);

    // It is hard to detect when changing memberships etc.
    // So trigger on all input element changes on the form.
    var billingFormID = CRM.payment.getBillingForm().id;
    var debounceTimeoutId;
    var inputOnChangeRunning = false;
    $('#' + billingFormID + ' input').on('change', function() {
      // As this runs on all input elements on the form we debounce so it runs max once every 200ms
      if (inputOnChangeRunning) {
        return;
      }
      clearTimeout(debounceTimeoutId);
      inputOnChangeRunning = true;
      debounceTimeoutId = setTimeout(function() {
        toggleRecur();
        inputOnChangeRunning = false;
      }, 200);
    });
    // Trigger when we change the frequency unit selector (eg. month, year) on recur
    $('select#frequency_unit').on('change', function() {
      toggleRecur();
    });

    toggleRecur();
  });

  function toggleRecur() {
    if (CRM.payment.getIsRecur()) {
      if ($('select#frequency_unit').length > 0) {
        var selectedFrequencyUnit = $('select#frequency_unit').val();
        if (CRM.$.inArray(selectedFrequencyUnit, CRM.vars.stripe.startDateFrequencyIntervals) < 0) {
          hideStartDate();
          return;
        }
      }
      if ($('#receive_date option').length === 1) {
        // We only have one option. No need to offer selection - just show the date
        $('#receive_date').parent('div.content').prev('div.label').hide();
        $('#receive_date').next('div.description').hide();
        $('#receive_date').hide();
        $('#recur-start-date-description').remove();
        var recurStartMessage = '';
        if (recurMode === 'membership') {
          recurStartMessage = ts('Your membership payment will start on %1', {1: $('#receive_date').text()});
        }
        else {
          recurStartMessage = ts('Your recurring contribution will start on %1', {1: $('#receive_date').text()})
        }
        $('#receive_date').after(
          '<div class="description" id="recur-start-date-description">' + recurStartMessage + '</div>'
        );
      }
      $('#stripe-recurring-start-date').show().val('');
    }
    else {
      hideStartDate();
    }

    function hideStartDate() {
      $('#stripe-recurring-start-date').hide();
      $("#stripe-recurring-start-date option:selected").prop("selected", false);
      $("#stripe-recurring-start-date option:first").prop("selected", "selected");
    }
  }

}(CRM.$, CRM.ts('com.drastikbydesign.stripe')));


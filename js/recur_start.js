/* custom js for public selection of future recurring start dates
 * only show option when recurring is selected
 * start by removing any previously injected similar field
 */
CRM.$(function($) {
  'use strict';
   if ($('.is_recur-section').length) {
     $('.is_recur-section #stripe-recurring-start-date').remove();
     $('.is_recur-section').append($('#stripe-recurring-start-date'));
     cj('input[id="is_recur"]').on('change', function() {
       toggleRecur();
     });
     toggleRecur();
   }
   else {
     // I'm not on the right kind of page, just remove the extra field
     $('#stripe-recurring-start-date').remove();
   }

   function toggleRecur() {
     var isRecur = $('input[id="is_recur"]:checked');
     if (isRecur.val() > 0) {
       if ($('#receive_date option').length === 1) {
         // We only have one option. No need to offer selection - just show the date
         $('#receive_date').parent('div.content').prev('div.label').hide();
         $('#receive_date').next('div.description').hide();
         $('#receive_date').hide();
         $('#recur-start-date-description').remove();
         $('#receive_date').after('<div class="description" id="recur-start-date-description">Your recurring contribution will start on' + $('#receive_date').text() + '</div>');
       }
       $('#stripe-recurring-start-date').show().val('');
     }
     else {
       $('#stripe-recurring-start-date').hide();
       $("#stripe-recurring-start-date option:selected").prop("selected", false);
       $("#stripe-recurring-start-date option:first").prop("selected", "selected");
     }
   }
});

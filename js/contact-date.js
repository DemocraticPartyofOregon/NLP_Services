/* global Drupal, once */
(function (Drupal, once) {
  'use strict';

  // Y-m-d for a Date, in the browser's time zone.
  function ymd(date) {
    const pad = function (n) { return (n < 10 ? '0' : '') + n; };
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }

  // The date of a voter contact (NlpContactDate::dateElement()): a future
  // date is put back to today, and a date more than a few days old gets a
  // reminder to report right after the contact.  The server checks the date
  // again when the form is submitted.
  // noinspection JSUnusedGlobalSymbols,JSUnusedLocalSymbols
  Drupal.behaviors.nlpContactDate = {
    attach: function (context, settings) {
      once('nlp-contact-date', 'input.nlp-contact-date', context).forEach(function (input) {
        const note = document.createElement('div');
        note.className = 'nlp-contact-date-note';
        note.setAttribute('role', 'status');
        note.hidden = true;
        input.insertAdjacentElement('afterend', note);

        function show(message, kind) {
          note.textContent = message;
          note.className = 'nlp-contact-date-note nlp-contact-date-' + kind;
          note.hidden = message === '';
        }

        input.addEventListener('change', function () {
          const today = input.max || ymd(new Date());
          const date = input.value;
          if (date === '') { show('', 'late'); return; }
          if (date > today) {
            input.value = today;
            show(input.dataset.futureMsg, 'future');
            return;
          }
          const oldest = new Date(today + 'T00:00:00');
          oldest.setDate(oldest.getDate() - parseInt(input.dataset.lateDays || '3', 10));
          show(date < ymd(oldest) ? input.dataset.lateMsg : '', 'late');
        });
      });
    }
  };
}(Drupal, once));

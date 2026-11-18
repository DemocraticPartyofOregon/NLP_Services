/* global Drupal, once */
(function (Drupal, once) {
  'use strict';
  // The data entry form's "Show Contacts" buttons: each opens and closes the
  // historical contacts popup beside it.
  // noinspection JSUnusedGlobalSymbols,JSUnusedLocalSymbols
  Drupal.behaviors.nlpHistoricalContacts = {
    attach: function (context, settings) {
      once('nlp-historical-contacts', '.historical-contacts-toggle', context).forEach(function (button) {
        const list = button.parentElement.querySelector('.historical-report-list');
        if (!list) { return; }
        button.addEventListener('click', function () {
          const show = list.hidden;
          list.hidden = !show;
          button.setAttribute('aria-expanded', show ? 'true' : 'false');
          button.textContent = show ? 'Hide Contacts' : 'Show Contacts';
        });
      });
    }
  };
}(Drupal, once));

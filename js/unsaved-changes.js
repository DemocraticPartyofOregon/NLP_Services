/* global Drupal */
(function (Drupal) {
  'use strict';

  // The HD/precinct selects and the name search only filter the list; they are
  // not edits to save.  Other pages mark their non-edit controls (search boxes,
  // date pickers, etc.) with the nlp-not-an-edit class.
  function isFilterControl(el) {
    return el.classList.contains('nlp-hd-select')
      || el.classList.contains('nlp-pct-select')
      || el.classList.contains('nlp-not-an-edit')
      || el.name === 'search-needle';
  }

  // noinspection JSUnusedGlobalSymbols,JSUnusedLocalSymbols
  Drupal.behaviors.nlpUnsavedChanges = {
    attach: function (context, settings) {
      // Attach only once per page load, not on every AJAX response.
      if (window.nlpUnsavedChangesAttached) { return; }
      const form = document.querySelector('form.js-form-changed');
      if (!form) { return; }
      window.nlpUnsavedChangesAttached = true;

      let formChanged = false;

      function markChanged(e) {
        if (isFilterControl(e.target)) { return; }
        formChanged = true;
        // Reveal the floating Save Changes button now that there is something to save.
        form.querySelectorAll('.nlp-floating-save').forEach(function (button) {
          button.classList.add('is-visible');
        });
        // Color the top Save Changes button so it's clear there is something to save.
        form.querySelectorAll('.nlp-save-top').forEach(function (button) {
          button.classList.add('is-changed');
        });
      }
      form.addEventListener('change', markChanged);
      form.addEventListener('input', markChanged);

      // Any form submission (Save, Next, Previous, etc.) means the data was processed.
      form.addEventListener('submit', function () { formChanged = false; });

      window.addEventListener('beforeunload', function (e) {
        if (formChanged) {
          e.preventDefault();
        }
      });
    }
  };
}(Drupal));

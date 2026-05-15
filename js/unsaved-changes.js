(function (Drupal) {
  'use strict';
  Drupal.behaviors.nlpUnsavedChanges = {
    attach: function (context, settings) {
      // Attach only once per page load, not on every AJAX response.
      if (window.nlpUnsavedChangesAttached) { return; }
      var form = document.querySelector('form.js-form-changed');
      if (!form) { return; }
      window.nlpUnsavedChangesAttached = true;

      var formChanged = false;

      form.addEventListener('change', function () { formChanged = true; });
      form.addEventListener('input', function () { formChanged = true; });

      // Any form submission (Save, Next, Previous, etc.) means the data was processed.
      form.addEventListener('submit', function () { formChanged = false; });

      window.addEventListener('beforeunload', function (e) {
        if (formChanged) {
          e.preventDefault();
          e.returnValue = '';
        }
      });
    }
  };
}(Drupal));

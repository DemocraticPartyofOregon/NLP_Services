/* global Drupal */
(function (Drupal) {
  'use strict';
  // noinspection JSUnusedGlobalSymbols,JSUnusedLocalSymbols
  Drupal.behaviors.nlpSelectAllPostcards = {
    attach: function (context, settings) {
      const selectAll = context.querySelector('#postcards-select-all');
      if (!selectAll) { return; }

      const voterBoxes = function () {
        return document.querySelectorAll('input[name^="postcardsSentList["]');
      };

      selectAll.addEventListener('change', function () {
        voterBoxes().forEach(function (cb) { cb.checked = selectAll.checked; });
      });

      // Keep "select all" in sync when individual boxes are changed.
      document.addEventListener('change', function (e) {
        if (!e.target.name || !e.target.name.startsWith('postcardsSentList[')) { return; }
        const boxes = voterBoxes();
        const allChecked = Array.from(boxes).every(function (cb) { return cb.checked; });
        selectAll.checked = allChecked;
        selectAll.indeterminate = !allChecked && Array.from(boxes).some(function (cb) { return cb.checked; });
      });
    }
  };
}(Drupal));

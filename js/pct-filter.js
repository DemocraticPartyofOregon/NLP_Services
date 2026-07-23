/* global Drupal */
(function (Drupal) {
  'use strict';
  // noinspection JSUnusedGlobalSymbols
  Drupal.behaviors.nlpPctFilter = {
    attach: function (context) {
      context.querySelectorAll('.nlp-pct-select').forEach(function (el) {
        if (el.dataset.nlpPctFilterAttached) { return; }
        el.dataset.nlpPctFilterAttached = 'true';
        el.addEventListener('change', function () {
          var url = new URL(window.location.href);
          url.searchParams.set('pct_index', el.value);
          window.location.href = url.toString();
        });
      });
    }
  };
}(Drupal));

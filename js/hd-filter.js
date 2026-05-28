/* global Drupal */
(function (Drupal) {
  'use strict';
  // noinspection JSUnusedGlobalSymbols
  Drupal.behaviors.nlpHdFilter = {
    attach: function (context) {
      context.querySelectorAll('.nlp-hd-select').forEach(function (el) {
        if (el.dataset.nlpHdFilterAttached) { return; }
        el.dataset.nlpHdFilterAttached = 'true';
        el.addEventListener('change', function () {
          var url = new URL(window.location.href);
          url.searchParams.set('hd_index', el.value);
          window.location.href = url.toString();
        });
      });
    }
  };
}(Drupal));

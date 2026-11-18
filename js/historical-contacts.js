/* global Drupal, once */
(function (Drupal, once) {
  'use strict';

  // Open or close a popup and update the button that toggles it.
  function setOpen(button, popup, open, openText, closedText) {
    popup.hidden = !open;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.textContent = open ? openText : closedText;
  }

  // The data entry form's "Show Contacts" buttons: each opens and closes the
  // historical contacts popup beside it.  The "Edit" button beside it opens and
  // closes the list of reports the user may change.  Only one of the two
  // popups is open at a time, since they open in the same place.
  // noinspection JSUnusedGlobalSymbols,JSUnusedLocalSymbols
  Drupal.behaviors.nlpHistoricalContacts = {
    attach: function (context, settings) {
      once('nlp-historical-contacts', '.historical-contacts', context).forEach(function (box) {
        const contactsButton = box.querySelector('.historical-contacts-toggle');
        const contactsList = box.querySelector('.historical-report-list');
        const editButton = box.querySelector('.report-edit-toggle');
        const editList = box.querySelector('.report-edit-list');
        const hasContacts = contactsButton && contactsList;
        const hasEdit = editButton && editList;

        function showContacts(open) {
          if (hasContacts) { setOpen(contactsButton, contactsList, open, 'Hide Contacts', 'Show Contacts'); }
        }
        function showEdit(open) {
          if (hasEdit) { setOpen(editButton, editList, open, 'Close Edit', 'Edit'); }
        }

        if (hasContacts) {
          contactsButton.addEventListener('click', function () {
            const open = contactsList.hidden;
            showEdit(false);
            showContacts(open);
          });
        }
        if (!hasEdit) { return; }
        editButton.addEventListener('click', function () {
          const open = editList.hidden;
          showContacts(false);
          showEdit(open);
        });

        editList.querySelectorAll('.report-edit-item').forEach(function (item) {
          const textArea = item.querySelector('.report-text-area');
          const textToggle = item.querySelector('.report-text-toggle');
          const cancel = item.querySelector('.report-text-cancel');
          const deleteButton = item.querySelector('.report-delete');
          // A note's box has several lines; a phone number's or email's has one.
          const text = textArea ? textArea.querySelector('textarea, input[type="text"]') : null;
          const save = textArea ? textArea.querySelector('input[type="submit"]') : null;
          if (textArea && textToggle) {
            textToggle.addEventListener('click', function () {
              textArea.hidden = false;
              textToggle.hidden = true;
              if (text) { text.focus(); }
            });
          }
          if (textArea && textToggle && cancel) {
            cancel.addEventListener('click', function () {
              if (text) { text.value = text.defaultValue; }
              textArea.hidden = true;
              textToggle.hidden = false;
            });
          }
          // Enter in a one-line box would submit the form with its first
          // button (the last name search); make it save the correction instead.
          if (text && save && text.tagName === 'INPUT') {
            text.addEventListener('keydown', function (e) {
              if (e.key === 'Enter') {
                e.preventDefault();
                save.click();
              }
            });
          }
          if (deleteButton) {
            deleteButton.addEventListener('click', function (e) {
              if (!window.confirm('Delete this report? This cannot be undone.')) {
                e.preventDefault();
              }
            });
          }
        });
      });
    }
  };
}(Drupal, once));

(function () {
  'use strict';
  const root = document.querySelector('.sie-admin');
  if (!root) return;

  root.querySelectorAll('[data-sie-autosubmit]').forEach((field) => {
    field.addEventListener('change', () => field.form && field.form.requestSubmit());
  });

  root.querySelectorAll('form[data-sie-confirm]').forEach((form) => {
    let confirmed = false;
    form.addEventListener('submit', async (event) => {
      if (confirmed) { confirmed = false; return; }
      event.preventDefault();
      const submitter = event.submitter;
      const options = {
        title: form.dataset.sieConfirmTitle || 'Confirm action',
        message: form.dataset.sieConfirmMessage || 'Continue this action?',
        confirmText: form.dataset.sieConfirmText || 'Continue',
        focus: 'cancel'
      };
      const accepted = window.NewNotifConfirm
        ? await window.NewNotifConfirm.warning(options)
        : window.confirm(options.message);
      if (!accepted || !form.isConnected || !form.reportValidity()) return;
      confirmed = true;
      form.requestSubmit(submitter || undefined);
    });
  });
}());

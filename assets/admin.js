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

  root.querySelectorAll('[data-sie-delete-question]').forEach((button) => {
    button.addEventListener('click', async (event) => {
      event.preventDefault();
      const accepted = window.NewNotifConfirm
        ? await window.NewNotifConfirm.danger({
            title: 'Delete this draft question?',
            message: 'The question and its hidden scoring will be removed from this draft. Published versions are not affected.',
            confirmText: 'Delete question',
            focus: 'cancel'
          })
        : window.confirm('Delete this question from the draft?');
      if (accepted && button.form && button.form.reportValidity()) button.form.requestSubmit(button);
    });
  });

  const branding = root.querySelector('[data-sie-branding]');
  if (branding) {
    const input = branding.querySelector('[data-sie-branding-url]');
    const titleInput = branding.querySelector('[data-sie-branding-title]');
    const preview = branding.querySelector('[data-sie-branding-preview]');
    const image = preview?.querySelector('img');
    const mark = preview?.querySelector('.sie-branding-default-mark');
    const title = preview?.querySelector('strong');
    const mediaPickerUrl = branding.dataset.sieMediaPickerUrl || '';
    const updatePreview = () => {
      const url = input?.value.trim() || '';
      if (image && url) { image.src = url; image.hidden = false; }
      if (image && !url) { image.removeAttribute('src'); image.hidden = true; }
      if (mark) mark.hidden = url !== '';
      if (title) title.textContent = titleInput?.value.trim() || preview?.dataset.sieBrandingFallbackTitle || '';
    };
    branding.querySelector('[data-sie-branding-clear]')?.addEventListener('click', () => {
      if (!input) return;
      input.value = '';
      updatePreview();
      input.focus();
    });
    branding.querySelector('[data-sie-branding-choose]')?.addEventListener('click', () => {
      if (!input || !mediaPickerUrl || typeof window.openMediaSelector !== 'function') return;
      window.openMediaSelector({ url: mediaPickerUrl })
        .then((detail) => {
          const media = typeof window.normalizeMedia === 'function' ? window.normalizeMedia(detail) : detail;
          if (!media?.url) return;
          input.value = media.url;
          updatePreview();
        })
        .catch((error) => console.error('Study Interest media selector error', error));
    });
    input?.addEventListener('input', updatePreview);
    titleInput?.addEventListener('input', updatePreview);
    image?.addEventListener('error', () => { image.hidden = true; if (mark) mark.hidden = false; });
    updatePreview();
  }

  const optionEditor = root.querySelector('[data-sie-option-editor]');
  const optionTemplate = root.querySelector('[data-sie-option-template]');
  const optionCount = root.querySelector('[data-sie-option-count]');
  const updateOptionRows = () => {
    if (!optionEditor) return;
    const rows = optionEditor.querySelectorAll('[data-sie-option-row]');
    rows.forEach((row, index) => {
      const title = row.querySelector('.sie-option-row-title strong');
      if (title) title.textContent = `Answer ${index + 1}`;
    });
    if (optionCount) optionCount.textContent = String(rows.length);
  };
  root.querySelector('[data-sie-add-option]')?.addEventListener('click', () => {
    if (!optionEditor || !optionTemplate) return;
    const index = Number(optionEditor.dataset.nextIndex || '0');
    const fragment = optionTemplate.content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach((field) => {
      field.name = field.name.replace('__INDEX__', String(index));
    });
    optionEditor.append(fragment);
    optionEditor.dataset.nextIndex = String(index + 1);
    updateOptionRows();
    optionEditor.lastElementChild?.querySelector('input')?.focus();
  });
  optionEditor?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-sie-remove-option]');
    if (!button) return;
    const rows = optionEditor.querySelectorAll('[data-sie-option-row]');
    if (rows.length <= 2) {
      window.alert('At least two answer options are required.');
      return;
    }
    button.closest('[data-sie-option-row]')?.remove();
    updateOptionRows();
  });
}());

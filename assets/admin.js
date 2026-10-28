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

  const correctionForm = root.querySelector('[data-sie-correction-form]');
  const guidance = root.querySelector('[data-sie-correction-guidance]');
  if (correctionForm && guidance) {
    const targetField = guidance.querySelector('[data-sie-guidance-target]');
    const status = guidance.querySelector('[data-sie-guidance-status]');
    const programList = guidance.querySelector('[data-sie-guidance-programs]');
    const suggestionList = guidance.querySelector('[data-sie-guidance-suggestions]');
    const answerFields = Array.from(correctionForm.querySelectorAll('[data-sie-answer]'));
    let previewTimer = 0;
    let previewRequest = null;
    let previewSequence = 0;
    const previewError = guidance.dataset.error || 'Guidance preview is unavailable.';

    const decimal = (value) => Number(value || 0).toFixed(2);
    const signed = (value) => {
      const number = Number(value || 0);
      return `${number > 0 ? '+' : ''}${decimal(number)}`;
    };
    const renderPreview = (preview) => {
      const target = preview.target || {};
      status.textContent = `${target.label || target.code}: ${decimal(target.score)} (${signed(target.delta)} from saved result), position ${target.position || '–'}. ${preview.changed_count || 0} answer(s) changed.`;

      programList.replaceChildren();
      (preview.programs || []).forEach((program) => {
        const card = document.createElement('div');
        if (program.code === target.code) card.className = 'is-target';
        const label = document.createElement('span');
        label.textContent = `#${program.position} ${program.label}`;
        const score = document.createElement('strong');
        score.textContent = decimal(program.score);
        const detail = document.createElement('small');
        detail.textContent = `${signed(program.delta)} · ${program.classification}${program.recommended ? ' · recommended' : ''}`;
        card.append(label, score, detail);
        programList.append(card);
      });

      answerFields.forEach((field) => {
        const questionCode = field.dataset.sieAnswer || '';
        const choices = preview.choices?.[questionCode] || {};
        Array.from(field.options).forEach((option) => {
          option.dataset.sieBaseLabel ||= option.textContent;
          const choice = choices[option.value];
          option.textContent = choice
            ? `${option.dataset.sieBaseLabel} · ${target.label} ${decimal(choice.score)} (${signed(choice.delta)}) · position #${choice.position}`
            : option.dataset.sieBaseLabel;
        });
        const selected = choices[field.value];
        const impact = field.closest('[data-sie-question]')?.querySelector('[data-sie-choice-impact]');
        if (impact) impact.textContent = selected
          ? `Draft result for ${target.label}: ${decimal(selected.score)}, position ${selected.position}${selected.recommended ? ', recommended' : ''}. Other options show their one-answer impact.`
          : '';
      });

      suggestionList.replaceChildren();
      const suggestions = preview.suggestions || [];
      if (!suggestions.length) {
        const empty = document.createElement('p');
        empty.textContent = 'No single answer change raises this target from the current draft.';
        suggestionList.append(empty);
      }
      suggestions.forEach((suggestion) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.questionCode = suggestion.question_code;
        button.dataset.optionCode = suggestion.option_code;
        const title = document.createElement('strong');
        title.textContent = `Q${suggestion.question_number} · ${suggestion.question_code} → ${suggestion.option_code}`;
        const option = document.createElement('span');
        option.textContent = suggestion.option_label;
        const effect = document.createElement('small');
        effect.textContent = `${target.label} ${decimal(suggestion.score)} (${signed(suggestion.delta)}), position ${suggestion.position}`;
        button.append(title, option, effect);
        suggestionList.append(button);
      });
    };
    const requestPreview = async (sequence) => {
      previewRequest = new AbortController();
      guidance.setAttribute('aria-busy', 'true');
      status.textContent = 'Calculating guidance…';
      try {
        const response = await fetch(guidance.dataset.endpoint || '', {
          method: 'POST',
          body: new FormData(correctionForm),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          signal: previewRequest.signal
        });
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) throw new Error(previewError);
        const payload = await response.json();
        const expectedRevision = Number(correctionForm.elements.expected_revision?.value || -1);
        if (!response.ok || !payload.ok) throw new Error(payload.error || previewError);
        if (Number(payload.revision) !== expectedRevision) throw new Error('This result changed while guidance was calculated. Reload the latest revision.');
        if (sequence === previewSequence) renderPreview(payload.preview || {});
      } catch (error) {
        if (error.name !== 'AbortError' && sequence === previewSequence) status.textContent = error.message || previewError;
      } finally {
        if (sequence === previewSequence) guidance.removeAttribute('aria-busy');
      }
    };
    const clearPreview = () => {
      programList.replaceChildren();
      suggestionList.replaceChildren();
      answerFields.forEach((field) => {
        Array.from(field.options).forEach((option) => {
          if (option.dataset.sieBaseLabel) option.textContent = option.dataset.sieBaseLabel;
        });
        const impact = field.closest('[data-sie-question]')?.querySelector('[data-sie-choice-impact]');
        if (impact) impact.textContent = '';
      });
    };
    const schedulePreview = () => {
      const sequence = ++previewSequence;
      previewRequest?.abort();
      window.clearTimeout(previewTimer);
      clearPreview();
      guidance.setAttribute('aria-busy', 'true');
      status.textContent = 'Waiting to recalculate guidance…';
      previewTimer = window.setTimeout(() => requestPreview(sequence), 180);
    };
    answerFields.forEach((field) => field.addEventListener('change', schedulePreview));
    targetField?.addEventListener('change', schedulePreview);
    suggestionList?.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-question-code][data-option-code]');
      if (!button) return;
      const field = answerFields.find((item) => item.dataset.sieAnswer === button.dataset.questionCode);
      if (!field) return;
      field.value = button.dataset.optionCode || '';
      field.dispatchEvent(new Event('change', { bubbles: true }));
      field.focus();
    });
    schedulePreview();
  }

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

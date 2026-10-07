(function () {
  'use strict';
  const data = window.StudyInterestData || {};
  const request = async (url, payload) => {
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': data.csrf},
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });
    const result = await response.json().catch(() => ({ok: false, error: 'Respons server tidak dapat dibaca.'}));
    if (!response.ok || !result.ok) throw new Error(result.error || 'Permintaan tidak berhasil.');
    return result;
  };

  if (data.mode === 'landing') {
    const form = document.querySelector('#sie-start-form');
    const consent = document.querySelector('#sie-consent');
    const start = document.querySelector('#sie-start');
    const message = document.querySelector('#sie-message');
    const name = document.querySelector('#sie-name');
    const school = document.querySelector('#sie-school');
    const classLevel = document.querySelector('#sie-class');
    const email = document.querySelector('#sie-email');
    const phone = document.querySelector('#sie-phone');
    const contactConsent = document.querySelector('#sie-contact-consent');
    if (!form || !consent || !start || !message || !name || !school || !classLevel || !email || !phone || !contactConsent) return;
    let submitting = false;
    const updateStart = () => {
      if (submitting) return;
      const hasContact = Boolean(email.value.trim() || phone.value.trim());
      start.disabled = !consent.checked || name.value.trim().length < 2 || school.value.trim().length < 2
        || !classLevel.value.trim() || !email.validity.valid || (hasContact && !contactConsent.checked);
    };
    [consent, name, school, classLevel, email, phone, contactConsent].forEach((field) => field.addEventListener('input', updateStart));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (submitting || start.disabled) return;
      if (!form.reportValidity()) return;
      const payload = {
        consent: true,
        version_id: data.versionId,
        configuration_hash: data.configurationHash,
        name: name.value.trim(),
        school: school.value.trim(),
        class_level: classLevel.value.trim(),
        email: email.value.trim(),
        phone: phone.value.trim(),
        contact_consent: contactConsent.checked
      };
      submitting = true;
      Array.from(form.elements).forEach((field) => { field.disabled = true; });
      message.dataset.state = 'loading';
      message.textContent = 'Menyiapkan ruang eksplorasimu...';
      try {
        const result = await request(data.startUrl, payload);
        location.assign('/study-interest/?session=' + encodeURIComponent(result.session.public_id));
      } catch (error) {
        submitting = false;
        Array.from(form.elements).forEach((field) => { field.disabled = false; });
        message.dataset.state = 'error';
        message.textContent = error.message;
        updateStart();
      }
    });
    return;
  }

  if (data.mode !== 'assessment' || !Array.isArray(data.questions)) return;
  const questionHost = document.querySelector('#sie-question');
  const progress = document.querySelector('#sie-progress');
  const progressLabel = document.querySelector('#sie-progress-label');
  const back = document.querySelector('#sie-back');
  const next = document.querySelector('#sie-next');
  const saveState = document.querySelector('#sie-save-state');
  if (!questionHost || !progress || !progressLabel || !back || !next || !saveState || data.questions.length === 0) return;
  const answers = Object.assign({}, data.answers || {});
  const firstUnanswered = data.questions.findIndex((question) => !answers[String(question.id)]);
  let index = firstUnanswered < 0 ? data.questions.length - 1 : firstUnanswered;
  let saving = false;

  const setSaveState = (text, state) => {
    saveState.dataset.state = state;
    saveState.lastChild.textContent = ' ' + text;
  };
  const renderControls = () => {
    const question = data.questions[index];
    back.disabled = index === 0 || saving;
    next.disabled = !answers[String(question.id)] || saving;
    questionHost.querySelectorAll('input').forEach((input) => { input.disabled = saving; });
  };
  const render = (focusHeading) => {
    const question = data.questions[index];
    progress.value = index + 1;
    progressLabel.textContent = 'Pertanyaan ' + (index + 1) + ' dari ' + data.questions.length;
    next.replaceChildren(document.createTextNode(index === data.questions.length - 1 ? 'Lihat hasil ' : 'Lanjut '));
    const arrow = document.createElement('span');
    arrow.setAttribute('aria-hidden', 'true');
    arrow.innerHTML = '&rarr;';
    next.append(arrow);
    questionHost.replaceChildren();

    const fieldset = document.createElement('fieldset');
    fieldset.className = 'sie-question-fieldset';
    const legend = document.createElement('legend');
    legend.className = 'sie-visually-hidden';
    legend.textContent = question.title || question.prompt;
    const meta = document.createElement('div');
    meta.className = 'sie-question-meta';
    const section = document.createElement('span');
    section.textContent = 'BAGIAN ' + question.section;
    const sectionLabel = document.createElement('strong');
    sectionLabel.textContent = question.section_label;
    meta.append(section, sectionLabel);
    const heading = document.createElement('h1');
    heading.id = 'sie-question-title';
    heading.tabIndex = -1;
    heading.textContent = question.title || question.prompt;
    fieldset.setAttribute('aria-labelledby', heading.id);
    fieldset.append(legend, meta, heading);
    if (question.title) {
      const prompt = document.createElement('p');
      prompt.className = 'sie-question-prompt';
      prompt.textContent = question.prompt;
      fieldset.append(prompt);
    }
    const options = document.createElement('div');
    options.className = 'sie-options';
    question.options.forEach((option, optionIndex) => {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'radio';
      input.name = 'study-interest-answer';
      input.value = option.id;
      input.checked = Number(answers[String(question.id)]) === option.id;
      const marker = document.createElement('span');
      marker.className = 'sie-option-marker';
      marker.textContent = String.fromCharCode(65 + optionIndex);
      marker.setAttribute('aria-hidden', 'true');
      const text = document.createElement('span');
      text.className = 'sie-option-text';
      text.textContent = option.label;
      label.append(input, marker, text);
      input.addEventListener('change', async () => {
        if (saving) return;
        const previous = answers[String(question.id)];
        saving = true;
        setSaveState('Menyimpan...', 'saving');
        renderControls();
        try {
          await request(data.answerUrl, {session: data.session, question_id: question.id, option_id: option.id});
          answers[String(question.id)] = option.id;
          setSaveState('Tersimpan', 'saved');
        } catch (error) {
          if (previous === undefined) delete answers[String(question.id)];
          else answers[String(question.id)] = previous;
          render(false);
          setSaveState(error.message, 'error');
        } finally {
          saving = false;
          renderControls();
        }
      });
      options.append(label);
    });
    fieldset.append(options);
    questionHost.append(fieldset);
    renderControls();
    if (focusHeading) heading.focus({preventScroll: true});
  };
  const moveTo = (nextIndex) => {
    index = nextIndex;
    render(true);
    scrollTo({top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
  };
  back.addEventListener('click', () => { if (index > 0 && !saving) moveTo(index - 1); });
  next.addEventListener('click', async () => {
    if (saving || !answers[String(data.questions[index].id)]) return;
    if (index < data.questions.length - 1) { moveTo(index + 1); return; }
    saving = true;
    setSaveState('Menyusun hasil...', 'saving');
    renderControls();
    try {
      const result = await request(data.completeUrl, {session: data.session});
      location.assign(result.result_url);
    } catch (error) {
      setSaveState(error.message, 'error');
      saving = false;
      renderControls();
    }
  });
  render(false);
}());

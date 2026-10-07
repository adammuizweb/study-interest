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
    const result = await response.json().catch(() => ({ok: false, error: 'Invalid server response'}));
    if (!response.ok || !result.ok) throw new Error(result.error || 'Request failed');
    return result;
  };

  if (data.mode === 'landing') {
    const consent = document.querySelector('#sie-consent');
    const start = document.querySelector('#sie-start');
    const message = document.querySelector('#sie-message');
    if (!consent || !start || !message) return;
    consent.addEventListener('change', () => { start.disabled = !consent.checked; });
    start.addEventListener('click', async () => {
      start.disabled = true;
      message.textContent = 'Preparing your exploration...';
      try {
        const result = await request(data.startUrl, {consent: true});
        location.assign('/study-interest/?session=' + encodeURIComponent(result.session.public_id));
      } catch (error) {
        message.textContent = error.message;
        start.disabled = false;
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
  const answers = Object.assign({}, data.answers || {});
  const firstUnanswered = data.questions.findIndex((question) => !answers[String(question.id)]);
  let index = firstUnanswered < 0 ? data.questions.length - 1 : firstUnanswered;
  let saving = false;

  const render = () => {
    const question = data.questions[index];
    progress.value = index + 1;
    progressLabel.textContent = 'Question ' + (index + 1) + ' of ' + data.questions.length;
    back.disabled = index === 0 || saving;
    next.disabled = !answers[String(question.id)] || saving;
    next.textContent = index === data.questions.length - 1 ? 'See my result' : 'Next';
    questionHost.replaceChildren();
    const meta = document.createElement('p');
    meta.className = 'sie-eyebrow';
    meta.textContent = 'SECTION ' + question.section + ' · ' + question.section_label;
    const heading = document.createElement('h1');
    heading.textContent = question.title || question.prompt;
    questionHost.append(meta, heading);
    if (question.title) {
      const prompt = document.createElement('p');
      prompt.className = 'sie-question-prompt';
      prompt.textContent = question.prompt;
      questionHost.append(prompt);
    }
    const options = document.createElement('div');
    options.className = 'sie-options';
    question.options.forEach((option) => {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'radio';
      input.name = 'study-interest-answer';
      input.value = option.id;
      input.checked = Number(answers[String(question.id)]) === option.id;
      const text = document.createElement('span');
      text.textContent = option.label;
      label.append(input, text);
      input.addEventListener('change', async () => {
        const previous = answers[String(question.id)];
        saving = true;
        saveState.textContent = 'Saving...';
        renderControls();
        try {
          await request(data.answerUrl, {session: data.session, question_id: question.id, option_id: option.id});
          answers[String(question.id)] = option.id;
          saveState.textContent = 'Saved';
        } catch (error) {
          if (previous === undefined) delete answers[String(question.id)];
          else answers[String(question.id)] = previous;
          render();
          saveState.textContent = error.message;
        } finally {
          saving = false;
          renderControls();
        }
      });
      options.append(label);
    });
    questionHost.append(options);
  };
  const renderControls = () => {
    const question = data.questions[index];
    back.disabled = index === 0 || saving;
    next.disabled = !answers[String(question.id)] || saving;
  };
  back.addEventListener('click', () => { if (index > 0 && !saving) { index--; render(); scrollTo(0, 0); } });
  next.addEventListener('click', async () => {
    if (saving || !answers[String(data.questions[index].id)]) return;
    if (index < data.questions.length - 1) { index++; render(); scrollTo(0, 0); return; }
    saving = true;
    saveState.textContent = 'Calculating your result...';
    renderControls();
    try {
      const result = await request(data.completeUrl, {session: data.session});
      location.assign(result.result_url);
    } catch (error) {
      saveState.textContent = error.message;
      saving = false;
      renderControls();
    }
  });
  render();
}());

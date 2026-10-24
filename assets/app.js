document.addEventListener('DOMContentLoaded', () => {
  const timer = document.getElementById('exam-timer');
  const form = document.getElementById('exam-form');
  if (timer && form) {
    const deadline = Number(timer.dataset.deadline || 0);
    const submitButton = form.querySelector('button[type="submit"]');
    const connectivity = document.getElementById('exam-connectivity');
    const connectivityText = document.getElementById('exam-connection-text');
    const focusWarning = document.getElementById('exam-focus-warning');
    const fullscreenButton = document.getElementById('exam-fullscreen');
    let submitted = false;
    let approvedSubmit = false;
    let deferredSubmit = false;
    let flaggedPageSwitch = false;
    const tick = () => {
      const remaining = Math.max(0, deadline - Math.floor(Date.now()/1000));
      const min = Math.floor(remaining/60), sec = remaining%60;
      timer.textContent = `${String(min).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
      if (remaining <= 0 && !submitted) {
        submitted = true;
        timer.textContent = 'Submitting…';
        if (submitButton) submitButton.dataset.autoSubmit = '1';
        if (navigator.onLine) form.requestSubmit(submitButton || undefined);
        else {
          deferredSubmit = true;
          if (connectivity) connectivity.textContent = 'Time has ended. Reconnect to submit your saved answers.';
        }
      }
    };
    tick(); setInterval(tick, 1000);
    form.addEventListener('submit', (event) => {
      if (!approvedSubmit && !(event.submitter && event.submitter.dataset.autoSubmit) && !window.confirm('Submit your examination now? You cannot change answers after submission.')) { event.preventDefault(); return; }
      approvedSubmit = true;
      if (!navigator.onLine) {
        event.preventDefault();
        deferredSubmit = true;
        if (connectivity) connectivity.textContent = 'You are offline. Your latest server-saved answers are safe; submission will retry when you reconnect.';
        return;
      }
      const buttons = form.querySelectorAll('button[type="submit"]');
      buttons.forEach(b => { b.disabled = true; b.textContent = 'Submitting…'; });
    });

    const questions = Array.from(form.querySelectorAll('[data-question-index]'));
    const navButtons = Array.from(form.querySelectorAll('[data-nav-index]'));
    const progress = document.getElementById('exam-progress');
    const saveStatus = document.getElementById('answer-save-status');
    const previous = document.getElementById('previous-question');
    const next = document.getElementById('next-question');
    let current = 0;
    let saveQueue = Promise.resolve();
    const answerSaveTimers = new Map();
    const csrf = form.querySelector('input[name="csrf"]')?.value || '';
    const examId = form.querySelector('input[name="exam_id"]')?.value || '';
    const attemptId = form.querySelector('input[name="attempt_id"]')?.value || '';
    const draftKey = `cycle-exam-pending-${attemptId}`;
    let pendingDrafts = {};

    const persistDrafts = () => {
      try {
        if (Object.keys(pendingDrafts).length) sessionStorage.setItem(draftKey, JSON.stringify(pendingDrafts));
        else sessionStorage.removeItem(draftKey);
      } catch {
        if (saveStatus) saveStatus.textContent = 'Browser storage is unavailable; only server-saved answers can be recovered.';
      }
    };
    try {
      pendingDrafts = JSON.parse(sessionStorage.getItem(draftKey) || '{}');
      if (!pendingDrafts || typeof pendingDrafts !== 'object' || Array.isArray(pendingDrafts)) pendingDrafts = {};
    } catch {
      pendingDrafts = {};
    }

    const selectedAnswer = (questionId) => form.querySelector(`textarea[data-question-id="${questionId}"]`)?.value ?? form.querySelector(`input[data-question-id="${questionId}"]:checked`)?.value ?? '';
    const isFlagged = (questionId) => form.querySelector(`[data-flag-button][data-question-id="${questionId}"]`)?.getAttribute('aria-pressed') === 'true';
    const restorePendingDrafts = () => {
      Object.entries(pendingDrafts).forEach(([questionId, draft]) => {
        if (!/^[md]?\d+$/.test(questionId) || !draft || typeof draft.answer !== 'string' || draft.answer.length > 60000 || !['0', '1'].includes(draft.flagged)) return;
        const textarea = form.querySelector(`textarea[data-question-id="${questionId}"]`);
        if (textarea) textarea.value = draft.answer;
        const radio = draft.answer && form.querySelector(`input[data-question-id="${questionId}"][value="${draft.answer}"]`);
        if (radio) radio.checked = true;
        const button = form.querySelector(`[data-flag-button][data-question-id="${questionId}"]`);
        const flagValue = form.querySelector(`[data-flag-value][data-question-id="${questionId}"]`);
        if (button) {
          const flagged = draft.flagged === '1';
          button.setAttribute('aria-pressed', flagged ? 'true' : 'false');
          button.textContent = flagged ? 'Unflag question' : 'Flag for review';
          if (flagValue) flagValue.value = draft.flagged;
        }
      });
    };
    const updateNavigation = () => {
      let answeredCount = 0;
      navButtons.forEach((button) => {
        const question = questions[Number(button.dataset.navIndex)];
        const questionId = question.querySelector('[data-question-id]')?.dataset.questionId;
        const answered = Boolean(questionId && selectedAnswer(questionId));
        const flagged = Boolean(questionId && isFlagged(questionId));
        button.classList.toggle('answered', answered);
        button.classList.toggle('flagged', flagged);
        button.classList.toggle('current', Number(button.dataset.navIndex) === current);
        button.setAttribute('aria-label', `Question ${Number(button.dataset.navIndex) + 1}, ${answered ? 'answered' : 'unanswered'}${flagged ? ', flagged for review' : ''}`);
        if (answered) answeredCount++;
      });
      if (progress) progress.textContent = `${answeredCount} of ${questions.length} answered`;
      if (previous) previous.disabled = current === 0;
      if (next) next.disabled = current === questions.length - 1;
    };
    const showQuestion = (index) => {
      if (index < 0 || index >= questions.length) return;
      current = index;
      questions.forEach((question, i) => { question.hidden = i !== current; });
      updateNavigation();
    };
    const saveQuestion = (questionId) => {
      if (!questionId) return;
      if (saveStatus) saveStatus.textContent = 'Saving answer…';
      const draft = { answer: selectedAnswer(questionId), flagged: isFlagged(questionId) ? '1' : '0' };
      pendingDrafts[questionId] = draft;
      persistDrafts();
      const body = new URLSearchParams({
        csrf,
        exam_id: examId,
        attempt_id: attemptId,
        question_id: questionId,
        answer: selectedAnswer(questionId),
        flagged: isFlagged(questionId) ? '1' : '0'
      });
      saveQueue = saveQueue.then(() => {
        if (!navigator.onLine) throw new Error('Offline');
        return fetch('index.php?action=save_answer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin'
        });
      }).then(async (response) => {
        const result = await response.json();
        if (!response.ok || !result.saved) throw new Error(result.message || 'Answer could not be saved.');
        const latest = pendingDrafts[questionId];
        if (latest && latest.answer === draft.answer && latest.flagged === draft.flagged) {
          delete pendingDrafts[questionId];
          persistDrafts();
        }
        if (saveStatus && !Object.keys(pendingDrafts).length) saveStatus.textContent = 'All answers saved.';
      }).catch(() => {
        if (saveStatus) saveStatus.textContent = 'Save pending. Keep this page open; your answer will retry when connected.';
      });
    };
    const retryPendingSaves = () => {
      if (!navigator.onLine) return;
      Object.keys(pendingDrafts).forEach(saveQuestion);
    };
    const updateConnectivity = () => {
      if (!connectivity) return;
      const offline = !navigator.onLine;
      connectivity.classList.toggle('alert-danger', offline);
      connectivity.classList.toggle('alert-success', !offline);
      if (connectivityText) connectivityText.textContent = offline
        ? 'Internet connection lost. Keep this page open; unsaved answers will retry when reconnected.'
        : 'Connected — answers save automatically.';
    };

    navButtons.forEach((button) => button.addEventListener('click', () => showQuestion(Number(button.dataset.navIndex))));
    previous?.addEventListener('click', () => showQuestion(current - 1));
    next?.addEventListener('click', () => showQuestion(current + 1));
    form.querySelectorAll('input[type="radio"][data-question-id]').forEach((input) => input.addEventListener('change', () => {
      updateNavigation();
      saveQuestion(input.dataset.questionId);
    }));
    form.querySelectorAll('textarea[data-question-id]').forEach((textarea) => {
      const saveSoon = () => {
        updateNavigation();
        window.clearTimeout(answerSaveTimers.get(textarea.dataset.questionId));
        answerSaveTimers.set(textarea.dataset.questionId, window.setTimeout(() => saveQuestion(textarea.dataset.questionId), 800));
      };
      textarea.addEventListener('input', saveSoon);
      textarea.addEventListener('blur', () => {
        window.clearTimeout(answerSaveTimers.get(textarea.dataset.questionId));
        saveQuestion(textarea.dataset.questionId);
      });
    });
    form.querySelectorAll('[data-flag-button]').forEach((button) => button.addEventListener('click', () => {
      const flagged = button.getAttribute('aria-pressed') !== 'true';
      button.setAttribute('aria-pressed', flagged ? 'true' : 'false');
      button.textContent = flagged ? 'Unflag question' : 'Flag for review';
      const flagValue = form.querySelector(`[data-flag-value][data-question-id="${button.dataset.questionId}"]`);
      if (flagValue) flagValue.value = flagged ? '1' : '0';
      updateNavigation();
      saveQuestion(button.dataset.questionId);
    }));
    restorePendingDrafts();
    window.addEventListener('offline', updateConnectivity);
    window.addEventListener('online', () => {
      updateConnectivity();
      retryPendingSaves();
      if (deferredSubmit && submitButton) {
        deferredSubmit = false;
        form.requestSubmit(submitButton);
      }
    });
    document.addEventListener('visibilitychange', () => {
      if (!focusWarning) return;
      if (document.hidden) {
        flaggedPageSwitch = true;
        focusWarning.hidden = false;
        focusWarning.textContent = 'Exam page left the foreground. Please return to the exam; this browser cannot block other apps.';
      } else if (flaggedPageSwitch) {
        focusWarning.hidden = false;
        focusWarning.textContent = 'You switched away from the exam. Your server-saved answers remain available.';
        retryPendingSaves();
      }
    });
    fullscreenButton?.addEventListener('click', async () => {
      try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await document.documentElement.requestFullscreen();
      } catch {
        if (focusWarning) {
          focusWarning.hidden = false;
          focusWarning.textContent = 'Fullscreen is not available in this browser. Continue the exam in the normal page.';
        }
      }
    });
    document.addEventListener('fullscreenchange', () => {
      if (fullscreenButton) fullscreenButton.textContent = document.fullscreenElement ? 'Exit fullscreen' : 'Optional fullscreen';
    });
    updateConnectivity();
    window.setInterval(() => {
      if (navigator.onLine && Object.keys(pendingDrafts).length) retryPendingSaves();
    }, 5000);
    showQuestion(0);
  }
});

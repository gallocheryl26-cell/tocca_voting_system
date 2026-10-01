(function () {
  'use strict';
  const modal = document.getElementById('voteTutorialModal');
  if (!modal) return;
  const byId = (id) => document.getElementById(id);
  const frame = byId('voteGuideFrame');
  const makeStep = (screen, title, description, location) => ({ screen, title, description, location });
  const flows = {
    main: [
      makeStep('categories', 'Choose a category', 'Select one of the category cards. The Categories, Vote, and Summary indicator follows your progress through the ballot.', 'Categories'),
      makeStep('answer', 'Choose your answer', 'Open the dropdown and choose your preferred entry. Some awards ask for a product, vendor, or typed answer instead; complete the fields shown for that award.', 'Categories → Vote'),
      makeStep('proof', 'A photo is optional', 'Use Add photo if you have proof of purchase. You can still vote without a photo. The preview does not upload files.', 'Vote → Proof of purchase'),
      makeStep('next-award', 'Move through the awards', 'Next opens the next award; Previous lets you check an earlier answer. Moving between awards saves your selections as a draft, rather than casting your votes.', 'Vote → Next / Previous'),
      makeStep('list', 'Use list view if you prefer', 'Change to List View shows several awards per page. Use Next and Previous to move between pages, or switch back to Single Item View.', 'Vote → List view'),
      makeStep('review', 'Open the summary at the end', 'Review summary appears on the last award or the final list page. It saves your current selections and opens the Summary screen.', 'Vote → Review summary'),
      makeStep('summary', 'Check your selections', 'Expand a category to review each answer. Edit returns you to that award while it is still uncast. Unanswered awards stay available for later.', 'Summary → Review / Edit'),
      makeStep('cast', 'Cast one vote or several', 'Cast vote submits one answered award. Vote All submits every answered award that is ready; it does not submit unanswered awards.', 'Summary → Cast vote / Vote All'),
      makeStep('confirm', 'Confirm before submitting', 'Check the confirmation carefully. Cast all records the ready selections; Not yet returns you to the summary. Votes that have been cast cannot be changed.', 'Summary → Cast all ready votes?'),
      makeStep('voted', 'Check the Voted status', 'Submitted awards show Voted. Return to Categories to answer any remaining awards, and sign out when you are finished, especially on a shared device.', 'Summary → Voted'),
    ],
    qr: [
      makeStep('qr-business', 'Check the business page', 'The printed store QR opens the page for that business. After Google sign-in, its name and eligible awards appear here.', 'Store QR → Business awards'),
      makeStep('qr-vote', 'Vote for an award', 'Tap Vote beside an award to choose this business for that title. Vote all is also available for the remaining awards on this business page.', 'Business awards → Vote / Vote all'),
      makeStep('qr-confirm', 'Confirm the business and award', 'Check the business name and award in the confirmation, then choose Cast vote. Cancel returns to the awards. A cast vote cannot be changed.', 'Business awards → Confirm vote'),
      makeStep('qr-voted', 'See what is complete', 'Voted marks each submitted award, and the progress count updates. When every award for this business is cast, the page shows All done here.', 'Business awards → Progress'),
      makeStep('qr-main', 'Continue with other categories', 'Main voting site takes you to the wider ballot for other businesses and categories. Continue with the same Google account. The printed store QR stays the same.', 'Business awards → Main voting site'),
    ],
  };
  let flow = modal.dataset.defaultFlow === 'qr' ? 'qr' : 'main';
  let index = 0;
  let playing = false;
  let framePlaying = false;
  let timer = null;
  let ready = false;
  let token = '';
  let returnModal = null;
  let opener = null;
  const steps = () => flows[flow];
  const speechSupported = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  const speech = speechSupported ? window.speechSynthesis : null;
  let soundEnabled = false;
  let narration = null;
  let narrationFinished = false;
  let speechGeneration = 0;
  let speechTimeout = null;
  let speechNotice = '';
  let voices = [];
  let voiceRefreshTimer = null;
  const voiceLanguage = voice => String(voice.lang || '').replace(/_/g, '-').replace(/^eng(?=-|$)/i, 'en');

  function updateSpeechControls() {
    const sound = byId('voteGuideSound');
    sound.disabled = !speechSupported;
    sound.setAttribute('aria-pressed', String(soundEnabled));
    sound.setAttribute('aria-label', soundEnabled ? 'Turn tutorial narration off' : 'Read tutorial steps aloud');
    sound.innerHTML = `<i class="fa-solid ${soundEnabled ? 'fa-volume-high' : 'fa-volume-xmark'}" aria-hidden="true"></i> <span>${soundEnabled ? 'Sound on' : 'Read aloud'}</span>`;
    byId('voteGuideVoice').disabled = !speechSupported || voices.length === 0;
    byId('voteGuideReadAgain').disabled = !speechSupported || !soundEnabled || !ready;
    byId('voteGuideSpeechStatus').textContent = !speechSupported
      ? 'Read aloud is unavailable in this browser.'
      : speechNotice || (soundEnabled ? voices.length ? 'Read aloud is on. You can change the voice.' : 'English narration is on.' : 'Turn on sound to hear each step.');
  }
  function loadVoices() {
    if (!speechSupported) { updateSpeechControls(); return; }
    const selected = byId('voteGuideVoice').value;
    let available = [];
    try { available = speech.getVoices() || []; } catch (error) { /* The engine may still be starting. */ }
    voices = available.filter(voice => /^en(?:-|$)/i.test(voiceLanguage(voice)));
    if (voices.length) { clearTimeout(voiceRefreshTimer); voiceRefreshTimer = null; }
    const options = [new Option('English (automatic)', '')];
    voices.forEach(voice => options.push(new Option(`${voice.name} (${voice.lang})`, voice.voiceURI)));
    byId('voteGuideVoice').replaceChildren(...options);
    if (voices.some(voice => voice.voiceURI === selected)) byId('voteGuideVoice').value = selected;
    updateSpeechControls();
  }
  function refreshVoices(attempt = 0) {
    clearTimeout(voiceRefreshTimer);
    voiceRefreshTimer = null;
    if (!speechSupported || !modal.classList.contains('show')) return;
    loadVoices();
    // A partial mobile voice list is not proof that English cannot be spoken.
    if (!voices.length && attempt < 3) voiceRefreshTimer = setTimeout(() => refreshVoices(attempt + 1), 1000 * (2 ** attempt));
  }
  function stopNarration() {
    speechGeneration++;
    clearTimeout(speechTimeout);
    speechTimeout = null;
    if (narration) speech.cancel();
    narration = null;
    narrationFinished = false;
    speechNotice = '';
    updateSpeechControls();
  }
  function speechFailed(message) {
    stopNarration();
    soundEnabled = false;
    speechNotice = message;
    updateSpeechControls();
    updatePlayer();
    schedule();
  }
  function readStep() {
    if (!soundEnabled || !ready || narration || narrationFinished || document.hidden || !modal.classList.contains('show')) return;
    const step = steps()[index];
    const generation = speechGeneration;
    const utterance = new SpeechSynthesisUtterance(`Step ${index + 1}. ${step.title}. ${step.description}`);
    const selected = byId('voteGuideVoice').value;
    const voice = voices.find(item => item.voiceURI === selected)
      || voices.find(item => /natural|neural|google/i.test(item.name) && /^en/i.test(item.lang))
      || voices.find(item => item.default)
      || voices.find(item => /^en-US$/i.test(item.lang))
      || voices[0];
    if (voice) utterance.voice = voice;
    // Let the engine resolve English when getVoices() has not listed it yet.
    utterance.lang = voice ? voiceLanguage(voice) : 'en-US';
    utterance.rate = 0.96;
    narration = utterance;
    speechNotice = `Reading step ${index + 1}…`;
    updateSpeechControls();
    utterance.onend = () => {
      if (generation !== speechGeneration || narration !== utterance) return;
      clearTimeout(speechTimeout);
      narration = null;
      narrationFinished = true;
      speechNotice = `Step ${index + 1} finished.`;
      updateSpeechControls();
      schedule();
    };
    utterance.onerror = event => {
      if (generation !== speechGeneration || narration !== utterance) return;
      const message = event?.error === 'not-allowed'
        ? 'Tap Read aloud to enable sound. The guide will keep playing.'
        : ['language-unavailable', 'voice-unavailable'].includes(event?.error)
          ? 'English audio could not start. The guide will keep playing; you can retry Read aloud.'
          : 'The voice could not play. Try Read aloud again or choose another voice.';
      speechFailed(message);
    };
    // A stalled speech engine must never leave the walkthrough stuck on one step.
    speechTimeout = setTimeout(() => {
      if (generation === speechGeneration && narration === utterance) speechFailed('The voice did not finish. You can continue without sound or try Read aloud again.');
    }, 60000);
    try { speech.resume(); speech.speak(utterance); }
    catch (error) { speechFailed('Read aloud could not start. Try another voice or continue without sound.'); }
  }
  function pause() {
    playing = false;
    clearTimeout(timer);
    clearTimeout(voiceRefreshTimer);
    voiceRefreshTimer = null;
    timer = null;
    framePlaying = false;
    stopNarration();
    if (frame.contentWindow) frame.contentWindow.postMessage({ type: 'vote-guide-play', playing: false, token }, location.origin);
    updatePlayer();
  }
  function updatePlayer() {
    const last = index === steps().length - 1;
    const percentage = Math.round(index / (steps().length - 1) * 100);
    byId('voteGuidePrevious').disabled = index === 0;
    byId('voteGuideNext').disabled = last;
    byId('voteGuidePlay').innerHTML = playing ? '<i class="fa-solid fa-pause" aria-hidden="true"></i> <span>Pause</span>' : last ? '<i class="fa-solid fa-rotate-right" aria-hidden="true"></i> <span>Replay guide</span>' : '<i class="fa-solid fa-play" aria-hidden="true"></i> <span>Play guide</span>';
    byId('voteGuidePlay').setAttribute('aria-label', playing ? 'Pause voting tutorial' : last ? 'Replay voting tutorial' : 'Play voting tutorial');
    byId('voteGuidePlaybackStatus').textContent = playing ? soundEnabled ? 'Playing · advances after narration' : 'Playing · a new step every 9 seconds' : last ? 'Guide complete · You are ready to vote' : 'Explore at your own pace · ← Back / → Next';
    byId('voteGuideProgressText').textContent = `${percentage}% · ${index + 1} of ${steps().length}`;
    const timeline = byId('voteGuideTimeline');
    timeline.max = steps().length;
    timeline.value = index + 1;
    timeline.setAttribute('aria-valuetext', `Step ${index + 1}: ${steps()[index].title}`);
    timeline.style.setProperty('--guide-progress', `${percentage}%`);
  }
  function schedule() {
    clearTimeout(timer);
    if (!playing || !ready) return;
    if (!framePlaying) {
      frame.contentWindow.postMessage({ type: 'vote-guide-play', playing: true, token }, location.origin);
      framePlaying = true;
    }
    if (soundEnabled && !narrationFinished) { readStep(); return; }
    timer = setTimeout(() => {
      if (index < steps().length - 1) { index++; render(); }
      else pause();
    }, soundEnabled ? 1500 : 9000);
  }
  function render() {
    clearTimeout(timer);
    stopNarration();
    ready = false;
    framePlaying = false;
    const step = steps()[index];
    token = crypto.randomUUID();
    byId('voteGuideLoading').hidden = false;
    byId('voteGuideLoading').textContent = 'Loading voting screen…';
    byId('voteGuideStepTitle').textContent = step.title;
    byId('voteGuideStepDescription').textContent = step.description;
    byId('voteGuideCounter').textContent = `STEP ${index + 1} OF ${steps().length}`;
    byId('voteGuideLocation').textContent = step.location;
    const list = byId('voteGuideSteps');
    list.replaceChildren(...steps().map((item, position) => {
      const li = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      const number = document.createElement('span');
      number.className = 'vote-guide-step-number';
      number.textContent = position < index ? '✓' : String(position + 1);
      number.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span');
      label.textContent = item.title;
      button.append(number, label);
      if (position < index) button.classList.add('is-complete');
      button.setAttribute('aria-label', `Step ${position + 1}: ${item.title}`);
      if (position === index) button.setAttribute('aria-current', 'step');
      button.addEventListener('click', () => seek(position));
      li.appendChild(button);
      return li;
    }));
    byId('voteGuideStepSelect').replaceChildren(...steps().map((item, position) => new Option(`${position + 1}. ${item.title}`, String(position))));
    byId('voteGuideStepSelect').value = String(index);
    modal.querySelectorAll('[data-guide-flow]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.guideFlow === flow)));
    frame.src = `voting_tutorial_preview.php?screen=${encodeURIComponent(step.screen)}&token=${encodeURIComponent(token)}`;
    updatePlayer();
    updateSpeechControls();
    const selected = list.querySelector('[aria-current]');
    selected?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
  function seek(position, autoplay = playing) {
    index = Math.max(0, Math.min(steps().length - 1, position));
    pause();
    playing = autoplay;
    render();
  }
  byId('voteGuidePrevious').addEventListener('click', () => seek(index - 1));
  byId('voteGuideNext').addEventListener('click', () => seek(index + 1));
  byId('voteGuideRestart').addEventListener('click', () => seek(0, true));
  byId('voteGuideTimeline').addEventListener('input', event => seek(Number(event.target.value) - 1));
  byId('voteGuideStepSelect').addEventListener('change', event => seek(Number(event.target.value)));
  byId('voteGuidePlay').addEventListener('click', () => {
    if (playing) return pause();
    playing = true;
    if (index === steps().length - 1) { index = 0; render(); }
    updatePlayer();
    schedule();
  });
  byId('voteGuideSound').addEventListener('click', () => {
    if (!speechSupported) return;
    refreshVoices();
    clearTimeout(timer);
    stopNarration();
    soundEnabled = !soundEnabled;
    updateSpeechControls();
    updatePlayer();
    if (soundEnabled) readStep();
    schedule();
  });
  const repeatNarration = () => {
    clearTimeout(timer);
    stopNarration();
    readStep();
    schedule();
  };
  byId('voteGuideReadAgain').addEventListener('click', repeatNarration);
  byId('voteGuideVoice').addEventListener('change', () => { if (soundEnabled) repeatNarration(); });
  if (speechSupported) speech.addEventListener('voiceschanged', loadVoices);
  loadVoices();
  modal.querySelectorAll('[data-guide-flow]').forEach(button => button.addEventListener('click', () => {
    flow = button.dataset.guideFlow; seek(0, true);
  }));
  modal.addEventListener('keydown', event => {
    if (event.target.matches('input, textarea, select')) return;
    if (event.key === 'ArrowRight') { event.preventDefault(); seek(index + 1); }
    if (event.key === 'ArrowLeft') { event.preventDefault(); seek(index - 1); }
  });
  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== frame.contentWindow || event.data?.token !== token || !modal.classList.contains('show')) return;
    if (event.data.type === 'vote-guide-ready') {
      if (ready) return;
      ready = true; byId('voteGuideLoading').hidden = true;
      updateSpeechControls(); readStep(); schedule();
    } else if (event.data.type === 'vote-guide-advance') seek(index + 1);
    else if (event.data.type === 'vote-guide-back') seek(index - 1);
    else if (event.data.type === 'vote-guide-close') bootstrap.Modal.getOrCreateInstance(modal).hide();
    else if (event.data.type === 'vote-guide-error') { pause(); byId('voteGuideLoading').textContent = 'The preview could not load. Choose another step or reopen the guide.'; }
  });
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-vote-tutorial-open]');
    if (!trigger || !window.bootstrap?.Modal) return;
    opener = trigger;
    returnModal = document.querySelector('.modal.show');
    const show = () => bootstrap.Modal.getOrCreateInstance(modal).show();
    if (returnModal) {
      returnModal.addEventListener('hidden.bs.modal', show, { once: true });
      bootstrap.Modal.getOrCreateInstance(returnModal).hide();
    } else show();
  });
  modal.addEventListener('shown.bs.modal', () => {
    index = 0;
    playing = true;
    soundEnabled = speechSupported;
    refreshVoices();
    render();
  });
  modal.addEventListener('hide.bs.modal', pause);
  modal.addEventListener('hidden.bs.modal', () => {
    soundEnabled = false;
    updateSpeechControls();
    frame.removeAttribute('src');
    token = '';
    if (returnModal) {
      returnModal.addEventListener('shown.bs.modal', () => opener?.focus(), { once: true });
      bootstrap.Modal.getOrCreateInstance(returnModal).show();
      returnModal = null;
    } else opener?.focus();
  });
  document.addEventListener('visibilitychange', () => { if (document.hidden) pause(); });
  window.addEventListener('pagehide', pause);
})();

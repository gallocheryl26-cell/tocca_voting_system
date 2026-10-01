// Exercise the real player with delayed speech events and a controlled clock.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../e-vote-final-enhanced/js/voting_tutorial.js'), 'utf8');
const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../e-vote-final-enhanced/audio/voter-guide/manifest.json'), 'utf8'));

class Element {
  constructor() {
    this.listeners = new Map(); this.attributes = new Map(); this.children = [];
    this.dataset = {}; this.value = ''; this.textContent = ''; this.disabled = false;
    const classes = new Set();
    this.classList = { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) };
    this.style = { setProperty() {} };
  }
  addEventListener(type, listener) { const all = this.listeners.get(type) || []; all.push(listener); this.listeners.set(type, all); }
  emit(type, event = {}) { (this.listeners.get(type) || []).forEach(listener => listener({ target: this, ...event })); }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name); }
  removeAttribute(name) { this.attributes.delete(name); }
  append(...children) { this.children.push(...children); }
  appendChild(child) { this.append(child); }
  replaceChildren(...children) { this.children = children; }
  querySelector() { return this.children.flatMap(child => child.children).find(child => child.getAttribute('aria-current')); }
  scrollIntoView() {}
}

function player(supported = true, reportedVoices = [{ name: 'English voice', lang: 'en-US', voiceURI: 'english', default: true }, { name: 'Other English voice', lang: 'en-PH', voiceURI: 'other' }], recorded = false) {
  const ids = [...source.matchAll(/byId\('([^']+)'\)/g)].map(match => match[1]);
  const elements = Object.fromEntries([...new Set(ids)].map(id => [id, new Element()]));
  elements.voteGuideNarration.textContent = JSON.stringify(manifest);
  elements.voteGuideVoice.value = recorded ? 'guide' : '';
  const modal = elements.voteTutorialModal = new Element();
  const modes = ['main', 'qr'].map(flow => { const button = new Element(); button.dataset.guideFlow = flow; return button; });
  modal.querySelectorAll = () => modes;
  const messages = [];
  elements.voteGuideFrame.contentWindow = { postMessage: message => messages.push(message) };
  const timers = new Map(); let nextTimer = 0; let token = 0;
  const document = new Element(); document.hidden = false;
  document.getElementById = id => elements[id];
  document.createElement = () => new Element();
  document.querySelector = () => modal.classList.contains('show') ? modal : null;
  const window = new Element(); window.window = window;
  const spoken = []; let cancelled = 0;
  const audioPlays = [];
  if (recorded) {
    window.Audio = function () {};
    Object.assign(elements.voteGuideAudio, {
      currentTime: 0, paused: true,
      play() { this.paused = false; audioPlays.push({ src: this.src, ended: this.onended, error: this.onerror }); },
      pause() { this.paused = true; },
    });
  }
  if (supported) {
    window.SpeechSynthesisUtterance = function (text) { this.text = text; };
    window.speechSynthesis = {
      getVoices: () => reportedVoices,
      speak: utterance => spoken.push(utterance), cancel: () => cancelled++, resume() {}, addEventListener() {},
    };
  }
  Object.assign(window, {
    document, location: { origin: 'https://example.test' },
    crypto: { randomUUID: () => String(++token) },
    Option: function (text, value) { const option = new Element(); option.textContent = text; option.value = value; return option; },
    setTimeout: (callback, delay) => { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id),
  });
  vm.runInNewContext(source, window);
  const click = id => elements[id].emit('click');
  const ready = () => window.emit('message', { origin: window.location.origin, source: elements.voteGuideFrame.contentWindow, data: { type: 'vote-guide-ready', token: new URL(elements.voteGuideFrame.src, window.location.origin).searchParams.get('token') } });
  const fire = delay => {
    const found = [...timers].find(([, timer]) => timer.delay === delay);
    assert.ok(found, `Expected a ${delay}ms timer`);
    timers.delete(found[0]); found[1].callback();
  };
  modal.classList.add('show'); modal.emit('shown.bs.modal'); ready();
  return { elements, modal, modes, window, document, spoken, audioPlays, timers, messages, click, ready, fire, cancelled: () => cancelled, step: () => elements.voteGuideCounter.textContent };
}

let checked = 0;
function check(name, run) { run(); checked++; console.log(`PASS: ${name}`); }
check('opening the guide starts English narration and playback automatically', () => {
  const p = player();
  assert.equal(p.spoken.length, 1); assert.match(p.spoken[0].text, /Let's walk through your ballot together/);
  assert.notEqual(p.spoken[0].text, p.elements.voteGuideStepDescription.textContent);
  assert.equal(p.spoken[0].lang, 'en-US');
  assert.equal(p.elements.voteGuidePlay.getAttribute('aria-label'), 'Pause voting tutorial');
});
check('playback waits for speech instead of the nine second timer', () => {
  const p = player();
  assert.equal(p.spoken.length, 1); assert.ok(![...p.timers.values()].some(timer => timer.delay === 9000));
  p.spoken[0].onend(); p.fire(1500); assert.equal(p.step(), 'STEP 2 OF 10'); p.ready();
  assert.equal(p.spoken.length, 2); assert.match(p.spoken[1].text, /choose who you'd like to vote for/);
  assert.equal(p.messages.filter(message => message.playing === true).length, 2);
});
check('pause cancels speech and ignores late completion', () => {
  const p = player(); const old = p.spoken[0];
  p.click('voteGuidePlay'); old.onend(); assert.ok(p.cancelled() > 0); assert.equal(p.timers.size, 0);
  assert.equal(p.step(), 'STEP 1 OF 10'); p.click('voteGuidePlay'); assert.equal(p.spoken.length, 2);
});
check('next cancels old speech and reads the new step once', () => {
  const p = player(); const old = p.spoken[0]; p.click('voteGuideNext');
  old.onend(); p.ready(); p.ready(); assert.equal(p.spoken.length, 2);
  assert.match(p.spoken[1].text, /choose who you'd like to vote for/); assert.ok(![...p.timers.values()].some(timer => timer.delay === 1500));
});
check('voice change and read again replace the current narration', () => {
  const p = player(); p.elements.voteGuideVoice.value = 'other'; p.elements.voteGuideVoice.emit('change');
  assert.equal(p.spoken[1].voice.voiceURI, 'other'); p.click('voteGuideReadAgain'); assert.equal(p.spoken.length, 3);
});
check('turning sound off restores timed playback', () => {
  const p = player(); p.click('voteGuideSound');
  assert.equal(p.elements.voteGuideSound.getAttribute('aria-pressed'), 'false'); p.fire(9000); assert.equal(p.step(), 'STEP 2 OF 10');
});
check('voice error and stalled voice fall back without blocking the guide', () => {
  for (const fail of ['error', 'timeout']) {
    const p = player();
    if (fail === 'error') p.spoken[0].onerror(); else p.fire(60000);
    assert.equal(p.elements.voteGuideSound.getAttribute('aria-pressed'), 'false'); p.fire(9000); assert.equal(p.step(), 'STEP 2 OF 10');
  }
});
check('unsupported browsers retain the normal walkthrough', () => {
  const p = player(false); assert.equal(p.elements.voteGuideSound.disabled, true); p.fire(9000);
  assert.equal(p.step(), 'STEP 2 OF 10'); assert.match(p.elements.voteGuideSpeechStatus.textContent, /unavailable/);
});
check('closing, backgrounding and leaving the page stop narration', () => {
  for (const action of ['close', 'background', 'leave']) {
    const p = player(); const old = p.spoken[0];
    if (action === 'close') { p.modal.emit('hide.bs.modal'); p.modal.classList.remove('show'); p.modal.emit('hidden.bs.modal'); }
    if (action === 'background') { p.document.hidden = true; p.document.emit('visibilitychange'); }
    if (action === 'leave') p.window.emit('pagehide');
    old.onend(); assert.ok(p.cancelled() > 0); assert.equal(p.timers.size, 0);
  }
});
check('switching guides resets speech and completion remains a final step', () => {
  const p = player(); p.modes[1].emit('click'); p.ready(); assert.equal(p.step(), 'STEP 1 OF 5');
  p.elements.voteGuideTimeline.value = 5; p.elements.voteGuideTimeline.emit('input'); p.ready(); p.spoken.at(-1).onend(); p.fire(1500);
  assert.equal(p.elements.voteGuidePlay.getAttribute('aria-label'), 'Replay voting tutorial');
  assert.equal(p.elements.voteGuideNext.disabled, true); assert.equal(p.timers.size, 0);
});
check('both voting guides play every step through completion without Next', () => {
  for (const [flow, count] of [['main', 10], ['qr', 5]]) {
    const p = player();
    if (flow === 'qr') { p.modes[1].emit('click'); p.ready(); }
    for (let step = 1; step <= count; step++) {
      assert.equal(p.step(), `STEP ${step} OF ${count}`);
      assert.equal(p.elements.voteGuidePlay.getAttribute('aria-label'), 'Pause voting tutorial');
      const utterance = p.spoken.at(-1);
      assert.match(utterance.lang, /^en/);
      utterance.onend(); p.fire(1500);
      if (step < count) p.ready();
    }
    assert.equal(p.step(), `STEP ${count} OF ${count}`);
    assert.equal(p.elements.voteGuidePlay.getAttribute('aria-label'), 'Replay voting tutorial');
    assert.equal(p.timers.size, 0);
    p.click('voteGuideRestart'); p.ready();
    assert.equal(p.step(), `STEP 1 OF ${count}`);
    assert.equal(p.elements.voteGuidePlay.getAttribute('aria-label'), 'Pause voting tutorial');
  }
});
check('incomplete mobile voice lists still request English and discover a delayed voice', () => {
  for (const initial of [[], [{ name: 'Device default', lang: 'fil-PH', voiceURI: 'default', default: true }]]) {
    const p = player(true, initial);
    assert.equal(p.elements.voteGuideSound.disabled, false);
    assert.equal(p.spoken.length, 1);
    assert.equal(p.spoken[0].lang, 'en-US');
    assert.equal(p.spoken[0].voice, undefined);
    assert.ok(!p.elements.voteGuideVoice.children.some(option => option.value === 'default'));
    p.window.speechSynthesis.getVoices = () => [{ name: 'English', lang: 'en-US', voiceURI: 'delayed' }];
    p.fire(1000);
    assert.equal(p.elements.voteGuideVoice.disabled, false);
    p.spoken[0].onend(); p.fire(1500); p.ready();
    assert.equal(p.spoken[1].voice.voiceURI, 'delayed');
    p.modal.emit('hide.bs.modal'); assert.equal(p.timers.size, 0);
  }
});
check('mobile English locale formats are normalized', () => {
  const p = player(true, [{ name: 'English', lang: 'en_US', voiceURI: 'mobile-english' }]);
  assert.equal(p.spoken[0].voice.voiceURI, 'mobile-english');
  assert.equal(p.spoken[0].lang, 'en-US');
});
check('mobile audio policy and language failures allow retry without stopping autoplay', () => {
  for (const error of ['not-allowed', 'language-unavailable', 'voice-unavailable']) {
    const p = player();
    p.spoken[0].onerror({ error });
    assert.equal(p.elements.voteGuideSound.disabled, false);
    assert.match(p.elements.voteGuideSpeechStatus.textContent, /guide will keep playing/i);
    p.fire(9000); p.ready();
    assert.equal(p.step(), 'STEP 2 OF 10');
    p.click('voteGuideSound');
    assert.equal(p.spoken.at(-1).lang, 'en-US');
    assert.equal(p.elements.voteGuideSound.getAttribute('aria-pressed'), 'true');
  }
});
check('the guide narrator plays on phones without a speech engine or English voices', () => {
  for (const speech of [true, false]) {
    const p = player(speech, [], true);
    assert.equal(p.elements.voteGuideVoice.value, 'guide');
    assert.equal(p.elements.voteGuideSound.disabled, false);
    assert.equal(p.audioPlays.length, 1); assert.equal(p.spoken.length, 0);
    assert.match(p.audioPlays[0].src, /^audio\/voter-guide\/categories-/);
    assert.ok(![...p.timers.values()].some(timer => timer.delay === 9000));
    p.audioPlays[0].ended(); p.fire(1500); p.ready();
    assert.equal(p.step(), 'STEP 2 OF 10'); assert.equal(p.audioPlays.length, 2);
  }
});
check('both recorded guides finish every step without Next and can replay', () => {
  for (const [flow, count] of [['main', 10], ['qr', 5]]) {
    const p = player(false, [], true);
    if (flow === 'qr') { p.modes[1].emit('click'); p.ready(); }
    for (let step = 1; step <= count; step++) {
      assert.equal(p.step(), `STEP ${step} OF ${count}`);
      const screen = new URL(p.elements.voteGuideFrame.src, 'https://example.test').searchParams.get('screen');
      assert.equal(p.audioPlays.at(-1).src, `audio/voter-guide/${manifest.steps[screen].file}`);
      p.audioPlays.at(-1).ended(); p.fire(1500);
      if (step < count) p.ready();
    }
    assert.equal(p.elements.voteGuidePlay.getAttribute('aria-label'), 'Replay voting tutorial');
    assert.equal(p.timers.size, 0);
    p.click('voteGuideRestart'); p.ready(); assert.equal(p.step(), `STEP 1 OF ${count}`);
  }
});
check('pausing, seeking, muting, and closing cancel recordings and ignore old callbacks', () => {
  for (const action of ['pause', 'seek', 'mute', 'close', 'background', 'leave']) {
    const p = player(false, [], true); const old = p.audioPlays[0];
    if (action === 'pause') p.click('voteGuidePlay');
    if (action === 'seek') p.click('voteGuideNext');
    if (action === 'mute') p.click('voteGuideSound');
    if (action === 'close') { p.modal.emit('hide.bs.modal'); p.modal.classList.remove('show'); p.modal.emit('hidden.bs.modal'); }
    if (action === 'background') { p.document.hidden = true; p.document.emit('visibilitychange'); }
    if (action === 'leave') p.window.emit('pagehide');
    assert.equal(p.elements.voteGuideAudio.paused, true);
    old.ended(); old.error();
    assert.ok(![...p.timers.values()].some(timer => timer.delay === 1500));
    if (action === 'seek') { p.ready(); assert.equal(p.audioPlays.length, 2); }
    if (action === 'mute') { p.fire(9000); assert.equal(p.step(), 'STEP 2 OF 10'); }
  }
});
check('missing or stalled audio falls back to the coaching script and retries the next recording', () => {
  for (const fail of ['error', 'timeout']) {
    const p = player(true, undefined, true);
    if (fail === 'error') p.audioPlays[0].error(); else p.fire(60000);
    assert.equal(p.spoken.length, 1);
    assert.equal(p.spoken[0].text, manifest.steps.categories.text);
    assert.equal(p.spoken[0].lang, 'en-US');
    assert.equal(p.elements.voteGuideSound.getAttribute('aria-pressed'), 'true');
    p.spoken[0].onend(); p.fire(1500); p.ready();
    assert.equal(p.audioPlays.length, 2);
  }
  const noSpeech = player(false, [], true);
  noSpeech.audioPlays[0].error(); noSpeech.fire(9000);
  assert.equal(noSpeech.step(), 'STEP 2 OF 10');
});
check('blocked recording playback offers a sound retry without stopping the walkthrough', () => {
  const p = player(false, [], true);
  p.audioPlays[0].error({ name: 'NotAllowedError' });
  assert.match(p.elements.voteGuideSpeechStatus.textContent, /Tap Read aloud/);
  assert.equal(p.elements.voteGuideSound.disabled, false);
  p.fire(9000); p.ready(); p.click('voteGuideSound');
  assert.equal(p.audioPlays.length, 2);
  assert.equal(p.elements.voteGuideSound.getAttribute('aria-pressed'), 'true');
});
check('changing between the guide narrator and a device voice stops the previous source', () => {
  const p = player(true, undefined, true); const old = p.audioPlays[0];
  p.elements.voteGuideVoice.value = 'other'; p.elements.voteGuideVoice.emit('change');
  assert.equal(p.elements.voteGuideAudio.paused, true); assert.equal(p.spoken.length, 1);
  assert.equal(p.spoken[0].voice.voiceURI, 'other');
  old.ended(); assert.ok(![...p.timers.values()].some(timer => timer.delay === 1500));
  const device = p.spoken[0];
  p.elements.voteGuideVoice.value = 'guide'; p.elements.voteGuideVoice.emit('change');
  device.onend(); assert.ok(p.cancelled() > 0); assert.equal(p.audioPlays.length, 2);
  p.click('voteGuideReadAgain'); assert.equal(p.audioPlays.length, 3);
});
console.log(`${checked} tutorial narration and playback checks passed.`);

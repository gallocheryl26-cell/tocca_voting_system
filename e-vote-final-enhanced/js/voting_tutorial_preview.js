/* The real page controllers run against an isolated, in-memory example ballot. */
(function () {
  'use strict';
  const data = window.VOTE_GUIDE_DEMO;
  if (!data) return;
  const screen = data.screen;
  const notify = (type) => parent.postMessage({ type, token: data.token }, location.origin);
  const memory = new Map();
  const storage = {
    getItem: key => memory.has(String(key)) ? memory.get(String(key)) : null,
    setItem: (key, value) => memory.set(String(key), String(value)),
    removeItem: key => memory.delete(String(key)),
    clear: () => memory.clear(),
    key: index => [...memory.keys()][index] ?? null,
    get length() { return memory.size; },
  };
  // Fail closed: real browser storage must never be used by the example controllers.
  Object.defineProperty(window, 'localStorage', { value: storage, configurable: false });
  Object.defineProperty(window, 'sessionStorage', { value: storage, configurable: false });
  const qr = screen.startsWith('qr-');
  const allQuestions = data.questions;
  const questions = qr ? allQuestions.slice(0, 3) : allQuestions;
  const selected = questions.slice(0, 3).map(q => ({ question_id: q.question_id, question_name: q.question_name, answer_fields: q.answer_fields, choice_id: q.choices[0].choice_id, choice_text: q.choices[0].choice_name, freetext: '' }));
  const seeded = screen !== 'answer' && screen !== 'categories';
  const answers = seeded ? { [data.categoryId]: { category_name: data.categories.find(c => c.id === data.categoryId)?.name || 'Food', selections: selected } } : {};
  const final = {};
  if (screen === 'voted' || screen === 'qr-voted' || screen === 'qr-main') selected.forEach(answer => { final[answer.question_id] = true; });
  storage.setItem('voter_id', '900000000');
  storage.setItem('current_event_id', data.eventId);
  storage.setItem('selected_category_id', data.categoryId);
  storage.setItem('allCategoryAnswers', JSON.stringify(answers));
  storage.setItem('finalizedAnswers', JSON.stringify(final));
  storage.setItem('qr_choice_id', '900000001');
  storage.setItem('qr_business', data.choice_name);
  window.toccaVoterUrl = () => '#';
  window.toccaVoterGo = () => notify('vote-guide-advance');
  window.showToast = () => {};
  const url = new URL(location.href);
  url.searchParams.set('category_id', data.categoryId);
  url.searchParams.set('event_id', data.eventId);
  if (screen === 'review' && questions.length) url.searchParams.set('edit_question', questions.at(-1).question_id);
  history.replaceState({}, '', url);
  const response = payload => new Response(JSON.stringify(payload), { headers: { 'Content-Type': 'application/json' } });
  // No original fetch is retained, and the response CSP also blocks connections.
  window.fetch = async (input, options = {}) => {
    const route = new URL(typeof input === 'string' ? input : input.url, location.href);
    const endpoint = route.pathname.split('/').pop();
    const body = typeof options.body === 'string' ? JSON.parse(options.body) : {};
    switch (endpoint) {
      case 'check_voter_session.php': return response({ can_access_ballot: true, voter_id: 900000000 });
      case 'get_all_categories.php': return response({ status: 'success', event_id: data.eventId, categories: ['summary', 'cast', 'confirm', 'voted'].includes(screen) ? data.categories.filter(category => category.id === data.categoryId) : data.categories });
      case 'load_questions_with_choices.php': return response({ status: 'success', questions, field_labels: data.labels });
      case 'load_all_questions.php': return response({ status: 'success', questions });
      case 'load_all_drafts.php': return response([]);
      case 'load_category_draft.php': return response({ status: 'success', selections: seeded ? selected : [] });
      case 'load_existing_votes.php': return response({ status: 'success', answers: [] });
      case 'get_finalized_answers.php': return response({ status: 'success', finalized: Object.keys(final).map(question_id => ({ question_id: Number(question_id) })) });
      case 'get_business_questions.php': return response({ status: 'success', categories: [{ category_id: data.categoryId, category_name: answers[data.categoryId]?.category_name || 'Food', questions }] });
      case 'save_draft.php': return response({ status: 'success', message: 'Example draft kept in memory' });
      case 'submit_vote.php':
        (body.answers || []).forEach(answer => { final[answer.question_id] = true; });
        return response({ status: 'success', writes: (body.answers || []).length, complete: false });
      default: throw new Error('The tutorial cannot call a live endpoint.');
    }
  };
  let timer = null;
  let playing = false;
  let focusBox;
  let pointer;
  let target;
  let scene;
  let configured = false;
  let announced = false;
  const selectors = {
    categories: '.category-card',
    answer: '.question-block .choices', proof: '.vote-proof-section', 'next-award': '#nextBtn',
    list: '#toggleViewBtn', review: '#submitVoteBtn', summary: '.summary-row-actions',
    cast: '#voteAllBtn', confirm: '#voterConfirmOkBtn', voted: '.summary-stat.stat-voted',
    'qr-business': '#businessName', 'qr-vote': '.qr-award-row__btn', 'qr-confirm': '#voterConfirmOkBtn',
    'qr-voted': '#qrCompletePanel', 'qr-main': '#openLegacyBtn',
  };
  function visible(element) { return element && element.getClientRects().length > 0; }
  function unionBounds(elements) {
    const rects = elements.filter(visible).map(element => element.getBoundingClientRect());
    if (!rects.length) return null;
    const left = Math.min(...rects.map(rect => rect.left));
    const top = Math.min(...rects.map(rect => rect.top));
    const right = Math.max(...rects.map(rect => rect.right));
    const bottom = Math.max(...rects.map(rect => rect.bottom));
    return { left, top, width: right - left, height: bottom - top };
  }
  function framingBounds() {
    const find = selector => document.querySelector(selector);
    if (['confirm', 'qr-confirm'].includes(screen)) return unionBounds([target.closest('.modal-dialog')]);
    if (screen === 'categories') return unionBounds([find('#categoryList')]);
    if (screen === 'answer') {
      const card = target.closest('.question-block');
      const rect = card.getBoundingClientRect();
      const dropdown = card.querySelector('.choices__list--dropdown.is-active');
      const bottom = Math.max(target.getBoundingClientRect().bottom, dropdown?.getBoundingClientRect().bottom || 0);
      return { left: rect.left, top: rect.top, width: rect.width, height: bottom - rect.top + 12 };
    }
    if (['next-award', 'list', 'review'].includes(screen)) return unionBounds([find('#controls'), find('#pageControls')]);
    if (screen === 'summary') return unionBounds([target.closest('.summary-row')]);
    if (screen === 'cast') return unionBounds([target.closest('.summary-actions-bar')]);
    if (screen === 'voted') return unionBounds([find('#summaryOverview')]);
    if (screen === 'qr-business') return unionBounds([target.closest('.voter-hero')]);
    if (screen === 'qr-vote') return unionBounds([target.closest('.qr-award-row')]);
    return unionBounds([target]);
  }
  function paint() {
    if (!scene || !target || !visible(target)) return;
    // Frame the real controls as a video scene. The preview never scrolls.
    scene.style.width = `${Math.max(280, innerWidth)}px`;
    scene.style.transform = 'none';
    const region = framingBounds() || unionBounds([target]);
    if (!region || region.width <= 0 || region.height <= 0) return;
    const scale = Math.min(1, (innerWidth - 24) / region.width, (innerHeight - 24) / region.height);
    const left = (innerWidth - region.width * scale) / 2 - region.left * scale;
    const top = (innerHeight - region.height * scale) / 2 - region.top * scale;
    scene.style.transform = `translate(${left}px, ${top}px) scale(${scale})`;
    const bounds = target.getBoundingClientRect();
    focusBox.style.left = `${Math.max(4, bounds.left - 3)}px`;
    focusBox.style.top = `${Math.max(4, bounds.top - 3)}px`;
    focusBox.style.width = `${Math.min(innerWidth - 8, bounds.width + 6)}px`;
    focusBox.style.height = `${Math.min(innerHeight - 8, bounds.height + 6)}px`;
    pointer.style.left = `${Math.min(innerWidth - 31, bounds.right - 8)}px`;
    pointer.style.top = `${Math.min(innerHeight - 36, bounds.bottom - 8)}px`;
  }
  function pulse() {
    if (!target) return;
    const ring = document.createElement('span');
    ring.className = 'guide-demo-click';
    const bounds = target.getBoundingClientRect();
    ring.style.left = `${bounds.left + bounds.width / 2 - 14}px`;
    ring.style.top = `${bounds.top + bounds.height / 2 - 14}px`;
    document.body.appendChild(ring);
    setTimeout(() => ring.remove(), 1100);
  }
  function animateAction() {
    clearTimeout(timer);
    if (!playing || !announced || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    timer = setTimeout(() => {
      pulse();
      if (screen === 'answer') {
        const select = document.querySelector('.question-block select');
        select?.choicesInstance?.showDropdown();
        timer = setTimeout(() => {
          select?.choicesInstance?.setChoiceByValue('900000001');
          select?.choicesInstance?.hideDropdown();
          select?.dispatchEvent(new Event('change'));
        }, 1200);
      } else if (screen === 'next-award') document.getElementById('nextBtn')?.click();
      else if (screen === 'list') document.getElementById('toggleViewBtn')?.click();
    }, 2200);
  }
  function prepare() {
    if (!configured) {
      if (['summary', 'cast', 'confirm', 'voted'].includes(screen)) {
        if (!document.querySelector('#summaryContainer .accordion-button')) return;
        const accordion = document.getElementById(`collapse-${data.categoryId}`);
        if (accordion) bootstrap.Collapse.getOrCreateInstance(accordion, { toggle: false }).show();
        if (screen === 'confirm') document.getElementById('voteAllBtn').click();
        configured = true;
      } else if (screen === 'qr-confirm') {
        const vote = document.querySelector('.qr-award-row__btn');
        if (!vote) return;
        vote.click(); configured = true;
      } else if (document.querySelector(selectors[screen])) configured = true;
    }
    const current = document.querySelector(selectors[screen]);
    if (!visible(current)) return;
    target = current;
    paint();
    document.querySelectorAll('input[type=file]').forEach(input => { input.disabled = true; });
    if (!announced) { announced = true; notify('vote-guide-ready'); animateAction(); }
  }
  window.addEventListener('message', event => {
    if (event.source !== parent || event.origin !== location.origin || event.data?.token !== data.token || event.data.type !== 'vote-guide-play') return;
    playing = event.data.playing;
    clearTimeout(timer);
    if (playing) animateAction();
  });
  document.addEventListener('DOMContentLoaded', () => {
    const camera = document.createElement('div'); camera.className = 'guide-demo-camera';
    scene = document.createElement('div'); scene.className = 'guide-demo-scene';
    // Reuse the actual page and modal nodes; only their camera position changes.
    [...document.body.children].filter(element => element.matches('.voter-shell, .modal')).forEach(element => scene.appendChild(element));
    camera.appendChild(scene); document.body.appendChild(camera);
    focusBox = document.createElement('div'); focusBox.className = 'guide-demo-focus'; focusBox.setAttribute('aria-hidden', 'true');
    pointer = document.createElement('div'); pointer.className = 'guide-demo-pointer'; pointer.setAttribute('aria-hidden', 'true');
    pointer.innerHTML = '<svg viewBox="0 0 30 36"><path d="M3 2v28l7-8 7 12 5-3-7-12 11-1z" fill="white" stroke="#313158" stroke-width="2"/></svg>';
    document.body.append(focusBox, pointer);
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); notify('vote-guide-close'); }
      else if (['PageUp', 'PageDown', 'Home', 'End', 'ArrowUp', 'ArrowDown', ' '].includes(event.key) && !event.target.closest('input, select, textarea, button, [role=listbox]')) event.preventDefault();
      else if (!event.target.closest('input, select, textarea, [role=listbox]')) {
        if (event.key === 'ArrowRight') { event.preventDefault(); notify('vote-guide-advance'); }
        if (event.key === 'ArrowLeft') { event.preventDefault(); notify('vote-guide-back'); }
      }
    }, true);
    document.addEventListener('wheel', event => event.preventDefault(), { passive: false });
    document.addEventListener('touchmove', event => event.preventDefault(), { passive: false });
    // Public preview interactions cannot navigate to a real ballot or open a file picker.
    document.addEventListener('click', event => {
      const link = event.target.closest('a');
      if (link) { event.preventDefault(); event.stopImmediatePropagation(); if (link.textContent.trim() === 'Categories') notify('vote-guide-advance'); }
      if (event.target.closest('.vote-proof-add, input[type=file], #signOutBtn')) { event.preventDefault(); event.stopImmediatePropagation(); }
      if (event.target.closest('#openLegacyBtn')) { event.preventDefault(); event.stopImmediatePropagation(); notify('vote-guide-advance'); }
      if (['confirm', 'qr-confirm'].includes(screen) && event.target.closest('[data-voter-confirm-cancel]')) { event.preventDefault(); event.stopImmediatePropagation(); notify('vote-guide-back'); }
    }, true);
    document.addEventListener('click', event => {
      if (!event.isTrusted) return;
      const button = event.target.closest('button');
      if (button && ((screen === 'next-award' && button.id === 'nextBtn') || (screen === 'list' && button.id === 'toggleViewBtn') || (['confirm', 'qr-confirm'].includes(screen) && button.id === 'voterConfirmOkBtn'))) {
        setTimeout(() => notify('vote-guide-advance'), 600);
      }
    });
    document.addEventListener('change', event => {
      if (screen === 'answer' && event.isTrusted && event.target.matches('.question-block select') && event.target.value) setTimeout(() => notify('vote-guide-advance'), 600);
    });
    const interval = setInterval(prepare, 200);
    window.addEventListener('pagehide', () => { clearInterval(interval); clearTimeout(timer); }, { once: true });
    window.addEventListener('resize', paint);
    window.addEventListener('scroll', paint, { passive: true });
    document.addEventListener('scroll', paint, { passive: true, capture: true });
    setTimeout(() => { if (!announced) notify('vote-guide-error'); }, 12000);
  });
})();

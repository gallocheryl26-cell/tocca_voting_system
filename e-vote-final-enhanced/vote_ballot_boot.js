/**
 * Classic (non-module) ballot loader.
 * Facebook in-app and some WebViews skip or fail ES modules, which leaves
 * "Loading award titles…" forever. This fetch+render path does not use import.
 */
(function () {
  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function stillWaiting() {
    return !!byId('initialMessage');
  }

  function categoryIdFromPage() {
    try {
      return new URLSearchParams(window.location.search).get('category_id') || '';
    } catch (e) {
      return '';
    }
  }

  function isOpenText(question) {
    var fields = String((question && question.answer_fields) || '').toLowerCase();
    if (fields === 'song_singer' || fields === 'product_business') return true;
    return String((question && question.answer_mode) || '') === 'open_text';
  }

  function labelsFor(question) {
    var L = (question && question.field_labels) || {};
    return {
      title: L.open_label || 'Product Name',
      titlePh: L.open_placeholder || 'Type the product name',
      second: L.open_label_2 || 'Business Name',
      secondPh: L.open_placeholder_2 || 'Type the business name',
      instruction: L.list_instruction || 'Type both answers.',
      singleField: L.single_field === true,
    };
  }

  function isPlaceAwardName(name) {
    var n = String(name || '').toLowerCase();
    return n.indexOf('date place') !== -1
      || n.indexOf('hangout spot') !== -1
      || n.indexOf('viewing spot') !== -1
      || n.indexOf('tourist spot') !== -1;
  }

  function isSingleOpenField(question) {
    var L = (question && question.field_labels) || {};
    if (L.single_field === true) return true;
    return isPlaceAwardName(question && question.question_name);
  }

  var questions = [];
  var index = 0;
  var answers = {};

  function readPair(block) {
    var titleEl = block.querySelector('.open-text-title');
    var singerEl = block.querySelector('.open-text-singer');
    var title = titleEl ? String(titleEl.value || '').trim() : '';
    if (!singerEl) return title;
    var singer = String(singerEl.value || '').trim();
    if (!title && !singer) return '';
    if (!singer) return title;
    if (!title) return singer;
    return title + ' — ' + singer;
  }

  function saveVisible() {
    var block = document.querySelector('#questionsContainer .question-block');
    if (!block) return;
    var qid = block.getAttribute('data-question-id');
    var select = block.querySelector('select');
    var pair = readPair(block);
    answers[qid] = {
      choice_id: select && select.value ? select.value : null,
      freetext: pair,
      choice_text: pair,
    };
  }

  function renderOne() {
    var box = byId('questionsContainer');
    if (!box || !questions.length) return;
    if (index < 0) index = 0;
    if (index >= questions.length) index = questions.length - 1;
    var question = questions[index];
    var qid = String(question.question_id);
    var stored = answers[qid] || {};
    var html = '<div class="voter-question-card question-block mb-4" data-question-id="' +
      escapeHtml(qid) + '" data-original-index="' + index + '">';
    html += '<p class="text-muted small text-center mb-2">Award ' + (index + 1) + ' of ' + questions.length + '</p>';
    html += '<label class="form-label fw-bold">' + escapeHtml(question.question_name || '') + '</label>';

    if (isOpenText(question)) {
      var L = labelsFor(question);
      var single = isSingleOpenField(question);
      var storedText = String(stored.freetext || stored.choice_text || '');
      var titleVal;
      var singerVal;
      if (single) {
        titleVal = escapeHtml(storedText);
        singerVal = '';
      } else {
        var parts = storedText.split(' — ');
        titleVal = escapeHtml(parts[0] || '');
        singerVal = escapeHtml(parts.length > 1 ? parts.slice(1).join(' — ') : '');
      }
      html += '<label class="form-label mt-2 fw-normal text-primary d-block">' + escapeHtml(L.instruction) + '</label>';
      html += '<div class="open-text-pair' + (single ? ' open-text-pair--single' : '') + '">';
      html += '<div class="open-text-field"><label class="form-label small text-muted mb-1">' + escapeHtml(L.title) +
        '</label><input type="text" class="form-control open-text-title" maxlength="180" autocomplete="off" placeholder="' +
        escapeHtml(L.titlePh) + '" value="' + titleVal + '"></div>';
      if (!single) {
        html += '<div class="open-text-field"><label class="form-label small text-muted mb-1">' + escapeHtml(L.second) +
          '</label><input type="text" class="form-control open-text-singer" maxlength="180" autocomplete="off" placeholder="' +
          escapeHtml(L.secondPh) + '" value="' + singerVal + '"></div>';
      }
      html += '</div>';
    } else {
      var choices = question.choices || [];
      html += '<label class="form-label mt-2 fw-normal text-primary d-block">Pick from the list. A photo is optional.</label>';
      html += '<select class="form-select mb-2" aria-label="Answer">';
      html += '<option value="">Choose a business…</option>';
      for (var i = 0; i < choices.length; i++) {
        var c = choices[i];
        var selected = String(stored.choice_id || '') === String(c.choice_id) ? ' selected' : '';
        html += '<option value="' + escapeHtml(c.choice_id) + '"' + selected + '>' +
          escapeHtml(c.choice_name || '') + '</option>';
      }
      html += '</select>';
    }
    html += '</div>';
    box.innerHTML = html;

    var prevBtn = byId('prevBtn');
    var nextBtn = byId('nextBtn');
    var pageControls = byId('pageControls');
    var controls = byId('controls');
    if (pageControls) pageControls.classList.remove('d-none');
    if (controls) controls.classList.remove('d-none');
    if (prevBtn) {
      prevBtn.disabled = index === 0;
      prevBtn.classList.remove('d-none');
    }
    if (nextBtn) {
      nextBtn.disabled = index === questions.length - 1;
      nextBtn.classList.remove('d-none');
    }
    var search = byId('questionSearchInput');
    if (search) search.disabled = false;
  }

  function persistLocal() {
    saveVisible();
    var catId = categoryIdFromPage();
    var titleEl = byId('selectedCategoryTitle');
    var catName = titleEl ? String(titleEl.textContent || '').trim() : '';
    var selections = [];
    for (var i = 0; i < questions.length; i++) {
      var q = questions[i];
      var stored = answers[String(q.question_id)] || {};
      selections.push({
        question_id: q.question_id,
        question_name: q.question_name,
        answer_fields: q.answer_fields || '',
        choice_id: stored.choice_id || null,
        freetext: stored.freetext || '',
        choice_text: stored.choice_text || stored.freetext || '',
      });
    }
    try {
      var all = {};
      try {
        all = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}') || {};
      } catch (e1) {
        all = {};
      }
      all[catId] = { category_name: catName, selections: selections };
      localStorage.setItem('allCategoryAnswers', JSON.stringify(all));
      localStorage.setItem('temp_vote_answers', JSON.stringify(all));
      localStorage.setItem('selected_category_id', catId);
    } catch (e2) {}
    return {
      category_id: parseInt(catId, 10),
      category_name: catName,
      voter_id: (function () {
        try { return localStorage.getItem('voter_id'); } catch (e) { return null; }
      })(),
      selections: selections,
    };
  }

  var awardNavBusy = false;
  var proceedBusy = false;

  function goPrev() {
    if (awardNavBusy) return;
    awardNavBusy = true;
    saveVisible();
    if (index > 0) {
      index -= 1;
      renderOne();
    }
    setTimeout(function () { awardNavBusy = false; }, 350);
  }

  function goNext() {
    if (awardNavBusy) return;
    awardNavBusy = true;
    saveVisible();
    if (index < questions.length - 1) {
      index += 1;
      renderOne();
    }
    setTimeout(function () { awardNavBusy = false; }, 350);
  }

  function proceed() {
    if (proceedBusy) return;
    proceedBusy = true;
    var submitBtn = byId('submitVoteBtn');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.setAttribute('aria-busy', 'true');
    }
    var payload = persistLocal();
    try {
      fetch('save_draft.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }).catch(function () {
        proceedBusy = false;
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.removeAttribute('aria-busy');
        }
      });
    } catch (e) {
      proceedBusy = false;
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.removeAttribute('aria-busy');
      }
    }
    window.location.href = 'summarypoll.php';
  }

  function bindOnce() {
    var prevBtn = byId('prevBtn');
    var nextBtn = byId('nextBtn');
    var submitBtn = byId('submitVoteBtn');
    if (prevBtn && !prevBtn.getAttribute('data-boot-bound')) {
      prevBtn.setAttribute('data-boot-bound', '1');
      prevBtn.addEventListener('click', function (ev) {
        if (!window.__voteModuleActive) {
          ev.preventDefault();
          goPrev();
        }
      });
    }
    if (nextBtn && !nextBtn.getAttribute('data-boot-bound')) {
      nextBtn.setAttribute('data-boot-bound', '1');
      nextBtn.addEventListener('click', function (ev) {
        if (!window.__voteModuleActive) {
          ev.preventDefault();
          goNext();
        }
      });
    }
    if (submitBtn && !submitBtn.getAttribute('data-boot-bound')) {
      submitBtn.setAttribute('data-boot-bound', '1');
      submitBtn.addEventListener('click', function (ev) {
        if (!window.__voteModuleActive) {
          ev.preventDefault();
          proceed();
        }
      });
    }
  }

  function paint(data) {
    if (!stillWaiting()) return;
    if (!data || data.status !== 'success' || !data.questions || !data.questions.length) {
      var box = byId('questionsContainer');
      if (box) {
        box.innerHTML = '<p class="text-info text-center mt-4">No award titles available for this category.</p>';
      }
      return;
    }
    questions = data.questions;
    index = 0;
    window.__voteModuleActive = false;
    bindOnce();
    renderOne();
    var titleEl = byId('selectedCategoryTitle');
    if (titleEl && data.field_labels && data.field_labels.profile) {
      /* keep server category title */
    }
  }

  var catId = categoryIdFromPage();
  if (!catId) return;

  fetch('load_questions_with_choices.php?category_id=' + encodeURIComponent(catId), {
    credentials: 'same-origin',
  })
    .then(function (res) { return res.json(); })
    .then(paint)
    .catch(function () {
      if (!stillWaiting()) return;
      var box = byId('questionsContainer');
      if (box) {
        box.innerHTML = '<p class="alert alert-danger text-center mt-4">Could not load award titles. Please refresh the page.</p>';
      }
    });
})();

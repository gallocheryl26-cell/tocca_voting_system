(function () {
  'use strict';

  // ---- tiny helpers ---------------------------------------------------------
  function val(el){ return el ? String(el.value || '').trim() : ''; }
  function on(el,evt,fn){ if(el) el.addEventListener(evt,fn,false); }
  function esc(s){
    s = (s == null ? '' : String(s));
    return s.replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
    });
  }
  function cleanText(v){ return String(v == null ? '' : v).replace(/\s+/g,' ').trim(); }
  function hasDT(){ return !!(window.jQuery && jQuery.fn && jQuery.fn.DataTable); }

  // ---- DOM refs -------------------------------------------------------------
  var frm        = document.getElementById('frmFilters');
  var ddlStatus  = document.getElementById('status_filter'); // blank = all
  var ddlCat     = document.getElementById('category_id');
  var ddlAwd     = document.getElementById('question_id');
  var rowCount   = document.getElementById('rowCount');

  // Download modal elements
  var btnConfirmDownload = document.getElementById('confirmDownloadResults');
  var ddlScope           = document.getElementById('downloadScope');   // 'all' | 'current'
  var ddlFormat          = document.getElementById('downloadFormat');  // 'csv' | 'excel' | 'pdf'
  var ddlDownloadStatus  = document.getElementById('downloadStatus');
  var downloadScopeWrap  = document.getElementById('downloadScopeCurrentWrap');
  var ddlDownloadCat     = document.getElementById('download_category_id');
  var ddlDownloadAwd     = document.getElementById('download_question_id');
  var downloadScopeFeedback = document.getElementById('downloadScopeFeedback');
  var downloadModalEl    = document.getElementById('downloadResultsModal');
  var credentialsModalEl = document.getElementById('exportCredentialsModal');
  var credentialsBodyEl  = document.getElementById('exportCredentialsBody');
  var btnConfirmExportWithCredentials = document.getElementById('confirmExportWithCredentials');

  var pendingDownloadParams = null;
  var activeEventId = window.TOCCA_NOMINATION_REPORT_EVENT_ID || null;

  var $tbl = (window.jQuery ? jQuery('#tblEstabs') : null);
  var dt   = null;

  // ---- toast ---------------------------------------------------------------
  function toast(msg, ok){
    if (ok === void 0) ok = false;
    var el = document.getElementById('toastMsg');
    var body = document.getElementById('toastBody');
    if (!el || !body) { try { alert(msg); } catch(_) {} return; }
    el.classList.remove('text-bg-success','text-bg-danger');
    el.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
    body.textContent = String(msg || '');
    new bootstrap.Toast(el).show();
  }

  // ---- empty message --------------------------------------------------------
  function missingSelectionMessage(){
    if (!activeEventId) {
      return 'No active event. Activate an event under File Maintenance → Events.';
    }
    return 'No registrations found for this event with the selected filters.';
  }

  function requireActiveEvent(){
    if (activeEventId) return true;
    toast('No active event. Activate an event under File Maintenance → Events.', false);
    return false;
  }

  function reportQueryParams(extra){
    var params = new URLSearchParams(extra || {});
    if (activeEventId) {
      params.set('event_id', String(activeEventId));
    }
    return params;
  }

  function buildEmptyDT(){
    if (!hasDT() || !$tbl) { toast('DataTables not loaded. Check script order.', false); return; }
    if (dt) { dt.destroy(); $tbl.find('tbody').empty(); }
    dt = $tbl.DataTable({
      data: [],
      columns: [
        { title:'Business' },
        { title:'Email' },
        { title:'Status', width:'180px' }
      ],
      language: { emptyTable: missingSelectionMessage() },
      autoWidth: false,
      searching: false,
      paging: false,
      info: false
    });
    if (rowCount) rowCount.textContent = '0 rows';
  }

  // ---- fetch wrapper --------------------------------------------------------
  function fetchJSON(url){
    return fetch(url, { cache:'no-store' })
      .then(function(r){ return r.text(); })
      .then(function(t){
        var j;
        try { j = JSON.parse(t); }
        catch (e) {
          console.error('Non-JSON response:', t);
          throw new Error('Server returned non-JSON. Open Network tab for details.');
        }
        if (j.status !== 'success') throw new Error(j.message || 'Request failed');
        return j;
      });
  }

  // ---- load Categories (active event only) ---------------------------------
  function loadCategories(){
    if (!ddlCat) return;
    if (!requireActiveEvent()) {
      buildEmptyDT();
      return;
    }
    ddlCat.innerHTML = '<option value="">All categories</option>';
    ddlCat.disabled = true;

    if (ddlAwd) {
      ddlAwd.innerHTML = '<option value="">All awards</option>';
      ddlAwd.disabled = true;
    }

    fetchJSON('nomination_report.php?action=categories&' + reportQueryParams().toString())
    .then(function(j){
      ddlCat.innerHTML = '<option value="">All categories</option>' +
        (j.rows || []).map(function(r){
          return '<option value="'+r.category_id+'">'+esc(r.category_name)+'</option>';
        }).join('');
      ddlCat.disabled = false;
      ddlAwd.disabled = false; // allow All awards by default
    })
    .catch(function(){
      ddlCat.innerHTML = '<option value="">(failed)</option>';
      ddlCat.disabled = false;
      toast('Failed to load categories', false);
    })
    .finally(function(){
      buildEmptyDT();
    });
  }

  // ---- load Awards by Category (active event only) ---------------------------
  function loadAwards(){
    if (!ddlCat || !ddlAwd) return;
    if (!requireActiveEvent()) {
      buildEmptyDT();
      return;
    }
    var category_id = val(ddlCat);
    ddlAwd.innerHTML = '<option value="">All awards</option>';
    ddlAwd.disabled = true;

    if (!category_id) {
      ddlAwd.disabled = false; // keep "All awards" selectable
      buildEmptyDT();
      return;
    }

    var url = 'nomination_report.php?action=awards&'
            + reportQueryParams({ category_id: category_id }).toString();

    fetchJSON(url)
    .then(function(j){
      ddlAwd.innerHTML = '<option value="">All awards</option>' +
        (j.rows || []).map(function(r){
          return '<option value="'+r.question_id+'">'+esc(r.award_name)+'</option>';
        }).join('');
      ddlAwd.disabled = false;
    })
    .catch(function(){
      ddlAwd.innerHTML = '<option value="">(failed)</option>';
      ddlAwd.disabled = false;
      toast('Failed to load awards', false);
    })
    .finally(function(){
      buildEmptyDT();
    });
  }

  // ---- status badge renderer ------------------------------------------------
  function statusBadge(s){
    s = String(s || '').toLowerCase();
    var label = s.replace('_',' ').replace(/\b\w/g, function(m){ return m.toUpperCase(); });
    var clsMap = {
      pending:     'secondary',
      in_review:   'info',
      needs_info:  'warning',
      approved:    'success',
      rejected:    'danger'
    };
    var cls = clsMap[s] || 'secondary';
    return '<span class="badge text-bg-'+cls+'">'+esc(label || 'Unknown')+'</span>';
  }

  // ---- load table data (active event only) ----------------------------------
  function loadEstablishments(e){
    if (e && e.preventDefault) e.preventDefault();
    if (!hasDT() || !$tbl) { toast('DataTables not loaded. Check script order.', false); return; }
    if (!requireActiveEvent()) {
      buildEmptyDT();
      return;
    }

    var params = reportQueryParams({
      status:      val(ddlStatus) || '',
      category_id: val(ddlCat)    || '',
      question_id: val(ddlAwd)    || ''
    });

    var url = 'nomination_report.php?action=establishments&' + params.toString();

    fetchJSON(url)
      .then(function(j){
        var rows = j.rows || [];
        if (dt) { dt.destroy(); $tbl.find('tbody').empty(); }
        dt = $tbl.DataTable({
          data: rows.map(function(r){
            // Expected fields:
            // r.establishment (or r.choice_name), r.email, r.status
            return [
              esc(r.establishment || r.choice_name || ''),
              esc(r.email || ''),
              statusBadge(r.status || '')
            ];
          }),
          columns: [
            { title:'Business' },
            { title:'Email' },
            { title:'Status', width:'180px' }
          ],
          autoWidth: false,
          pageLength: 25,
          order: [[0,'asc']],
          language: { emptyTable: missingSelectionMessage() }
        });
        if (rowCount) rowCount.textContent = rows.length + ' rows';
      })
      .catch(function(err){
        console.error(err);
        toast('Failed to load businesses', false);
        buildEmptyDT();
      });
  }

  // ---- Downloading ----------------------------------------------------------
  function clearDownloadScopeValidation() {
    if (ddlDownloadCat) ddlDownloadCat.classList.remove('is-invalid');
    if (ddlDownloadAwd) ddlDownloadAwd.classList.remove('is-invalid');
    if (downloadScopeFeedback) {
      downloadScopeFeedback.textContent = '';
      downloadScopeFeedback.classList.remove('text-danger');
    }
  }

  function syncDownloadScopeUI() {
    var scope = val(ddlScope) || 'all';
    var showCurrent = scope === 'current';
    if (downloadScopeWrap) {
      downloadScopeWrap.classList.toggle('d-none', !showCurrent);
    }
    if (!showCurrent) {
      clearDownloadScopeValidation();
    }
  }

  function loadDownloadCategories() {
    if (!ddlDownloadCat) return Promise.resolve();
    if (!requireActiveEvent()) return Promise.resolve();
    ddlDownloadCat.innerHTML = '<option value="">Select category…</option>';
    ddlDownloadCat.disabled = true;
    if (ddlDownloadAwd) {
      ddlDownloadAwd.innerHTML = '<option value="">Select award…</option>';
      ddlDownloadAwd.disabled = true;
    }
    return fetchJSON('nomination_report.php?action=categories&' + reportQueryParams().toString())
      .then(function (j) {
        ddlDownloadCat.innerHTML = '<option value="">Select category…</option>' +
          (j.rows || []).map(function (r) {
            return '<option value="' + r.category_id + '">' + esc(r.category_name) + '</option>';
          }).join('');
        ddlDownloadCat.disabled = false;
      })
      .catch(function () {
        ddlDownloadCat.innerHTML = '<option value="">(failed to load)</option>';
        ddlDownloadCat.disabled = false;
        toast('Failed to load categories for download.', false);
      });
  }

  function loadDownloadAwards() {
    if (!ddlDownloadCat || !ddlDownloadAwd) return Promise.resolve();
    var category_id = val(ddlDownloadCat);
    ddlDownloadAwd.innerHTML = '<option value="">Select award…</option>';
    ddlDownloadAwd.disabled = true;
    if (!category_id) {
      return Promise.resolve();
    }
    var url = 'nomination_report.php?action=awards&'
      + reportQueryParams({ category_id: category_id }).toString();
    return fetchJSON(url)
      .then(function (j) {
        ddlDownloadAwd.innerHTML = '<option value="">Select award…</option>' +
          (j.rows || []).map(function (r) {
            return '<option value="' + r.question_id + '">' + esc(r.award_name) + '</option>';
          }).join('');
        ddlDownloadAwd.disabled = false;
      })
      .catch(function () {
        ddlDownloadAwd.innerHTML = '<option value="">(failed to load)</option>';
        ddlDownloadAwd.disabled = false;
        toast('Failed to load awards for download.', false);
      });
  }

  function syncDownloadModalFromPageFilters() {
    if (!ddlDownloadCat || !ddlDownloadAwd) return;
    if (val(ddlCat)) {
      ddlDownloadCat.value = val(ddlCat);
      return loadDownloadAwards().then(function () {
        if (val(ddlAwd)) {
          ddlDownloadAwd.value = val(ddlAwd);
        }
      });
    }
  }

  function getDownloadFilterValues() {
    var scope = val(ddlScope) || 'all';
    if (scope === 'current') {
      return {
        scope: scope,
        category_id: val(ddlDownloadCat) || '',
        question_id: val(ddlDownloadAwd) || ''
      };
    }
    return {
      scope: scope,
      category_id: '',
      question_id: ''
    };
  }

  function validateDownloadScope() {
    clearDownloadScopeValidation();
    var filters = getDownloadFilterValues();
    if (filters.scope !== 'current') {
      return { ok: true, filters: filters };
    }
    if (filters.category_id && filters.question_id) {
      return { ok: true, filters: filters };
    }
    if (ddlDownloadCat) ddlDownloadCat.classList.add('is-invalid');
    if (ddlDownloadAwd) ddlDownloadAwd.classList.add('is-invalid');
    if (downloadScopeFeedback) {
      downloadScopeFeedback.textContent = 'Select a category and an award to download.';
      downloadScopeFeedback.classList.add('text-danger');
    }
    return { ok: false, filters: filters };
  }

  function hideDownloadModal() {
    if (!downloadModalEl) return;
    var modal = bootstrap.Modal.getInstance(downloadModalEl) || new bootstrap.Modal(downloadModalEl);
    modal.hide();
  }

  function parseContentDispositionFilename(headerValue) {
    if (!headerValue) return '';
    var match = /filename\*=UTF-8''([^;]+)|filename="([^"]+)"|filename=([^;]+)/i.exec(headerValue);
    var raw = (match && (match[1] || match[2] || match[3])) || '';
    try {
      return decodeURIComponent(raw.replace(/['"]/g, '').trim());
    } catch (_) {
      return raw.replace(/['"]/g, '').trim();
    }
  }

  function defaultExportFilename(format) {
    var stamp = new Date();
    var pad = function (n) { return String(n).padStart(2, '0'); };
    var name = 'TOCCA_RegistrationList_'
      + stamp.getFullYear()
      + pad(stamp.getMonth() + 1)
      + pad(stamp.getDate())
      + '_'
      + pad(stamp.getHours())
      + pad(stamp.getMinutes());
    if (format === 'pdf') return name + '.pdf';
    if (format === 'excel') return name + '.xlsx';
    return name + '.csv';
  }

  function triggerBlobDownload(blob, filename) {
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = filename || 'RegistrationList.dat';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function fetchExportPreflight(params) {
    var preflightParams = new URLSearchParams(params.toString());
    preflightParams.set('preflight', '1');
    return fetch('nominees_download_report.php?' + preflightParams.toString(), {
      cache: 'no-store',
      credentials: 'same-origin'
    })
      .then(function (res) { return res.text(); })
      .then(function (text) {
        var data;
        try { data = JSON.parse(text); }
        catch (e) { throw new Error('Could not read export settings.'); }
        if (data.status !== 'success') {
          throw new Error(data.message || 'Preflight failed');
        }
        return data;
      });
  }

  function triggerFileDownload(params) {
    var url = 'nominees_download_report.php?' + params.toString();
    return fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) {
          return res.text().then(function (t) {
            throw new Error(t || ('Download failed (HTTP ' + res.status + ')'));
          });
        }
        var format = params.get('format') || 'csv';
        var filename = parseContentDispositionFilename(res.headers.get('Content-Disposition'))
          || defaultExportFilename(format);
        return res.blob().then(function (blob) {
          triggerBlobDownload(blob, filename);
          toast('Download started.', true);
        });
      });
  }

  function renderCredentialsModal(credentials) {
    if (!credentialsBodyEl) return;
    var items = (credentials && credentials.items) ? credentials.items : [];
    credentialsBodyEl.innerHTML = items.map(function (item, idx) {
      var inputId = 'exportCredentialPassword' + idx;
      return ''
        + '<div class="mb-3">'
        + '  <label class="form-label fw-semibold" for="' + inputId + '">' + esc(item.label || 'Password') + '</label>'
        + '  <div class="input-group">'
        + '    <input type="text" class="form-control font-monospace" id="' + inputId + '" readonly value="' + esc(item.password || '') + '">'
        + '    <button type="button" class="btn btn-outline-secondary" data-copy-target="' + inputId + '">Copy</button>'
        + '  </div>'
        + (item.hint ? '<small class="text-muted d-block mt-1">' + esc(item.hint) + '</small>' : '')
        + '</div>';
    }).join('');

    credentialsBodyEl.querySelectorAll('[data-copy-target]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = document.getElementById(btn.getAttribute('data-copy-target'));
        if (!target) return;
        target.select();
        target.setSelectionRange(0, target.value.length);
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(target.value).then(function () {
            toast('Password copied.', true);
          }).catch(function () {
            toast('Copied to clipboard selection.', true);
          });
        } else {
          try {
            document.execCommand('copy');
            toast('Password copied.', true);
          } catch (_) {
            toast('Select the password and copy manually.', false);
          }
        }
      });
    });
  }

  function showCredentialsModal(credentials, params) {
    pendingDownloadParams = params;
    renderCredentialsModal(credentials);
    if (!credentialsModalEl) {
      return triggerFileDownload(params);
    }
    var modal = bootstrap.Modal.getInstance(credentialsModalEl) || new bootstrap.Modal(credentialsModalEl);
    modal.show();
    return Promise.resolve();
  }

  function handleDownloadClick() {
    if (!ddlFormat || !ddlScope) return;
    if (!requireActiveEvent()) return;

    var format = val(ddlFormat) || 'csv';
    var validated = validateDownloadScope();
    if (!validated.ok) {
      toast('Please select a category and an award, or choose All Categories.', false);
      return;
    }
    var filters = validated.filters;

    var params = reportQueryParams({
      category_id: filters.category_id,
      question_id: filters.question_id,
      status:      val(ddlDownloadStatus) || '',
      scope:       filters.scope,
      format:      format
    });

    hideDownloadModal();
    btnConfirmDownload.disabled = true;

    fetchExportPreflight(params)
      .then(function (preData) {
        if (preData.credentials && preData.credentials.show) {
          return showCredentialsModal(preData.credentials, params);
        }
        return triggerFileDownload(params);
      })
      .catch(function (err) {
        console.error(err);
        toast(err.message || 'Download failed.', false);
      })
      .finally(function () {
        btnConfirmDownload.disabled = false;
      });
  }

  on(btnConfirmExportWithCredentials, 'click', function () {
    if (!pendingDownloadParams) return;
    var params = pendingDownloadParams;
    pendingDownloadParams = null;
    btnConfirmExportWithCredentials.disabled = true;
    triggerFileDownload(params)
      .then(function () {
        if (credentialsModalEl) {
          var modal = bootstrap.Modal.getInstance(credentialsModalEl);
          if (modal) modal.hide();
        }
      })
      .catch(function (err) {
        console.error(err);
        toast(err.message || 'Download failed.', false);
      })
      .finally(function () {
        btnConfirmExportWithCredentials.disabled = false;
      });
  });

  // ---- Init wiring ----------------------------------------------------------
  if (!window.jQuery) {
    toast('jQuery not loaded. Load jQuery before DataTables and this file.', false);
    return;
  }
  if (!document.getElementById('tblEstabs')) {
    toast('Table #tblEstabs not found on page.', false);
    return;
  }

  on(ddlScope, 'change', function () {
    syncDownloadScopeUI();
    clearDownloadScopeValidation();
    if (val(ddlScope) === 'current') {
      loadDownloadCategories().then(syncDownloadModalFromPageFilters);
    }
  });
  on(ddlDownloadCat, 'change', function () {
    clearDownloadScopeValidation();
    loadDownloadAwards();
  });
  on(ddlDownloadAwd, 'change', clearDownloadScopeValidation);
  if (downloadModalEl) {
    downloadModalEl.addEventListener('shown.bs.modal', function () {
      if (ddlDownloadStatus && ddlStatus) {
        ddlDownloadStatus.value = val(ddlStatus);
      }
      syncDownloadScopeUI();
      loadDownloadCategories().then(function () {
        if (val(ddlScope) === 'current') {
          return syncDownloadModalFromPageFilters();
        }
      });
    });
  }

  // Events
  on(ddlCat, 'change', loadAwards);
  on(frm, 'submit', loadEstablishments);
  on(btnConfirmDownload, 'click', handleDownloadClick);

  // Auto reload when Status changes
  on(ddlStatus, 'change', function(){ loadEstablishments(); });

  // Init: build table, load filters, then load registrations for the active event
  buildEmptyDT();
  if (activeEventId) {
    loadCategories();
    setTimeout(loadEstablishments, 0);
  }
})();

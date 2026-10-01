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
  var ddlFormat          = document.getElementById('downloadFormat');
  var downloadFilterSummary = document.getElementById('downloadFilterSummary');
  var btnOpenDownload = document.querySelector('button[data-bs-target="#downloadResultsModal"]');
  var downloadModalEl    = document.getElementById('downloadResultsModal');
  var credentialsModalEl = document.getElementById('exportCredentialsModal');
  var credentialsBodyEl  = document.getElementById('exportCredentialsBody');
  var btnConfirmExportWithCredentials = document.getElementById('confirmExportWithCredentials');

  var pendingDownloadParams = null;
  var activeEventId = window.TOCCA_NOMINATION_REPORT_EVENT_ID || null;

  var $tbl = (window.jQuery ? jQuery('#tblEstabs') : null);
  var dt   = null;
  var appliedFilters = null;
  var reportRequestId = 0;
  var reportLoading = false;

  function setReportLoading(loading) {
    reportLoading = loading;
    if (btnOpenDownload) btnOpenDownload.disabled = loading || !appliedFilters;
    if (btnConfirmDownload) btnConfirmDownload.disabled = loading || !appliedFilters;
  }

  function selectedLabel(select) {
    return select && select.selectedIndex >= 0 ? cleanText(select.options[select.selectedIndex].textContent) : '';
  }

  function matchingRegistrationIds() {
    return dt ? dt.rows({ search: 'applied', order: 'applied' }).data().toArray().map(function(row){
      return row.nomination_id;
    }) : [];
  }

  function showDownloadFilterSummary() {
    if (!downloadFilterSummary) return;
    if (!appliedFilters || reportLoading) {
      downloadFilterSummary.textContent = 'Wait for the table to finish loading before downloading.';
      return;
    }
    var count = matchingRegistrationIds().length;
    var pairs = [
      ['Status', appliedFilters.status_label],
      ['Category', appliedFilters.category_label],
      ['Award', appliedFilters.award_label],
      ['Search', dt.search() || 'No search text']
    ];
    downloadFilterSummary.innerHTML = '<div class="fw-semibold mb-2">' + count + ' matching registration' + (count === 1 ? '' : 's') + '</div>'
      + '<dl class="row small mb-0">' + pairs.map(function(pair){
        return '<dt class="col-3">' + esc(pair[0]) + '</dt><dd class="col-9 mb-1">' + esc(pair[1]) + '</dd>';
      }).join('') + '</dl>';
  }

  function textCell(value, type) {
    value = cleanText(value);
    return type === 'display' ? (value ? esc(value) : '<span class="text-muted">—</span>') : value;
  }

  function awardCell(awards, type) {
    awards = awards || [];
    var titles = awards.map(function(a){ return cleanText(a.award_title); });
    if (type !== 'display') return titles.join(' ');
    if (!titles.length) return '<span class="text-muted">—</span>';
    if (titles.length === 1 && titles[0].length <= 90) return esc(titles[0]);
    var label = titles.length > 1 ? 'See more (' + titles.length + ' awards)' : 'See full title';
    return '<div class="report-award-preview">' + esc(titles[0]) + '</div>'
      + '<details class="report-award-details"><summary>'
      + '<span class="report-award-more">' + label + '</span>'
      + '<span class="report-award-less">See less</span></summary>'
      + '<ul class="report-award-list">' + awards.map(function(a){
        return '<li>' + esc(a.award_title)
          + '<small class="text-muted d-block">' + esc(a.category_name) + '</small></li>';
      }).join('') + '</ul></details>';
  }

  function reportColumns() {
    return [
      { title: 'Business', data: 'establishment', width: '16%', render: textCell },
      { title: 'Email', data: 'email', width: '15%', render: textCell },
      { title: 'Mobile number', data: 'mobile_number', width: '11%', className: 'report-mobile', render: textCell },
      { title: 'Address', data: 'address', width: '16%', render: textCell },
      { title: 'Category', data: 'categories', width: '13%', render: function(value, type){
        return textCell((value || []).join(', '), type);
      } },
      { title: 'Award titles', data: 'awards', width: '21%', render: awardCell },
      { title: 'Status', data: 'status', width: '8%', className: 'report-status', render: function(value, type){
        return type === 'display' ? statusBadge(value) : value;
      } }
    ];
  }

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
    appliedFilters = null;
    setReportLoading(false);
    if (dt) { dt.destroy(); $tbl.find('tbody').empty(); }
    dt = $tbl.DataTable({
      data: [],
      columns: reportColumns(),
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
    .finally(loadEstablishments);
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

    var requestId = ++reportRequestId;
    var requestedFilters = {
      status: params.get('status'),
      category_id: params.get('category_id'),
      question_id: params.get('question_id'),
      status_label: selectedLabel(ddlStatus),
      category_label: selectedLabel(ddlCat),
      award_label: selectedLabel(ddlAwd)
    };
    var currentSearch = dt ? dt.search() : '';
    setReportLoading(true);

    var url = 'nomination_report.php?action=establishments&' + params.toString();

    fetchJSON(url)
      .then(function(j){
        if (requestId !== reportRequestId) return;
        var rows = j.rows || [];
        if (dt) { dt.destroy(); $tbl.find('tbody').empty(); }
        dt = $tbl.DataTable({
          data: rows,
          columns: reportColumns(),
          autoWidth: false,
          pageLength: 25,
          search: { search: currentSearch },
          order: [[0,'asc']],
          language: { emptyTable: missingSelectionMessage() }
        });
        appliedFilters = requestedFilters;
        if (rowCount) rowCount.textContent = rows.length + ' rows';
      })
      .catch(function(err){
        if (requestId !== reportRequestId) return;
        console.error(err);
        toast('Failed to load businesses', false);
        buildEmptyDT();
      })
      .finally(function(){
        if (requestId === reportRequestId) setReportLoading(false);
      });
  }

  // ---- Downloading ----------------------------------------------------------
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
    return fetch('nominees_download_report.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: preflightParams.toString(),
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
    return fetch('nominees_download_report.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: params.toString(),
      cache: 'no-store',
      credentials: 'same-origin'
    })
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
    if (!ddlFormat || !requireActiveEvent()) return;
    if (!appliedFilters || reportLoading) {
      toast('Wait for the table to finish loading before downloading.', false);
      return;
    }

    var params = reportQueryParams({
      category_id: appliedFilters.category_id,
      question_id: appliedFilters.question_id,
      status: appliedFilters.status,
      search: dt.search(),
      nomination_ids: JSON.stringify(matchingRegistrationIds()),
      scope: 'table',
      format: val(ddlFormat) || 'csv'
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

  if (downloadModalEl) {
    downloadModalEl.addEventListener('show.bs.modal', showDownloadFilterSummary);
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
  }
})();

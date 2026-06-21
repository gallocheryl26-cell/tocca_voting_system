<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

$event_id = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;
$default_event_id = $event_id ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Nomination Feedbacks | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?= $faviconPath ?>">
  <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet"/>
  <link href="css/styles.css" rel="stylesheet"/>
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
  <link href="https://cdn.datatables.net/v/bs5/dt-2.0.7/r-3.0.3/datatables.min.css" rel="stylesheet"/>
  <style>
    /* ===== Feedback table ===== */
    #feedbackTable { vertical-align: middle; }
    #feedbackTable tbody td { padding: 1rem .75rem; }

    .review {
      max-width: 100%;
      word-break: break-word;
      white-space: pre-wrap;
      line-height: 1.5;
      color: inherit;
    }
    .review .by {
      margin-top: .25rem;
      font-size: .8rem;
      color: var(--bs-secondary-color, #6c757d);
    }
    .store-cell .logo-anon {
      background: linear-gradient(135deg, #64748b, #475569) !important;
    }

    .store-cell {
      display: flex;
      align-items: center;
      gap: .65rem;
      min-width: 0;
      font-weight: 500;
    }
    .store-cell .logo,
    .store-cell .logo-fallback {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      flex: 0 0 38px;
      object-fit: cover;
      background: #e2e8f0;
      border: 1px solid var(--bs-border-color, #dee2e6);
      box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
    }
    .store-cell .logo-fallback {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 700;
      font-size: .82rem;
      letter-spacing: .04em;
      text-transform: uppercase;
      background: linear-gradient(135deg, #4f46e5, #2563eb);
    }
    .store-cell > div {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .date-cell {
      white-space: nowrap;
      color: var(--bs-secondary-color, #6c757d);
      font-size: .875rem;
    }

    /* Dark-mode tweaks */
    html.dark-mode .store-cell .logo,
    html.dark-mode .store-cell .logo-fallback {
      border-color: #334155;
      background: #0b1220;
    }
    html.dark-mode .review,
    html.dark-mode .review .by,
    html.dark-mode .date-cell {
      color: #94a3b8;
    }
    html.dark-mode .review > div:first-child {
      color: var(--tocca-dark-text, #e5e7eb);
    }
    /* Responsive: stack feedback / store / date on small screens */
    @media (max-width: 575.98px) {
      #feedbackTable tbody td { padding: .75rem .5rem; }
      .store-cell .logo,
      .store-cell .logo-fallback { width: 32px; height: 32px; flex-basis: 32px; }
      .date-cell { font-size: .8rem; }
    }
  </style>
</head>
<body class="sb-nav-fixed">
  <?php include __DIR__ . '/partials/admin_topnav.php'; ?>

<div id="layoutSidenav">
    <!-- Sidebar -->
    <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

<div id="layoutSidenav_content">
      <main>
        <div class="container-fluid px-4">
                    <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">Nomination Feedback</h1>
              <?php echo render_feedbacks_breadcrumb([['label' => 'Nomination']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>

          <!-- Filters -->
          <div class="card mb-3 admin-tx-card"><div class="card-body">
            <div class="row g-2 align-items-end admin-filter-bar">
              <div class="col-md-auto">
                <label class="form-label">From</label>
                <input type="date" id="dateFrom" class="form-control form-control-sm">
              </div>
              <div class="col-md-auto">
                <label class="form-label">To</label>
                <input type="date" id="dateTo" class="form-control form-control-sm">
              </div>
              <div class="col-md-3">
                <label class="form-label">Search text</label>
                <input type="text" id="searchTxt" class="form-control form-control-sm" placeholder="Find words in feedback or business">
              </div>
              <div class="col-md-auto d-flex flex-wrap gap-2">
                <button id="btnApply" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Apply</button>
                <button id="btnReset" class="btn btn-outline-secondary btn-sm">Reset</button>
              </div>
            </div>
          </div></div>

          <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-body">
              <div class="table-responsive">
            <table id="feedbackTable" class="table table-striped table-bordered admin-data-table align-middle mb-0 w-100">
              <thead class="table-light"><tr><th>Feedback</th><th>Establishment</th><th>Date</th></tr></thead>
              <tbody></tbody>
            </table>
              </div>
            </div>
          </div>

          <input type="hidden" id="currentEventId" value="<?= h((string) ($default_event_id ?: '')) ?>">
        </div>
      </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
</div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.datatables.net/v/bs5/dt-2.0.7/r-3.0.3/datatables.min.js"></script>

  <script>
  (function(){
    const ENDPOINT = 'get_feedback.php'; // this file returns JSON
    const $eventId = $('#currentEventId'), $from = $('#dateFrom'), $to = $('#dateTo'), $q = $('#searchTxt');

    const table = $('#feedbackTable').DataTable({
      processing:true, serverSide:true, searching:false, ordering:false,
      lengthMenu:[[10,25,50],[10,25,50]],
      ajax: function (data, cb) {
        const params = new URLSearchParams();
        params.set('type','nominee');
        const eid = $eventId.val() || '';
        if (eid) params.set('event_id', eid);
        params.set('start', data.start || 0);
        params.set('length', data.length || 25);
        if ($from.val()) params.set('date_from', $from.val());
        if ($to.val())   params.set('date_to', $to.val());
        if ($q.val())    params.set('q', $q.val());

        fetch(ENDPOINT + '?' + params.toString(), {credentials:'same-origin'})
          .then(async r => {
            const text = await r.text();         // read as text first
            try { return JSON.parse(text); }     // parse to JSON
            catch(e){ console.error('Raw:', text); throw new Error('Invalid JSON from server'); }
          })
          .then(json => {
            if (json.status !== 'success') throw new Error(json.message || 'Failed');
            const rows = (json.data||[]).map(row => {
              const feedback = (row.feedback||'').trim() || '—';
              const business = row.business_name || '—';
              const isAnon   = Number(row.is_anonymous) === 1;
              const display  = isAnon ? 'Anonymous' : business;
              const dateStr  = formatDate(row.submitted_at);

              const feedbackCell = `
                <div class="review">
                  <div>${escapeHtml(feedback)}</div>
                </div>`;
              let logo;
              if (isAnon) {
                logo = `<span class="logo-fallback logo-anon" title="Submitted anonymously">AN</span>`;
              } else if (row.logo_url) {
                logo = `<img class="logo" src="${escapeAttr(row.logo_url)}" alt="${escapeAttr(business)} logo" onerror="this.outerHTML='<span class=&quot;logo-fallback&quot; style=&quot;background:${hashGradient(business)}&quot;>${initials(business)}</span>'">`;
              } else {
                logo = `<span class="logo-fallback" style="background:${hashGradient(business)}">${initials(business)}</span>`;
              }
              const storeCell = `<div class="store-cell">${logo}<div title="${escapeAttr(display)}">${escapeHtml(display)}</div></div>`;
              const dateCell  = `<span class="date-cell">${escapeHtml(dateStr)}</span>`;
              return [feedbackCell, storeCell, dateCell];
            });
            cb({ data: rows, recordsTotal: json.recordsTotal ?? 0, recordsFiltered: json.recordsFiltered ?? 0, draw: data.draw });
          })
          .catch(err => { console.error(err); cb({data:[], recordsTotal:0, recordsFiltered:0, draw:data.draw}); });
      },
      columnDefs:[
        {targets:0, width:'60%', className:'align-middle'},
        {targets:1, width:'25%'},
        {targets:2, width:'15%', className:'text-end'}
      ],
      responsive:true, dom:'frtip'
    });

    $('#btnApply').on('click', ()=> table.ajax.reload());
    $('#btnReset').on('click', ()=>{
      $from.val(''); $to.val(''); $q.val('');
      table.ajax.reload();
    });

    // helpers
    function escapeHtml(s){ return String(s??'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function escapeAttr(s){ return escapeHtml(s).replace(/"/g,'&quot;'); }
    function initials(name){ const p=String(name||'').trim().split(/\s+/).slice(0,2).map(w=>w[0]?.toUpperCase()||''); return (p.join('')||'E'); }
    function hashGradient(s){
      let h=0; for (const ch of String(s||'?')) h=(h*31+ch.charCodeAt(0))|0;
      const palette = [
        ['#4f46e5','#2563eb'],['#0ea5e9','#22d3ee'],['#10b981','#059669'],
        ['#f59e0b','#f97316'],['#ec4899','#db2777'],['#8b5cf6','#6366f1'],
        ['#14b8a6','#0d9488'],['#ef4444','#b91c1c']
      ];
      const [a,b] = palette[Math.abs(h) % palette.length];
      return `linear-gradient(135deg, ${a}, ${b})`;
    }
    function formatDate(ts){ if(!ts) return ''; const d=new Date(ts.replace(' ','T')); if(isNaN(d)) return ts; return d.toLocaleDateString(undefined,{day:'2-digit',month:'long',year:'numeric'}); }
  })();
  </script>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>

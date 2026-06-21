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
  <title>Voters Feedbacks | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?= h($faviconPath ?? '') ?>">
  <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet"/>
  <link href="css/styles.css" rel="stylesheet"/>
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
  <link href="https://cdn.datatables.net/v/bs5/dt-2.0.7/r-3.0.3/datatables.min.css" rel="stylesheet"/>
  <style>
    #feedbackTable { vertical-align: middle; }
    .review { max-width: 100%; word-break: break-word; white-space: pre-wrap; line-height: 1.5; }
    .store-cell { display: flex; align-items: center; gap: .65rem; min-width: 0; font-weight: 500; }
    .store-cell .logo-fallback {
      width: 38px; height: 38px; border-radius: 50%; flex: 0 0 38px;
      display: inline-flex; align-items: center; justify-content: center;
      color: #fff; font-weight: 700; font-size: .82rem;
    }
    .date-cell { white-space: nowrap; color: var(--bs-secondary-color); font-size: .875rem; }
  </style>
</head>
<body class="sb-nav-fixed">
  <?php include __DIR__ . '/partials/admin_topnav.php'; ?>

  <div id="layoutSidenav">
    <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

    <div id="layoutSidenav_content">
      <main>
        <div class="container-fluid px-4">
                    <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">Voters Feedback</h1>
              <?php echo render_feedbacks_breadcrumb([['label' => 'Voting']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>

          <div class="card mb-3 admin-tx-card">
            <div class="card-body">
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
                  <input type="text" id="searchTxt" class="form-control form-control-sm" placeholder="Words or last 2 digits of mobile">
                </div>
                <div class="col-md-auto d-flex flex-wrap gap-2">
                  <button id="btnApply" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Apply</button>
                  <button id="btnReset" class="btn btn-outline-secondary btn-sm">Reset</button>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-body">
              <div class="table-responsive">
                <table id="feedbackTable" class="table table-striped table-bordered admin-data-table align-middle mb-0 w-100">
                  <thead class="table-light">
                    <tr><th>Feedback</th><th>From</th><th>Date</th></tr>
                  </thead>
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
    const ENDPOINT = 'get_feedback.php';
    const $eventId = $('#currentEventId');
    const $from = $('#dateFrom'), $to = $('#dateTo'), $q = $('#searchTxt');

    const table = $('#feedbackTable').DataTable({
      processing:true, serverSide:true, searching:false, ordering:false,
      lengthMenu:[[10,25,50],[10,25,50]],
      ajax: function (data, cb) {
        const params = new URLSearchParams();
        params.set('type','voter');
        const eid = $eventId.val() || '';
        if (eid) params.set('event_id', eid);
        params.set('start', data.start || 0);
        params.set('length', data.length || 25);
        if ($from.val()) params.set('date_from', $from.val());
        if ($to.val())   params.set('date_to', $to.val());
        if ($q.val())    params.set('q', $q.val());

        fetch(ENDPOINT + '?' + params.toString(), {credentials:'same-origin'})
          .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); }
            catch(e){ console.error('Raw:', text); throw new Error('Invalid JSON from server'); }
          })
          .then(json => {
            if (json.status !== 'success') throw new Error(json.message || 'Failed');

            const rows = (json.data || []).map(row => {
              const feedback = (row.feedback || '').trim();
              const masked = row.display_name || maskPhone(row.phone_raw || '') || 'Anonymous';
              const last2  = lastTwo(row.phone_raw || '');
              const dateStr = formatDate(row.submitted_at);
              const feedbackCell = `<div class="review"><div class="mb-1">${escapeHtml(feedback)}</div></div>`;
              const avatarText = last2 || '??';
              const colorKey   = masked || 'Anonymous';
              const logo = `<span class="logo-fallback" style="background:${hashColor(colorKey)}">${escapeHtml(avatarText)}</span>`;
              const fromCell = `<div class="store-cell">${logo}<div>${escapeHtml(masked)}</div></div>`;
              const dateCell = `<span class="date-cell">${escapeHtml(dateStr)}</span>`;
              return [feedbackCell, fromCell, dateCell];
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

    function escapeHtml(s){ return String(s??'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function hashColor(s){ let h=0; for (const ch of String(s)) h=(h*31+ch.charCodeAt(0))|0; const r=(h>>16)&255,g=(h>>8)&255,b=h&255; return `rgb(${(r+200)%256}, ${(g+140)%256}, ${(b+180)%256})`; }
    function formatDate(ts){ if(!ts) return ''; const d=new Date(String(ts).replace(' ','T')); if(isNaN(d)) return ts; return d.toLocaleDateString(undefined,{day:'2-digit',month:'long',year:'numeric'}); }
    function lastTwo(ph){
      if(!ph) return '';
      const digits = String(ph).replace(/\D+/g,'');
      return digits.slice(-2) || '';
    }
    function maskPhone(ph){
      if(!ph) return '';
      const digits = String(ph).replace(/\D+/g,'');
      if (digits.length < 3) return '•••';
      const first3 = digits.slice(0, 3);
      const last2 = digits.length >= 5 ? digits.slice(-2) : '';
      const hidden = Math.max(0, digits.length - first3.length - last2.length);
      const core = first3 + '•'.repeat(hidden) + last2;
      return String(ph).trim().startsWith('+') ? '+' + core : core;
    }
  })();
  </script>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title>Audit Logs | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <?php include 'inline_style.php'; ?>
  <style>
    #auditTable td { vertical-align: top; }
    #auditTable td:nth-child(7) { min-width: 220px; max-width: 420px; }
    .audit-diff-list { list-style: none; padding-left: 0; margin-bottom: 0; }
    .audit-diff-item { line-height: 1.45; }
    .audit-diff-arrow { opacity: 0.75; }
    html.dark-mode #auditTable .badge.text-bg-secondary {
      background-color: rgba(108, 117, 125, 0.35) !important;
      color: #e9ecef !important;
    }
    html.dark-mode #auditTable .badge.text-bg-success {
      background-color: rgba(25, 135, 84, 0.35) !important;
      color: #d1e7dd !important;
    }
  </style>
  </head>

<body class="sb-nav-fixed">
  <!-- Top Nav -->
  <?php include __DIR__ . '/partials/admin_topnav.php'; ?>

  <div id="layoutSidenav">
    <!-- Sidebar -->
    <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

<div id="layoutSidenav_content">
      <main>
        <div class="container-fluid px-4">
                    <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">Admin History Log</h1>
              <?php echo render_utilities_breadcrumb([['label' => 'Audit Logs']]); ?>
            </div>
          </div>

          <?php echo render_admin_event_context(); ?>

          <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
              <span class="fw-semibold mb-0"><i class="fas fa-table me-1"></i> Audit Logs Table</span>
              <span class="text-muted small mb-0">All admin actions in the system</span>
            </div>
            <div class="card-body">
              <div class="row g-2 mb-3 align-items-end">
                <div class="col-md-2 col-sm-6">
                  <label for="filterFrom" class="form-label small text-muted mb-1">From</label>
                  <input type="date" id="filterFrom" class="form-control form-control-sm" />
                </div>
                <div class="col-md-2 col-sm-6">
                  <label for="filterTo" class="form-label small text-muted mb-1">To</label>
                  <input type="date" id="filterTo" class="form-control form-control-sm" />
                </div>
                <div class="col-md-2 col-sm-6">
                  <label for="filterModule" class="form-label small text-muted mb-1">Module</label>
                  <select id="filterModule" class="form-select form-select-sm">
                    <option value="">All Modules</option>
                    <option value="events">Events</option>
                    <option value="categories">Categories</option>
                    <option value="questions">Name of Awards</option>
                    <option value="choices">Businesses</option>
                    <option value="nomination_fields">Registration Form</option>
                    <option value="registrations">Registration</option>
                    <option value="nomination_reports">Registration Reports</option>
                    <option value="nomination_qr">Registration QR</option>
                    <option value="establishment_qr">Business QR</option>
                    <option value="results">Results</option>
                    <option value="voters">Voters</option>
                    <option value="schedule">Schedule</option>
                    <option value="voter_portal">Voter Page Content</option>
                  </select>
                </div>
                <div class="col-md-2 col-sm-6">
                  <label for="filterAction" class="form-label small text-muted mb-1">Action</label>
                  <select id="filterAction" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <option value="create">Create</option>
                    <option value="update">Update</option>
                    <option value="delete">Delete</option>
                    <option value="activate">Activate</option>
                    <option value="deactivate">Deactivate</option>
                    <option value="archive">Archive</option>
                    <option value="restore">Restore</option>
                    <option value="export">Downloaded</option>
                    <option value="generate_qr">Generated QR</option>
                    <option value="regenerate_qr">Regenerated QR</option>
                    <option value="schedule_change">Schedule Change</option>
                  </select>
                </div>
                <div class="col-md-2 col-sm-6">
                  <label for="filterAdminId" class="form-label small text-muted mb-1">Admin ID</label>
                  <input id="filterAdminId" class="form-control form-control-sm" placeholder="Admin ID" />
                </div>
                <div class="col-md-2 col-sm-6 d-grid">
                  <button type="button" id="applyFilters" class="btn btn-primary btn-sm">Apply</button>
                </div>
              </div>

              <div class="table-responsive">
                <table id="auditTable" class="table table-striped table-bordered admin-data-table w-100 mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Time</th>
                      <th>Admin</th>
                      <th>Module</th>
                      <th>Entity</th>
                      <th>Action</th>
                      <th>Event</th>
                      <th>Details</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </main>

      <!-- Footer -->
      </div>
  </div>

  <!-- Light theme color overrides -->
  <?php include __DIR__ . '/partials/admin_datatables_scripts.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

  <script>
    $(function () {
      const esc = (s)=> $('<div>').text(String(s ?? '')).html();

      const moduleMap = {
        categories: 'Categories',
        questions: 'Name of Awards',
        choices: 'Businesses',
        events: 'Events',
        nomination_fields: 'Registration Form',
        registrations: 'Registration',
        nomination_reports: 'Registration Reports',
        nomination_qr: 'Registration QR',
        establishment_qr: 'Business QR',
        results: 'Results',
        voters: 'Voters',
        schedule: 'Schedule',
        event_schedule: 'Schedule',
        voter_portal: 'Voter Page Content'
      };

      const actionMap = {
        create: 'add',
        update: 'edit',
        delete: 'delete',
        activate: 'activate',
        deactivate: 'deactivate',
        schedule_change: 'schedule change',
        archive: 'archive',
        restore: 'restore',
        export: 'downloaded',
        download: 'downloaded',
        generate_qr: 'generated qr',
        regenerate_qr: 'regenerated qr'
      };

      const table = $('#auditTable').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        ordering: false,
        orderMulti: false,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        language: {
          emptyTable: 'No audit log entries found.',
          zeroRecords: 'No matching audit log entries.'
        },

        ajax: {
          url: 'admin_audit_logs.php',
          data: function(d) {
            d.from     = $('#filterFrom').val();
            d.to       = $('#filterTo').val();
            d.module   = $('#filterModule').val();
            d.action   = $('#filterAction').val();
            d.admin_id = $('#filterAdminId').val();
          }
        },
        columns: [
          {
            data: 'event_time',
            render: (v)=> {
              if (!v) return '';
              const d = new Date(v.replace(' ', 'T'));
              if (isNaN(d.getTime())) return esc(v);
              return d.toLocaleString(undefined, {
                year: 'numeric', month: 'long', day: '2-digit',
                hour: 'numeric', minute: '2-digit'
              });
            }
          },
          { data: null, render: r => r.admin_name ? esc(r.admin_name) : (r.admin_id ? ('#'+esc(r.admin_id)) : '—') },
          {
            data: 'module',
            render: (v)=> esc(moduleMap[v] || (v ? String(v).replace(/_/g, ' ') : '—'))
          },
          {
            data: null,
            render: r => {
              const pick = (obj)=> obj?.category_name
              ?? obj?.question_name
              ?? obj?.question
              ?? obj?.choice_name
              ?? obj?.name
              ?? obj?.title
              ?? obj?.label
              ?? null;
              const label = r.entity_label
                || (r.details && (r.details.choice_name || r.details.event_name))
                || (r.details ? (pick(r.details.new) ?? pick(r.details.old) ?? pick(r.details)) : null);
              return label ? `<b>${esc(label)}</b>` : (r.entity_id ? `#${esc(r.entity_id)}` : '—');
            }
          },
          {
            data: 'action',
            render: (v, _, row)=> {
              let key = v ? String(v).toLowerCase() : '';
              if (!key && row?.details?.new) key = 'create';
              return key ? esc(actionMap[key] || key) : '—';
            }
          },
          { data: 'event', render: e => e && (e.name || e.id) ? esc(e.name || ('Event #'+e.id)) : '—' },

          // Details (unchanged logic)
          {
            data: 'details',
            orderable: false,
            render: (d, _, row) => {
              try {
                const esc = s => $('<div>').text(String(s ?? '')).html();

                const pretty = (field) => {
                  const dict = {
                    category_name:     'Category name',
                    question_name:     'Award name',
                    question:          'Question',
                    choice_name:       'Business',
                    event_name:        'Event name',
                    description:       'Description',
                    year:              'Year',
                    is_active:         'Active',
                    is_required:       'Required',
                    status:            'Status',
                    event_id:          'Event',
                    label:             'Label',
                    name:              'Name',
                    type:              'Type',
                    options:           'Options',
                    sort_order:        'Order',
                    nomination_start:  'Registration start',
                    nomination_end:    'Registration end',
                    voting_start:      'Voting start',
                    voting_end:        'Voting end',
                    bullets_json:      'Bullets Json'
                  };
                  return dict[field] || field.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                };

                const normVal = (k, v) => {
                  if (Array.isArray(v)) return v.length ? v.join(', ') : '(none)';

                  if (k === 'status' || k === 'is_active') {
                    if (v === 1 || v === '1' || v === true  || v === 'true')  return 'Active';
                    if (v === 0 || v === '0' || v === false || v === 'false') return 'Inactive';
                  }
                  if (k === 'is_required') {
                    if (v === 1 || v === '1' || v === true  || v === 'true')  return 'Required';
                    if (v === 0 || v === '0' || v === false || v === 'false') return 'Not required';
                  }

                  if (/_(start|end)$/.test(k) && v) {
                    const dt = new Date(String(v).replace(' ', 'T'));
                    if (!isNaN(dt.getTime())) {
                      return dt.toLocaleString(undefined, {
                        year: 'numeric', month: 'long', day: '2-digit',
                        hour: 'numeric', minute: '2-digit'
                      });
                    }
                  }

                  return (v ?? '') === '' || v === null ? '(not set)' : v;
                };

                const pick = (obj) => obj?.category_name
                  ?? obj?.question_name
                  ?? obj?.question
                  ?? obj?.choice_name
                  ?? obj?.event_name
                  ?? obj?.label
                  ?? obj?.title
                  ?? (obj?.section === 'intro' ? 'Registration Form Introduction'
                    : obj?.section === 'instructions' ? 'Registration Form Instruction'
                    : null)
                  ?? null;

                const act = String(row?.action || '').toLowerCase();
                const createdWord = act === 'activate' ? 'Activated' : 'Created';

                if ((act === 'export' || act === 'download') && d && typeof d === 'object') {
                  const bits = [];
                  if (d.format) bits.push(`<strong>Format:</strong> ${esc(String(d.format).toUpperCase())}`);
                  if (d.scope) bits.push(`<strong>Scope:</strong> ${esc(d.scope)}`);
                  if (d.rows !== undefined) bits.push(`<strong>Rows:</strong> ${esc(d.rows)}`);
                  if (d.event_name) bits.push(`<strong>Event:</strong> ${esc(d.event_name)}`);
                  if (d.doc_ref) bits.push(`<strong>Doc ref:</strong> ${esc(d.doc_ref)}`);
                  const head = '<b>Downloaded</b>';
                  return bits.length ? `${head}<ul class="mb-0 ps-3">${bits.map(b => `<li>${b}</li>`).join('')}</ul>` : head;
                }

                if (act === 'generate_qr' || act === 'regenerate_qr') {
                  const mod = String(row?.module || '').toLowerCase();
                  const name = d?.choice_name || d?.event_name || row?.entity_label || '';
                  const isNomination = mod === 'nomination_qr';
                  const verb = act === 'regenerate_qr'
                    ? (isNomination ? 'Regenerated registration QR' : 'Regenerated establishment QR')
                    : (isNomination ? 'Generated registration QR' : 'Generated establishment QR');
                  let html = `<b>${verb}${name ? ':</b> ' + esc(name) : '</b>'}`;
                  if (d?.path) {
                    html += ` <span class="text-muted small">(${esc(d.path)})</span>`;
                  }
                  return html;
                }

                if (act === 'archive') {
                  const label = pick(d?.old) ?? pick(d?.new) ?? row?.entity_label ?? pick(d) ?? '';
                  return `<b>Archived${label ? ':</b> ' + esc(label) : '</b>'}`;
                }

                const changed = (d?.diff?.changed) ? d.diff.changed : (d?.changed ? d.changed : null);

                const valBadge = (k, val, variant) => {
                  const normalized = normVal(k, val);
                  const safe = esc(normalized);

                  if (normalized === '(not set)') {
                    return `<span class="audit-diff-empty fst-italic opacity-75">${safe}</span>`;
                  }
                  const badgeClass = variant === 'new' ? 'text-bg-success' : 'text-bg-secondary';
                  return `<span class="badge ${badgeClass}">${safe}</span>`;
                };

                if (changed && Object.keys(changed).length) {
                  let s = '<b>Changed:</b><ul class="mb-0 ps-3 audit-diff-list">';
                  for (const k in changed) {
                    const c = changed[k] || {};
                    s += `<li class="mb-1 audit-diff-item"><span class="fw-semibold audit-diff-label">${esc(pretty(k))}</span>: `
                       + `${valBadge(k, c.old, 'old')} <span class="mx-1 audit-diff-arrow">→</span> ${valBadge(k, c.new, 'new')}</li>`;
                  }
                  s += '</ul>';
                  return s;
                }

                if (d?.new && !d?.old) {
                  const label = pick(d.new) ?? row?.entity_label ?? pick(d) ?? '';
                  const head  = `<b>${createdWord}${label ? ':</b> ' + esc(label) : '</b>'}`;

                  const etype = String(row?.entity_type || '').toLowerCase();
                  if (etype === 'nomination_field') {
                    const n = d.new || {};
                    const bullets = [];
                    if (n.type !== undefined)        bullets.push(`<strong>${esc(pretty('type'))}:</strong> ${esc(normVal('type', n.type))}`);
                    if (n.options !== undefined)     bullets.push(`<strong>${esc(pretty('options'))}:</strong> ${esc(normVal('options', n.options))}`);
                    if (n.is_required !== undefined) bullets.push(`<strong>${esc(pretty('is_required'))}:</strong> ${esc(normVal('is_required', n.is_required))}`);
                    if (n.is_active !== undefined)   bullets.push(`<strong>${esc(pretty('is_active'))}:</strong> ${esc(normVal('is_active', n.is_active))}`);
                    if (n.sort_order !== undefined)  bullets.push(`<strong>${esc(pretty('sort_order'))}:</strong> ${esc(normVal('sort_order', n.sort_order))}`);
                    if (bullets.length) return `${head}<ul class="mb-0"><li>${bullets.join('</li><li>')}</li></ul>`;
                  }
                  return head;
                }

                if (d?.old && !d?.new) {
                  const label = pick(d.old) ?? row?.entity_label ?? pick(d) ?? '';
                  return `<b>Deleted${label ? ':</b> ' + esc(label) : '</b>'}`;
                }

                const label = row?.entity_label ?? pick(d) ?? '';
                if (act === 'create' || act === 'add')  return `<b>${createdWord}${label ? ':</b> ' + esc(label) : '</b>'}`;
                if (act === 'delete')                   return `<b>Deleted${label ? ':</b> ' + esc(label) : '</b>'}`;
                if (act === 'edit' || act === 'update') return label ? esc(label) : '';

                return label ? esc(label) : '';
              } catch {
                return '';
              }
            }
          }
        ]
      });

      $('#applyFilters').on('click', () => table.ajax.reload());

      $('#auditTable').on('error.dt', function(e, settings, techNote, message) {
        console.error('DataTables error:', message);
      });
    });
  </script>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>

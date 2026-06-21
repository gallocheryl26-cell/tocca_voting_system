<?php /** @var string $feedbackType */ ?>
<div class="container-fluid px-4">
  <div class="admin-page-header mt-4 mb-4">
    <div class="min-w-0">
      <h1 class="admin-page-title mb-2"><?php echo h($pageTitle ?? 'Feedbacks'); ?></h1>
      <?php echo render_feedbacks_breadcrumb([['label' => $pageTitle ?? 'Feedbacks']]); ?>
    </div>
  </div>
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body row g-3 align-items-end">
      <div class="col-md-2">
        <label for="fbEvent" class="form-label">Event ID</label>
        <input type="number" class="form-control" id="fbEvent" min="0" placeholder="All">
      </div>
      <div class="col-md-2">
        <label for="fbFrom" class="form-label">From</label>
        <input type="date" class="form-control" id="fbFrom">
      </div>
      <div class="col-md-2">
        <label for="fbTo" class="form-label">To</label>
        <input type="date" class="form-control" id="fbTo">
      </div>
      <div class="col-md-4">
        <label for="fbQ" class="form-label">Search</label>
        <input type="search" class="form-control" id="fbQ" placeholder="Search feedback">
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-primary w-100" id="fbApply">Apply</button>
      </div>
    </div>
  </div>
  <div class="card shadow-sm border-0 admin-table-card">
    <div class="card-body">
      <div class="table-responsive">
      <table id="feedbackTable" class="table table-striped table-bordered admin-data-table w-100">
        <thead class="table-light">
          <tr>
            <th>Submitted</th>
            <th><?php echo ($feedbackType ?? '') === 'voter' ? 'Mobile' : 'Contact'; ?></th>
            <th>Feedback</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const type = <?php echo json_encode($feedbackType ?? 'nominee'); ?>;
  const tableEl = document.getElementById('feedbackTable');
  if (!tableEl || !window.jQuery || !jQuery.fn.DataTable) return;

  const dt = jQuery('#feedbackTable').DataTable({
    processing: true,
    serverSide: false,
    ajax: function (data, callback) {
      const params = new URLSearchParams({
        type,
        start: String(data.start || 0),
        length: String(data.length || 25),
        event_id: document.getElementById('fbEvent')?.value || '',
        date_from: document.getElementById('fbFrom')?.value || '',
        date_to: document.getElementById('fbTo')?.value || '',
        q: document.getElementById('fbQ')?.value || ''
      });
      fetch('get_feedback.php?' + params.toString())
        .then(r => r.json())
        .then(json => {
          if (json.status !== 'success') {
            callback({ data: [], recordsTotal: 0, recordsFiltered: 0 });
            return;
          }
          const rows = (json.data || []).map(row => ({
            submitted_at: row.submitted_at || '—',
            contact: row.display_contact || row.phone_display || '—',
            feedback: row.feedback || ''
          }));
          callback({
            data: rows,
            recordsTotal: json.recordsTotal || rows.length,
            recordsFiltered: json.recordsFiltered || rows.length
          });
        })
        .catch(() => callback({ data: [], recordsTotal: 0, recordsFiltered: 0 }));
    },
    columns: [
      { data: 'submitted_at' },
      { data: 'contact' },
      { data: 'feedback' }
    ],
    order: [[0, 'desc']],
    pageLength: 25
  });

  document.getElementById('fbApply')?.addEventListener('click', () => dt.ajax.reload());
});
</script>

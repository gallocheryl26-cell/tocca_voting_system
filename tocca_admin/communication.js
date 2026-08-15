// communications.js — Registration Emails only (no QR in this module)
(() => {
  const frm = document.getElementById('frmFilters');
  const tblSent = $('#tblSent');
  let dtSent = null;

  function toast(msg, ok = true) {
    const el = document.getElementById('toastMsg');
    const body = document.getElementById('toastBody');
    if (!el || !body) return alert(msg);
    el.className = `toast align-items-center text-bg-${ok ? 'success' : 'danger'} border-0`;
    body.textContent = msg;
    new bootstrap.Toast(el).show();
  }

  // Escape (includes apostrophes)
  function esc(s) {
    return (s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[m] || m));
  }

  // Date helpers: MM/DD/YY (table) and MM/DD/YY h:mm:ss AM/PM (modal)
  function toDate(val) {
    if (!val) return null;
    const d = new Date(String(val).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? null : d;
  }
  const pad2 = n => String(n).padStart(2, '0');

  function fmtMDY(val) {
    const d = toDate(val); if (!d) return '';
    const mm = pad2(d.getMonth() + 1);
    const dd = pad2(d.getDate());
    const yy = pad2(d.getFullYear() % 100);
    return `${mm}/${dd}/${yy}`;
  }
  function fmtMDYTime12(val) {
    const d = toDate(val); if (!d) return '';
    const mm = pad2(d.getMonth() + 1);
    const dd = pad2(d.getDate());
    const yy = pad2(d.getFullYear() % 100);
    let h = d.getHours();
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const min = pad2(d.getMinutes());
    const sec = pad2(d.getSeconds());
    return `${mm}/${dd}/${yy} ${h}:${min}:${sec} ${ampm}`;
  }

  // Pretty labels for type/status
  function toLabel(s) {
    const map = {
      qr_email: 'QR Email',
      nomination_status: 'Registration Status',
      nomination_info: 'Registration Info',
      nomination_update: 'Registration Update',
      sent: 'Sent', failed: 'Failed', pending: 'Pending'
    };
    if (!s) return '—';
    const key = String(s).toLowerCase();
    return map[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  async function fetchData() {
    const params = new URLSearchParams();
    const event_id = document.getElementById('event_id')?.value || '';
    const from     = document.getElementById('from')?.value || '';
    const to       = document.getElementById('to')?.value || '';
    const q        = (document.getElementById('q')?.value || '').trim();
    const limit    = document.getElementById('limit')?.value || 200;

    if (event_id) params.set('event_id', event_id);
    if (from)     params.set('from', from);
    if (to)       params.set('to', to);
    if (q)        params.set('q', q);
    params.set('limit', limit);

    const res = await fetch(`comm_search.php?${params}`);
    const txt = await res.text();
    let j;
    try { j = JSON.parse(txt); } catch {
      throw new Error('Server returned non-JSON (check PHP error logs).');
    }
    if (j.status !== 'success') throw new Error(j.message || 'Failed');
    return j;
  }

  // Build rows (To, Subject, Sent, View)
  function buildSentRows(rows) {
    return rows.map(r => {
      const sentDisplay = fmtMDY(r.sent_at);
      const sentTitle   = fmtMDYTime12(r.sent_at);
      return ([
        esc(r.recipient_name || '—'),
        `<span class="truncate" title="${esc(r.subject || '')}">${esc(r.subject || '')}</span>`,
        `<span title="${esc(sentTitle)}">${esc(sentDisplay)}</span>`,
        `<button class="btn btn-sm btn-outline-primary" data-view="${r.id}">View</button>`
      ]);
    });
  }

  async function load() {
    try {
      const data = await fetchData();
      // Only registration emails here (exclude QR email type)
      const rows = (data.sent || []).filter(
        r => (r.type || '').toLowerCase() !== 'qr_email'
      );

      if (dtSent) dtSent.destroy();
      tblSent.find('tbody').empty();

      dtSent = tblSent.DataTable({
        data: buildSentRows(rows),
        columns: [
          { title: 'To' }, { title: 'Subject' }, { title: 'Sent' }, { title: 'View' }
        ],
        pageLength: 10,
        order: [[2, 'desc']],
        columnDefs: [{ orderable: false, targets: [3] }]
      });

      const sentCountEl = document.getElementById('sentCount');
      if (sentCountEl) sentCountEl.textContent = `${rows.length}`;
    } catch (e) {
      toast(`Load failed: ${e.message}`, false);
    }
  }

  // View handler -> fills new modal layout (no Created/Scheduled, no QR)
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-view]');
    if (!btn) return;

    const id = +btn.getAttribute('data-view');
    try {
      const res = await fetch(`comm_view.php?id=${id}`);
      const j = await res.json();
      if (j.status !== 'success') throw new Error(j.message || 'Failed');
      const r = j.row;

      // ID + Subject
      document.getElementById('vmId').textContent = r.id;
      document.getElementById('vmSubject').textContent = r.subject || '(no subject)';

      // To
      const toStr = `${r.recipient_name || ''}${r.recipient_email ? ` <${r.recipient_email}>` : ''}`.trim();
      document.getElementById('vmTo').textContent = toStr || '—';

      // Type label badge
      document.getElementById('vmType').textContent = toLabel(r.type);

      // Status badge
      const sKey = (r.status || '').toLowerCase();
      const tone = sKey === 'sent' ? 'success' : (sKey === 'failed' ? 'danger' : 'secondary');
      document.getElementById('vmStatus').innerHTML =
        `<span class="badge text-bg-${tone}">${toLabel(sKey)}</span>`;

      // Sent (formatted)
      document.getElementById('vmSent').textContent = fmtMDYTime12(r.sent_at) || '—';

      // Retries / Error (only show error block when send failed)
      document.getElementById('vmRetries').textContent = r.retries ?? 0;
      const errText = (r.error_text || '').trim();
      const errWrap = document.getElementById('vmErrorWrap');
      const errEl = document.getElementById('vmError');
      if (errWrap && errEl) {
        if (errText) {
          errEl.textContent = errText;
          errWrap.classList.remove('d-none');
        } else {
          errEl.textContent = '';
          errWrap.classList.add('d-none');
        }
      }

      // Body HTML
      document.getElementById('vmBody').innerHTML = r.body_html || '(no body)';

      new bootstrap.Modal(document.getElementById('viewModal')).show();
    } catch (err) {
      toast(`View failed: ${err.message}`, false);
    }
  });

  frm?.addEventListener('submit', (e) => { e.preventDefault(); load(); });
  load();
})();

(() => {
  'use strict';
  function toast(msg, ok = true) {
    const el = document.getElementById('toastMsg');
    const body = document.getElementById('toastBody');
    if (!el || !body) return alert(msg);
    el.className = `toast align-items-center text-bg-${ok ? 'success' : 'danger'} border-0`;
    body.textContent = msg;
    new bootstrap.Toast(el).show();
  }

  const esc  = s => (s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]||m));
  const pad2 = n => String(n).padStart(2, '0');
  const toDate = v => { if (!v) return null; const d = new Date(String(v).replace(' ','T')); return Number.isNaN(d.getTime()) ? null : d; };
  const fmtMDY = v => { const d = toDate(v); if (!d) return ''; return `${pad2(d.getMonth()+1)}/${pad2(d.getDate())}/${pad2(d.getFullYear()%100)}`; };
  const fmtMDYTime12 = v => {
  const d = toDate(v); if (!d) return '';
  let h = d.getHours(); const ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
  return `${pad2(d.getMonth()+1)}/${pad2(d.getDate())}/${String(d.getFullYear()).slice(-2)}\n` +
         `${h}:${pad2(d.getMinutes())} ${ap}`;
};

  const toLabel = s => { const map = { qr_email:'QR Email', sent:'Sent', failed:'Failed', pending:'Pending' };
    if (!s) return '—'; const k = String(s).toLowerCase(); return map[k] || k.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
  };

  function ensureTableSkeleton() {
    const tbl = document.getElementById('tblQr');
    if (!tbl) return false;

    if ($.fn.DataTable && $.fn.DataTable.isDataTable('#tblQr')) {
      $('#tblQr').DataTable().clear().destroy();
    }

    if (!tbl.tHead || tbl.tHead.rows[0]?.cells.length !== 4) {
      tbl.innerHTML = `
        <thead>
          <tr>
            <th>To</th>
            <th>Subject</th>
            <th>Sent</th>
            <th>View</th>
          </tr>
        </thead>
        <tbody></tbody>
      `;
    } else {
      if (!tbl.tBodies.length) tbl.appendChild(document.createElement('tbody'));
    }
    return true;
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
    params.set('_', Date.now()); 

    const res = await fetch(`comm_search_qr.php?${params.toString()}`, { cache:'no-store' });
    const txt = await res.text();
    let j; try { j = JSON.parse(txt); } catch { throw new Error('Server returned non-JSON'); }
    if (j.status !== 'success') throw new Error(j.message || 'Failed');
    return j.qr || [];
  }

  function buildQrRows(rows) {
    return rows.map(r => {
      const sentDisplay = fmtMDY(r.sent_at);
      const sentTitle   = fmtMDYTime12(r.sent_at);
      return [
        esc(r.recipient_name || '—'),
        `<span class="truncate" title="${esc(r.subject || '')}">${esc(r.subject || '')}</span>`,
        `<span title="${esc(sentTitle)}">${esc(sentDisplay)}</span>`,
        `<button class="btn btn-sm btn-outline-primary" data-view="${r.id}">View</button>`
      ];
    });
  }

  let dtQr = null;

  function render(rows) {
    if (!ensureTableSkeleton()) {
      console.warn('[QR] #tblQr not found in DOM');
      return;
    }

    const $tbl = $('#tblQr');

    if ($.fn.DataTable.isDataTable('#tblQr')) {
      dtQr = $tbl.DataTable();
      dtQr.clear();
      dtQr.rows.add(buildQrRows(rows));
      dtQr.draw(false);
    } else {
      dtQr = $tbl.DataTable({
        data: buildQrRows(rows),
        columns: [
          { title: 'To' },
          { title: 'Subject' },
          { title: 'Sent' },
          { title: 'View' }
        ],
        pageLength: 10,
        order: [[2, 'desc']],              
        autoWidth: false,
        columnDefs: [{ orderable: false, targets: [3] }]
      });
    }

    const stat = document.getElementById('qrStatSent');
    if (stat) stat.textContent = String(rows.length);

    console.log('[QR] rows:', rows.length, rows.slice(0, 5).map(r => r.id));
  }

  async function load() {
    try {
      const rows = await fetchData();
      render(rows);
    } catch (e) {
      console.error('[QR] load error:', e);
      toast(`Load failed: ${e.message}`, false);
    }
  }
  function setQrPreview(row) {
    const img = document.getElementById('vmQrImg');
    const ph  = document.getElementById('vmQrPlaceholder');
    const dl  = document.getElementById('vmQrDownload');
    if (!img || !ph || !dl) return;
    if (row && row.qr_url) {
      const bust = row.sent_at ? `?t=${encodeURIComponent(row.sent_at)}` : `?id=${encodeURIComponent(row.id)}`;
      img.src = row.qr_url + bust;
      img.style.display = '';
      ph.style.display  = 'none';
      dl.href = row.qr_url;
      dl.classList.remove('d-none');
    } else {
      img.removeAttribute('src');
      img.style.display = 'none';
      ph.style.display  = '';
      ph.textContent = 'No QR preview';
      dl.classList.add('d-none');
      dl.removeAttribute('href');
    }
  }

  document.getElementById('tblQr')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-view]');
    if (!btn) return;
    const id = +btn.getAttribute('data-view');
    try {
      const res = await fetch(`comm_view.php?id=${id}`, { cache:'no-store' });
      const j = await res.json();
      if (j.status !== 'success') throw new Error(j.message || 'Failed');
      const r = j.row;

      document.getElementById('vmId').textContent = r.id;
      document.getElementById('vmSubject').textContent = r.subject || '(no subject)';
      const toStr = `${r.recipient_name || ''}${r.recipient_email ? ` <${r.recipient_email}>` : ''}`.trim();
      document.getElementById('vmTo').textContent = toStr || '—';
      const sKey = (r.status || '').toLowerCase();
      const tone = sKey === 'sent' ? 'success' : (sKey === 'failed' ? 'danger' : 'secondary');
      document.getElementById('vmType').textContent = toLabel(r.type);
      document.getElementById('vmStatus').innerHTML = `<span class="badge text-bg-${tone}">${toLabel(sKey)}</span>`;
      document.getElementById('vmSent').textContent = fmtMDYTime12(r.sent_at) || '—';
      document.getElementById('vmRetries').textContent = r.retries ?? 0;
      document.getElementById('vmError').textContent = r.error_text || '';
      document.getElementById('vmBody').innerHTML = r.body_html || '(no body)';

      if ((r.type || '').toLowerCase() === 'qr_email' && r.qr_url) setQrPreview(r);
      else setQrPreview(null);

      new bootstrap.Modal(document.getElementById('viewModal')).show();
    } catch (err) {
      toast(`View failed: ${err.message}`, false);
    }
  });

  document.getElementById('frmFilters')?.addEventListener('submit', (e) => { e.preventDefault(); load(); });
  window.addEventListener('comm:qr:refresh', load);
  window.addEventListener('storage', (e) => { if (e.key === 'comm:qr:refresh') load(); });
  document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('tblSent')) {
      console.warn('[QR] Warning: Another communications table (#tblSent) is present. Make sure communications.js is NOT included on this page.');
    }
    ensureTableSkeleton();
    load();
  });
})();

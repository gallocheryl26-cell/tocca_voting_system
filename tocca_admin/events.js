/* events.js v7.2 — robust parsing + strict schedule validation + hard-stop on invalid */
document.addEventListener("DOMContentLoaded", () => {
  const $ = (id) => document.getElementById(id);
  const val = (id) => { const el = $(id); return el ? String(el.value || "").trim() : ""; };
  const checked = (id) => { const el = $(id); return el ? !!el.checked : false; };

  function confirmAction(options) {
    if (typeof window.adminConfirm === 'function') {
      return window.adminConfirm(options);
    }
    return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
  }

  /* ---------- Flexible datetime parsing ---------- */
  // Accepts:
  // 1) "YYYY-MM-DDTHH:MM" or "YYYY-MM-DD HH:MM[:SS]" (from datetime-local)
  // 2) "DD/MM/YYYY HH:MM am|pm"
  // 3) "DD/MM/YYYY HH:MM" (24h)
  function parseFlexibleDate(s) {
    if (!s) return null;
    const str = String(s).trim();

    // ISO-ish
    if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(str)) {
      const iso = str.replace(" ", "T");
      const d = new Date(iso);
      return isNaN(d.getTime()) ? null : d;
    }

    // DD/MM/YYYY HH:MM am/pm
    let m = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})\s*(am|pm)$/i);
    if (m) {
      let [ , dd, MM, yyyy, hh, mm, ap ] = m;
      dd = parseInt(dd,10); MM = parseInt(MM,10); yyyy = parseInt(yyyy,10);
      hh = parseInt(hh,10); mm = parseInt(mm,10); ap = ap.toLowerCase();
      if (ap === "pm" && hh < 12) hh += 12;
      if (ap === "am" && hh === 12) hh = 0;
      const d = new Date(yyyy, MM-1, dd, hh, mm, 0, 0);
      return isNaN(d.getTime()) ? null : d;
    }

    // DD/MM/YYYY HH:MM (24h)
    m = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})$/);
    if (m) {
      let [ , dd, MM, yyyy, hh, mm ] = m;
      dd = parseInt(dd,10); MM = parseInt(MM,10); yyyy = parseInt(yyyy,10);
      hh = parseInt(hh,10); mm = parseInt(mm,10);
      const d = new Date(yyyy, MM-1, dd, hh, mm, 0, 0);
      return isNaN(d.getTime()) ? null : d;
    }

    // Fallback
    const d = new Date(str);
    return isNaN(d.getTime()) ? null : d;
  }

  function pad(n){ return String(n).padStart(2,"0"); }
  function toSql(d){ // -> "YYYY-MM-DD HH:MM:SS"
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
  }

  /* ---------- Display helpers ---------- */
  const parseSql = (s) => s ? new Date(String(s).replace(" ", "T")) : null;
  const fmtDT = (sql) => {
    const d = parseSql(sql);
    if (!d || isNaN(d.getTime())) return "—";
    const date = new Intl.DateTimeFormat('en-US', { year:'numeric', month:'long', day:'numeric' }).format(d);
    const time = new Intl.DateTimeFormat('en-US', { hour:'numeric', minute:'2-digit', hour12:true }).format(d);
    return `${date} at ${time}`;
  };
  const sqlToDtl = (v) => {
    if (!v) return "";
    const [d, t] = String(v).split(" ");
    if (!d || !t) return "";
    const [hh, mm] = t.split(":");
    return `${d}T${hh}:${mm}`;
  };

  // Point registration URLs to the /nomination app using the site root (first
  // path segment) so we don't accidentally nest the folder under deeper admin
  // paths (e.g., "/TOCCA.../admin/nomination" when the real folder is
  // "/TOCCA.../nomination/"). If the site itself is rooted at "/nomination",
  // keep that as the base.
  const currentUrl = new URL(window.location.href);
  const pathWithoutFile = currentUrl.pathname.endsWith("/")
    ? currentUrl.pathname
    : currentUrl.pathname.replace(/\/[^/]*$/, "/");
  const pathSegments = pathWithoutFile.split("/").filter(Boolean);
  const siteRoot = pathSegments[0] || "";
  const nominationBasePath = siteRoot === "registration"
    ? "/nomination/"
    : `/${siteRoot ? `${siteRoot}/` : ""}nomination/`;
  const nominationBaseUrl = new URL(nominationBasePath, currentUrl.origin);
  
  const buildNominationUrl = (eventId) => {
    const url = new URL('nomination_form.php', nominationBaseUrl);
    if (eventId) url.searchParams.set('event_id', eventId);
    return url.toString();
  };

  // Build URL to the PNG QR generator for the registration form
  const buildNominationQrUrl = ({ eventId, download = false, audit = false, kind = "register" } = {}) => {
    // This file must be in:  /TOCCA_RECENT_NEWEST_2/nomination/generate_nomination_qr.php
    const url = new URL('generate_nomination_qr.php', nominationBaseUrl);

    if (eventId) {
      url.searchParams.set('event_id', eventId);
    }
    url.searchParams.set('kind', ['register', 'track', 'vote'].includes(kind) ? kind : 'register');
    if (download) {
      url.searchParams.set('download', '1');
    }
    if (audit) {
      url.searchParams.set('audit', '1');
    }

    // Cache-buster so browser doesn’t reuse an old image
    url.searchParams.set('t', Date.now().toString());

    return url.toString();
  };

  function toast(message, type = "success") {
    if (typeof window.showToast === "function") window.showToast(message, type);
    else alert(message);
  }

  /* ---------- Validation helpers ---------- */
  function clearInvalid(el) {
    if (!el) return;
    el.classList.remove("is-invalid");
    el.removeAttribute("title");
    el.setCustomValidity?.("");
  }
  function markInvalid(el, msg) {
    if (!el) return;
    el.classList.add("is-invalid");
    el.setAttribute("title", msg);
    if (el.setCustomValidity) { el.setCustomValidity(msg); el.reportValidity?.(); }
  }
  function asDateFromInput(el) {
    if (!el || !el.value) return null;
    return parseFlexibleDate(el.value);
  }

  function startOfToday() {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0, 0);
  }

  function datetimeLocalMinToday() {
    const d = startOfToday();
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T00:00`;
  }

  function applyNomStartMinAttributes() {
    const min = datetimeLocalMinToday();
    const nomStart = $("nom_start");
    if (nomStart) nomStart.min = min;
  }

  // Core schedule validator
  function validateSchedule({ nomStartEl, nomEndEl, voteStartEl, voteEndEl, requireBothPairs, existingNomStartValue }) {
    const errors = [];
    const touched = [];
    [nomStartEl, nomEndEl, voteStartEl, voteEndEl].forEach(clearInvalid);

    const ns = asDateFromInput(nomStartEl);
    const ne = asDateFromInput(nomEndEl);
    const vs = asDateFromInput(voteStartEl);
    const ve = asDateFromInput(voteEndEl);

    const hasNomAny  = !!(nomStartEl?.value || nomEndEl?.value);
    const hasVoteAny = !!(voteStartEl?.value || voteEndEl?.value);
    const hasNomFull = !!(ns && ne);
    const hasVoteFull= !!(vs && ve);

    if (hasNomAny && !hasNomFull) {
      errors.push("Registration period must have both start and end.");
      if (!ns) { markInvalid(nomStartEl, "Enter a valid Registration start (e.g., 16/10/2025 06:53 am)."); touched.push(nomStartEl); }
      if (!ne) { markInvalid(nomEndEl,   "Enter a valid Registration end."); touched.push(nomEndEl); }
    }
    if (hasVoteAny && !hasVoteFull) {
      errors.push("Voting period must have both start and end.");
      if (!vs) { markInvalid(voteStartEl, "Enter a valid Voting start (e.g., 16/10/2025 01:53 pm)."); touched.push(voteStartEl); }
      if (!ve) { markInvalid(voteEndEl,   "Enter a valid Voting end."); touched.push(voteEndEl); }
    }

    if (requireBothPairs) {
      if (!hasNomFull) {
        errors.push("Active event requires a complete Registration period.");
        if (!ns) markInvalid(nomStartEl, "Registration start is required for Active events.");
        if (!ne) markInvalid(nomEndEl,   "Registration end is required for Active events.");
      }
      if (!hasVoteFull) {
        errors.push("Active event requires a complete Voting period.");
        if (!vs) markInvalid(voteStartEl, "Voting start is required for Active events.");
        if (!ve) markInvalid(voteEndEl,   "Voting end is required for Active events.");
      }
    }

    if (hasNomFull) {
      const todayStart = startOfToday();
      const currentVal = nomStartEl?.value || "";
      const isUnchanged = !!(existingNomStartValue && currentVal === existingNomStartValue);
      if (!isUnchanged && ns < todayStart) {
        errors.push("Registration start must be today or a future date.");
        markInvalid(nomStartEl, "Must be today or later.");
        touched.push(nomStartEl);
      }
    }

    if (hasNomFull && ns >= ne) {
      errors.push("Registration end must be after Registration start.");
      markInvalid(nomEndEl, "Must be after Registration start.");
      touched.push(nomEndEl);
    }
    if (hasVoteFull && vs >= ve) {
      errors.push("Voting end must be after Voting start.");
      markInvalid(voteEndEl, "Must be after Voting start.");
      touched.push(voteEndEl);
    }

    if (hasNomFull && hasVoteFull) {
      // No overlap: voting must start on/after registration end
      if (vs < ne) {
        errors.push("Voting must start on or after the Registration end.");
        markInvalid(voteStartEl, "Start must be on/after Registration end.");
        touched.push(voteStartEl);
      }
      // To require strictly-after, change to: if (vs <= ne) { ... }
    }

    (touched.find(Boolean) || nomStartEl)?.focus?.();
    return { ok: errors.length === 0, errors };
  }

  // Clear invalid when user edits
  [
    "nom_start","nom_end","vote_start","vote_end",
    "edit_nom_start","edit_nom_end","edit_vote_start","edit_vote_end"
  ].forEach(id => {
    const el = $(id);
    if (!el) return;
    el.addEventListener("input", () => clearInvalid(el));
    el.addEventListener("change", () => clearInvalid(el));
  });

  /* ---------- Elements ---------- */
  const addEventModalEl  = $("addEventModal");

  const editEventModalEl = $("editEventModal");
  const editIdEl         = $("edit_event_id");
  const editNameEl       = $("edit_event_name");
  const editDescEl       = $("edit_event_description");
  const editActiveEl     = $("edit_event_active");
  const saveEditBtn      = $("saveEditEventBtn");

  const editNomStartEl = $("edit_nom_start");
  const editNomEndEl   = $("edit_nom_end");
  const editVoteStartEl= $("edit_vote_start");
  const editVoteEndEl  = $("edit_vote_end");

  const nominationQrModalEl   = $("nominationQrModal");
  const nominationQrImageEl   = $("nominationQrImage");
  const voteQrImageEl         = $("voteQrImage");
  const trackingQrImageEl     = $("trackingQrImage");
  const nominationQrLinkEl    = $("nominationQrLink");
  const nominationQrTitleEl   = $("nominationQrTitle");
  const nominationQrDownload  = $("downloadNominationQrBtn");
  const voteQrDownload        = $("downloadVoteQrBtn");
  const trackingQrDownload    = $("downloadTrackingQrBtn");
  const copyNominationLinkBtn = $("copyNominationLinkBtn");
  const copyVoteLinkBtn       = $("copyVoteLinkBtn");
  const copyTrackLinkBtn      = $("copyTrackLinkBtn");
  let currentNominationLink   = "";
  let currentVoteLink         = "";
  let currentTrackLink        = "";

  /*if (nominationQrImageEl) {
    nominationQrImageEl.addEventListener("error", () => toast("Failed to load registration QR.", "danger"));
  }*/

  /* ---------- Table load & actions ---------- */
  function parseSqlSafe(s){ const d = parseSql(s); return (d && !isNaN(d)) ? d : null; }
  function fmtDTsafe(sql){ const d = parseSqlSafe(sql); return d ? fmtDT(sql) : "—"; }

  function uiHelpers() {
    const UI = window.ToccaAdminUI || {};
    return {
      badge: UI.statusBadge
        ? UI.statusBadge.bind(UI)
        : (kind, label) => `<span class="badge badge-status rounded-pill text-bg-${kind === 'active' ? 'primary' : kind === 'archived' ? 'dark' : 'secondary'}">${label}</span>`,
      bar: (html) => `<div class="admin-table-actions event-table-actions" role="group">${html}</div>`,
      mkBtn: UI.btn
        ? UI.btn.bind(UI)
        : (cls, text, attrs) => `<button type="button" class="btn btn-sm ${cls}" ${attrs || ''}>${text}</button>`,
    };
  }

  function buildEventRowHtml(ev) {
    const nsF = fmtDTsafe(ev.nomination_start || "");
    const neF = fmtDTsafe(ev.nomination_end   || "");
    const vsF = fmtDTsafe(ev.voting_start     || "");
    const veF = fmtDTsafe(ev.voting_end       || "");
    const { badge, bar, mkBtn } = uiHelpers();

    let statusHtml = "";
    if (Number(ev.is_archived) === 1)      statusHtml = badge('archived', 'Archived');
    else if (Number(ev.is_active) === 1)   statusHtml = badge('active', 'Active');
    else                                   statusHtml = badge('inactive', 'Inactive');

    const evId = ev.event_id;
    const enc = encodeURIComponent;
    const escAttr = (window.ToccaAdminUI && typeof window.ToccaAdminUI.escapeHtml === "function")
      ? window.ToccaAdminUI.escapeHtml
      : (s) => String(s ?? "").replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
    const actions = (Number(ev.is_archived) === 0)
      ? bar([
          Number(ev.is_active) === 0
            ? mkBtn('btn-success activate-event-btn', 'Activate', `data-event-id="${evId}"`)
            : '',
          mkBtn('btn-info btn-qr registration-qr-btn', 'Public links',
            `data-event-id="${evId}" data-event-name="${enc(ev.event_name || '')}" data-register-url="${escAttr(ev.register_url || '')}" data-vote-url="${escAttr(ev.vote_url || '')}" data-track-url="${escAttr(ev.track_url || '')}"`),
          mkBtn('btn-edit edit-event-btn', 'Edit',
            `data-event-id="${evId}" data-event-name="${enc(ev.event_name || '')}" data-event-description="${enc(ev.description || '')}" data-event-active="${ev.is_active}" data-nom-start="${enc(ev.nomination_start || '')}" data-nom-end="${enc(ev.nomination_end || '')}" data-vote-start="${enc(ev.voting_start || '')}" data-vote-end="${enc(ev.voting_end || '')}"`),
          mkBtn('btn-danger archive-event-btn', 'Archive',
            `data-event-id="${evId}" data-event-active="${ev.is_active}"`),
        ].filter(Boolean).join(''))
      : `<span class="text-muted">No Action</span>`;

    return `
      <td>
        <div class="fw-semibold">${ev.event_name || ""}</div>
        <div class="event-schedule text-muted mt-1">
          <div><strong>Registration:</strong> ${nsF} <span class="mx-1">–</span> ${neF}</div>
          <div><strong>Voting:</strong> ${vsF} <span class="mx-1">–</span> ${veF}</div>
        </div>
      </td>
      <td>${ev.description || ""}</td>
      <td style="width:110px">${statusHtml}</td>
      <td class="actions">${actions}</td>
    `;
  }

  function renderEventsTable(events) {
    const tbody = $("eventsTableBody");
    if (!tbody) return;
    tbody.innerHTML = "";

    if (!Array.isArray(events) || events.length === 0) {
      tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted">No events found.</td></tr>`;
      return;
    }

    const frag = document.createDocumentFragment();
    events.forEach((ev) => {
      const row = document.createElement("tr");
      row.innerHTML = buildEventRowHtml(ev);
      frag.appendChild(row);
    });
    tbody.appendChild(frag);
  }

  function canonicalPublicLink(kind) {
    const links = window.TOCCA_PUBLIC_LINKS || {};
    return String(links[kind] || "").trim();
  }

  function attrPublicUrl(btn, attrName, kind) {
    const raw = (btn.getAttribute(attrName) || "").trim();
    if (raw && raw !== "=" && raw !== "—") {
      if (/^https?:\/\//i.test(raw) || raw.startsWith("/")) {
        return raw;
      }
      try {
        const decoded = decodeURIComponent(raw);
        if (/^https?:\/\//i.test(decoded) || decoded.startsWith("/")) {
          return decoded;
        }
      } catch (e) { /* ignore malformed encoding */ }
    }
    return canonicalPublicLink(kind);
  }

  function bindEventsTableActions() {
    const tbody = $("eventsTableBody");
    if (!tbody || tbody.dataset.actionsBound === "1") return;
    tbody.dataset.actionsBound = "1";

    tbody.addEventListener("click", (event) => {
      const btn = event.target.closest("button");
      if (!btn || !tbody.contains(btn)) return;

      if (btn.classList.contains("activate-event-btn")) {
        const event_id = btn.getAttribute("data-event-id");
        if (!event_id) return;

        confirmAction({
          title: 'Activate event',
          message: 'Activate this event? It will become the active event for registrations and voting.',
          confirmLabel: 'Activate',
          confirmClass: 'btn-primary',
        }).then((confirmed) => {
          if (!confirmed) return;

          fetch("event.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "activate", event_id })
          })
          .then(r => r.json())
          .then((res) => {
            if (res.status === "success") { toast("Event activated successfully.","success"); loadEvents(); }
            else toast(res.message || "Failed to activate.","danger");
          })
          .catch((e) => { console.error(e); toast("Activation failed.","danger"); });
        });
        return;
      }

      if (btn.classList.contains("archive-event-btn")) {
        const event_id = btn.getAttribute("data-event-id");
        const isActive = Number(btn.getAttribute("data-event-active") || 0);
        if (!event_id) return;

        const msg = (isActive === 1)
          ? 'This event is currently active. Archiving it will hide it from the public and disable its registration and voting links. Continue?'
          : 'Archive this event? It will be moved out of the active events list.';

        confirmAction({
          title: 'Archive event',
          message: msg,
          confirmLabel: 'Archive',
          confirmClass: 'btn-warning',
        }).then((confirmed) => {
          if (!confirmed) return;

          fetch("event.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "archive", event_id })
          })
          .then(r => r.json())
          .then((res) => {
            if (res.status === "success") { toast("Event archived successfully.","success"); loadEvents(); }
            else toast(res.message || "Failed to archive.","danger");
          })
          .catch((e) => { console.error(e); toast("Archive failed.","danger"); });
        });
        return;
      }

      if (btn.classList.contains("edit-event-btn")) {
        const id   = btn.getAttribute("data-event-id") || "";
        const name = decodeURIComponent(btn.getAttribute("data-event-name") || "");
        const desc = decodeURIComponent(btn.getAttribute("data-event-description") || "");
        const act  = Number(btn.getAttribute("data-event-active") || 0);
        const ns = decodeURIComponent(btn.getAttribute("data-nom-start") || "");
        const ne = decodeURIComponent(btn.getAttribute("data-nom-end")   || "");
        const vs = decodeURIComponent(btn.getAttribute("data-vote-start")|| "");
        const ve = decodeURIComponent(btn.getAttribute("data-vote-end")  || "");

        if (editIdEl)      editIdEl.value = id;
        if (editNameEl)    editNameEl.value = name;
        if (editDescEl)    editDescEl.value = desc;
        if (editActiveEl)  editActiveEl.checked = !!act;
        if (editNomStartEl) {
          const dtlNs = sqlToDtl(ns) || ns || "";
          editNomStartEl.value = dtlNs;
          editNomStartEl.dataset.originalValue = dtlNs;
        }
        if (editNomEndEl)   editNomEndEl.value   = sqlToDtl(ne) || ne || "";
        if (editVoteStartEl)editVoteStartEl.value= sqlToDtl(vs) || vs || "";
        if (editVoteEndEl)  editVoteEndEl.value  = sqlToDtl(ve) || ve || "";

        (bootstrap.Modal.getInstance(editEventModalEl) || new bootstrap.Modal(editEventModalEl)).show();
        return;
      }

      if (btn.classList.contains("registration-qr-btn")) {
        const id   = btn.getAttribute("data-event-id") || "";
        const name = decodeURIComponent(btn.getAttribute("data-event-name") || "");
        const registerUrl = attrPublicUrl(btn, "data-register-url", "register");
        const voteUrl = attrPublicUrl(btn, "data-vote-url", "vote");
        const trackUrl = attrPublicUrl(btn, "data-track-url", "track");
        showNominationQr(id, name, registerUrl, voteUrl, trackUrl);
      }
    });
  }

  function loadEvents() {
    fetch("event.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "load_all" })
    })
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success" || !Array.isArray(data.events)) {
        renderEventsTable([]);
        return;
      }
      renderEventsTable(data.events);
    })
    .catch((e) => { console.error("load_all error:", e); toast("Failed to load events.","danger"); });
  }

  function showNominationQr(eventId, eventName, registerUrl, voteUrl, trackUrl) {
    const url = registerUrl || canonicalPublicLink("register");
    const vUrl = voteUrl || canonicalPublicLink("vote");
    const tUrl = trackUrl || canonicalPublicLink("track");
    currentNominationLink = url;
    currentVoteLink = vUrl;
    currentTrackLink = tUrl;

    if (nominationQrTitleEl) {
      nominationQrTitleEl.textContent = eventName ? `Public links – ${eventName}` : "Public links";
    }
    if (nominationQrLinkEl) {
      nominationQrLinkEl.textContent = url;
      nominationQrLinkEl.setAttribute("href", url);
    }

    const voteLinkEl = $("eventVoteLink");
    if (voteLinkEl) {
      voteLinkEl.textContent = vUrl || "—";
      voteLinkEl.setAttribute("href", vUrl || "#");
      voteLinkEl.classList.toggle("disabled", !vUrl);
    }
    const trackLinkEl = $("eventTrackLink");
    if (trackLinkEl) {
      trackLinkEl.textContent = tUrl || "—";
      trackLinkEl.setAttribute("href", tUrl || "#");
      trackLinkEl.classList.toggle("disabled", !tUrl);
    }

    const qrUrl = buildNominationQrUrl({ eventId, kind: "register" });
    const voteQrUrl = buildNominationQrUrl({ eventId, kind: "vote" });
    const trackQrUrl = buildNominationQrUrl({ eventId, kind: "track" });

    if (nominationQrImageEl) {
      nominationQrImageEl.src = qrUrl;
      nominationQrImageEl.alt = `Registration QR for ${eventName || "Registration"}`;
    }
    if (voteQrImageEl) {
      voteQrImageEl.src = voteQrUrl;
      voteQrImageEl.alt = `Main voting QR for ${eventName || "Voting"}`;
    }
    if (trackingQrImageEl) {
      trackingQrImageEl.src = trackQrUrl;
      trackingQrImageEl.alt = `Tracking QR for ${eventName || "Tracking"}`;
    }
    if (nominationQrDownload) {
      nominationQrDownload.setAttribute(
        "href",
        buildNominationQrUrl({ eventId, download: true, kind: "register" })
      );
    }
    if (voteQrDownload) {
      voteQrDownload.setAttribute(
        "href",
        buildNominationQrUrl({ eventId, download: true, kind: "vote" })
      );
    }
    if (trackingQrDownload) {
      trackingQrDownload.setAttribute(
        "href",
        buildNominationQrUrl({ eventId, download: true, kind: "track" })
      );
    }

    (bootstrap.Modal.getInstance(nominationQrModalEl) || new bootstrap.Modal(nominationQrModalEl)).show();
  }

  /* ---------- CREATE ---------- */
  const createBtnEl = $("createEventBtn");
  if (createBtnEl) {
    createBtnEl.addEventListener("click", (evt) => {
      const name        = val("event_name");
      const description = val("event_description");
      const isActive    = checked("event_active") ? 1 : 0;
      if (!name) { toast("Event name is required.","warning"); evt.preventDefault(); evt.stopPropagation(); return; }

      const nomStartEl = $("nom_start");
      const nomEndEl   = $("nom_end");
      const voteStartEl= $("vote_start");
      const voteEndEl  = $("vote_end");

      const v = validateSchedule({
        nomStartEl, nomEndEl, voteStartEl, voteEndEl,
        requireBothPairs: isActive === 1,
        existingNomStartValue: null
      });

      // DEBUG
      console.log("[CREATE] values:", {
        nom_start: nomStartEl?.value, nom_end: nomEndEl?.value,
        vote_start: voteStartEl?.value, vote_end: voteEndEl?.value,
        valid: v.ok, errors: v.errors
      });

      if (!v.ok) {
        toast(v.errors[0] || "Fix the highlighted schedule fields.","warning");
        evt.preventDefault(); evt.stopPropagation(); // HARD STOP
        return;
      }

      const ns = asDateFromInput(nomStartEl);
      const ne = asDateFromInput(nomEndEl);
      const vs = asDateFromInput(voteStartEl);
      const ve = asDateFromInput(voteEndEl);

      const payload = {
        action: "add_event",
        event_name: name,
        description,
        is_active: isActive,
        nomination_start: ns ? toSql(ns) : "",
        nomination_end:   ne ? toSql(ne) : "",
        voting_start:     vs ? toSql(vs) : "",
        voting_end:       ve ? toSql(ve) : ""
      };

      createBtnEl.disabled = true;
      fetch("event.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
      .then(r => r.json())
      .then((res) => {
        if (res.status === "success") {
          (bootstrap.Modal.getInstance(addEventModalEl) || new bootstrap.Modal(addEventModalEl)).hide();
          ["event_name","event_description","nom_start","nom_end","vote_start","vote_end"].forEach(id => { const el = $(id); if (el){ el.value=""; clearInvalid(el);} });
          const act = $("event_active"); if (act) act.checked = true;
          toast("Event created successfully!","success");
          if (res.needs_form_setup) toast("Registration period saved. Please configure the Registration Form under File Maintenance.","info");
          loadEvents();
        } else if (res.status === "duplicate") {
          toast("An event with this name already exists for that year.","warning");
        } else {
          toast(res.message || "Create failed.","danger");
        }
      })
      .catch((e) => { console.error("Create error:", e); toast("An unexpected error occurred.","danger"); })
      .finally(() => { createBtnEl.disabled = false; });
    });
  }

  /* ---------- SAVE EDIT ---------- */
  if (saveEditBtn) {
    saveEditBtn.addEventListener("click", (evt) => {
      const id    = editIdEl?.value || "";
      const name  = String(editNameEl?.value || "").trim();
      const desc  = String(editDescEl?.value || "").trim();
      const isAct = editActiveEl?.checked ? 1 : 0;

      if (!id || !name) { toast("Event ID and name are required.","warning"); evt.preventDefault(); evt.stopPropagation(); return; }

      const v = validateSchedule({
        nomStartEl: editNomStartEl,
        nomEndEl:   editNomEndEl,
        voteStartEl:editVoteStartEl,
        voteEndEl:  editVoteEndEl,
        requireBothPairs: isAct === 1,
        existingNomStartValue: editNomStartEl?.dataset.originalValue || null
      });

      // DEBUG
      console.log("[EDIT] values:", {
        nom_start: editNomStartEl?.value, nom_end: editNomEndEl?.value,
        vote_start: editVoteStartEl?.value, vote_end: editVoteEndEl?.value,
        valid: v.ok, errors: v.errors
      });

      if (!v.ok) {
        toast(v.errors[0] || "Fix the highlighted schedule fields.","warning");
        evt.preventDefault(); evt.stopPropagation(); // HARD STOP
        return;
      }

      const ns = asDateFromInput(editNomStartEl);
      const ne = asDateFromInput(editNomEndEl);
      const vs = asDateFromInput(editVoteStartEl);
      const ve = asDateFromInput(editVoteEndEl);

      const payload = {
        action: "update_event",
        event_id: id,
        event_name: name,
        description: desc,
        is_active: isAct,
        nomination_start: ns ? toSql(ns) : "",
        nomination_end:   ne ? toSql(ne) : "",
        voting_start:     vs ? toSql(vs) : "",
        voting_end:       ve ? toSql(ve) : ""
      };

      saveEditBtn.disabled = true;
      fetch("event.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
      .then(r => r.json())
      .then((res) => {
        if (res.status === "success") {
          (bootstrap.Modal.getInstance(editEventModalEl) || new bootstrap.Modal(editEventModalEl)).hide();
          toast("Event updated successfully!","success");
          if (res.needs_form_setup) toast("Registration period saved. Please configure the Registration Form under File Maintenance.","info");
          loadEvents();
        } else if (res.status === "duplicate") {
          toast("Another event with the same name already exists for that year.","warning");
        } else {
          toast(res.message || "Update failed.","danger");
        }
      })
     .catch((e) => { console.error("Update error:", e); toast("Update failed.","danger"); })
      .finally(() => { saveEditBtn.disabled = false; });
    });
  }

  function copyTextToClipboard(text, successMessage) {
    if (!text) return;
    const fallbackCopy = () => {
      try {
        const ta = document.createElement("textarea");
        ta.value = text;
        ta.style.position = "fixed";
        ta.style.opacity = "0";
        document.body.appendChild(ta);
        ta.select();
        document.execCommand("copy");
        document.body.removeChild(ta);
        toast(successMessage);
      } catch (err) {
        console.error(err);
        toast("Unable to copy link.", "danger");
      }
    };

    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text)
        .then(() => toast(successMessage))
        .catch(() => fallbackCopy());
    } else {
      fallbackCopy();
    }
  }

  if (copyNominationLinkBtn) {
    copyNominationLinkBtn.addEventListener("click", () => {
      copyTextToClipboard(currentNominationLink, "Registration link copied to clipboard.");
    });
  }
  if (copyVoteLinkBtn) {
    copyVoteLinkBtn.addEventListener("click", () => {
      copyTextToClipboard(currentVoteLink, "Voting link copied to clipboard.");
    });
  }
  if (copyTrackLinkBtn) {
    copyTrackLinkBtn.addEventListener("click", () => {
      copyTextToClipboard(currentTrackLink, "Tracking link copied to clipboard.");
    });
  }

  applyNomStartMinAttributes();

  if (addEventModalEl) {
    addEventModalEl.addEventListener("show.bs.modal", applyNomStartMinAttributes);
  }

  bindEventsTableActions();
  const eventsTableBody = $("eventsTableBody");
  if (!eventsTableBody?.dataset.ssr) {
    loadEvents();
  }
});

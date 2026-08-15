(function () {
  'use strict';

  const API_URL = 'get_notifications.php?limit=20';
  const DELETE_API_URL = 'delete_notification.php';
  const SUMMARY_API_URL = 'admin_notification.php';
  const LIVE_STREAM_URL = 'notifications_stream.php';
  const LIVE_STREAM_RETRY_MS = 5_000;
  const REFRESH_INTERVAL_MS = 30_000;
  const NOTIFICATION_WARMUP_DELAY_MS = 4_000;
  const NEWNESS_THRESHOLD_MS = 86_400_000; // 24 hours
  const URGENT_THRESHOLD_SECONDS = 172_800; // 48 hours
  const STORAGE_KEYS = {
    READ_AUDIT_IDS: 'toccaAdmin|notifReadAuditIds.v1',
  };
  const MAX_STORED_READ_IDS = 200;
  const SYSTEM_ALERT_ACTIONS = new Set([
    'system_pending_nomination',
    'system_nomination_start',
    'system_nomination_deadline',
    'system_voting_start',
    'system_voting_end',
    'system_pending_deadline',
    'system_nomination_submission',
  ]);

  const NOTIFICATION_GROUPS = [
    {
      key: 'action',
      title: 'Action required',
      description: 'Pending registrations that need review before deadlines.',
      actions: ['system_pending_nomination', 'system_pending_deadline'],
    },
    {
      key: 'submissions',
      title: 'New submissions',
      description: 'Recently submitted registrations awaiting review.',
      actions: ['system_nomination_submission'],
    },
    {
      key: 'nomination_schedule',
      title: 'Registration schedule',
      description: 'Opening and closing milestones for registrations.',
      actions: ['system_nomination_start', 'system_nomination_deadline'],
    },
    {
      key: 'voting_schedule',
      title: 'Voting schedule',
      description: 'Ballot opening and closing reminders.',
      actions: ['system_voting_start', 'system_voting_end'],
    },
  ];

  let lastFetch = 0;
  let isFetching = false;
  let badgeEl = null;
  let menuBodyEl = null;
  let summaryEl = null;
  let refreshBtn = null;
  let manageListEl = null;
  let manageHighlightsEl = null;
  let manageCountEl = null;
  let manageUpdatedEl = null;
  let dropdownSummaryEl = null;
  let dropdownCountEl = null;
  let lastRenderedFingerprint = '';
  let lastSummaryData = null;
  let readAuditIds = new Set();
  let isStorageReady = false;
  let lastSeenMaxAuditId = null;
  let hasAuditBaseline = false;
  let liveStreamSource = null;
  let liveStreamRestartTimer = null;
  const deletingAuditIds = new Set();
  let lastRenderAt = null;
  let notificationsActivated = false;
  let notificationWarmupTimer = null;
  let pendingForcedRefresh = false;
  const toastState = {
    queue: [],
    active: false,
    elements: null,
    hiddenHandler: null,
    navigateHref: '',
  };

  const RELATIVE_FORMATTER = (typeof Intl !== 'undefined' && Intl.RelativeTimeFormat)
    ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
    : null;
  const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
  ];


  function activateNotifications(forceLoad) {
    notificationsActivated = true;
    if (notificationWarmupTimer) {
      clearTimeout(notificationWarmupTimer);
      notificationWarmupTimer = null;
    }
    if (forceLoad) {
      loadNotifications({ force: true });
    }
  }

  function scheduleDeferredNotificationWarmup() {
    const run = () => {
      if (notificationsActivated) {
        return;
      }
      activateNotifications(true);
    };

    if (typeof requestIdleCallback === 'function') {
      requestIdleCallback(() => {
        notificationWarmupTimer = setTimeout(run, 500);
      }, { timeout: NOTIFICATION_WARMUP_DELAY_MS });
      return;
    }

    notificationWarmupTimer = setTimeout(run, NOTIFICATION_WARMUP_DELAY_MS);
  }

  function init() {
    if (init._done) {
      return;
    }
    init._done = true;

    badgeEl = document.getElementById('notifBellBadge');
    menuBodyEl = document.getElementById('notifMenuBody');
    summaryEl = document.getElementById('notifSummary');
    refreshBtn = document.getElementById('notifRefreshBtn');
    manageListEl = document.getElementById('notifCenterList');
    manageHighlightsEl = document.getElementById('notifHighlightsPanel');
    manageCountEl = document.getElementById('notifCenterCount');
    manageUpdatedEl = document.getElementById('notifLastUpdated');
    dropdownSummaryEl = document.getElementById('notifDropdownSummary');
    dropdownCountEl = document.getElementById('notifDropdownCount');
    if (manageUpdatedEl) {
      setManagementStatusText('Loading…');
    }
    const triggerEl = document.getElementById('notifBellBtn');

    if (!badgeEl || !menuBodyEl) {
      return;
    }

    initializeNotificationReadState();

    hideBadge();
    menuBodyEl.innerHTML = '<div class="text-center text-muted py-4 small">Open to view notifications.</div>';

    if (triggerEl) {
      triggerEl.addEventListener('show.bs.dropdown', () => {
        activateNotifications(true);
      });
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => {
        setManagementStatusText('Refreshing…');
        loadNotifications({ force: true, manual: true });
      });
    }

    if (menuBodyEl) {
      menuBodyEl.addEventListener('click', handleNotificationEntryClick, true);
      menuBodyEl.addEventListener('keydown', handleNotificationEntryKeyDown, true);
    }

    if (manageListEl) {
      manageListEl.addEventListener('click', handleNotificationEntryClick, true);
      manageListEl.addEventListener('keydown', handleNotificationEntryKeyDown, true);
    }

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        pauseNotificationLiveStream();
        return;
      }

      if (!notificationsActivated) {
        return;
      }

      ensureNotificationLiveUpdates();
      loadNotifications({ force: true });
    });

    window.addEventListener('beforeunload', () => {
      pauseNotificationLiveStream();
    });

    window.addEventListener('focus', () => {
      if (!notificationsActivated) {
        return;
      }

      ensureNotificationLiveUpdates();
      loadNotifications({ force: true });
    });

    setInterval(() => {
      if (!document.hidden && notificationsActivated) {
        ensureNotificationLiveUpdates();
        loadNotifications();
      }
    }, REFRESH_INTERVAL_MS);
  }

  async function loadNotifications(options = {}) {
    const { force = false, manual = false } = options;

    if (isFetching) {
      if (force) {
        pendingForcedRefresh = true;
      }
      return;
    }

    if (!force && Date.now() - lastFetch < 2000) {
      return;
    }

    isFetching = true;
    lastFetch = Date.now();

    setLoadingState(manual ? 'Refreshing…' : null);
    setRefreshBusy(true);

    try {
      const summaryData = await fetchSummaryData();
      lastSummaryData = summaryData;
      const response = await fetch(API_URL, { cache: 'no-store' });
      if (!response.ok) {
        throw new Error('Bad response');
      }

      const payload = await response.json();
      if (!payload || payload.status !== 'success' || !payload.data || !Array.isArray(payload.data.notifications)) {
        throw new Error('Malformed payload');
      }

      renderNotifications(payload.data.notifications || [], summaryData);
    } catch (error) {
      renderError();
      console.error('Notification fetch failed:', error);
    } finally {
      isFetching = false;
      setRefreshBusy(false);

      if (pendingForcedRefresh) {
        pendingForcedRefresh = false;
        loadNotifications({ force: true });
      }
    }
  }

  async function fetchSummaryData() {
    try {
      const response = await fetch(SUMMARY_API_URL, { cache: 'no-store' });
      if (!response.ok) {
        throw new Error('Bad response');
      }

      const payload = await response.json();
      if (!payload || payload.status !== 'success' || !payload.data || typeof payload.data !== 'object') {
        throw new Error('Malformed payload');
      }

      return payload.data;
    } catch (error) {
      console.warn('Summary fetch failed:', error);
      return lastSummaryData || null;
    }
  }

  function setLoadingState(message) {
    if (!menuBodyEl) {
      return;
    }

    if (!message && menuBodyEl.dataset.state === 'rendered') {
      return;
    }

    const text = message || 'Loading…';
    menuBodyEl.innerHTML = `<div class="text-center text-muted py-3">${escapeHtml(text)}</div>`;
    if (message) {
      setManagementStatusText(message);
    }
  }

  function renderError() {
    if (menuBodyEl) {
      menuBodyEl.innerHTML = '<div class="text-center py-2 text-danger">Unable to load notifications.</div>';
    }
    hideBadge();
    updateSummary(0, 0, lastSummaryData);
    updateManagementError('Unable to load notifications.');
    setManagementStatusText('Update failed');
  }

  function setRefreshBusy(isBusy) {
    if (!refreshBtn) {
      return;
    }
    refreshBtn.disabled = Boolean(isBusy);
    if (isBusy) {
      refreshBtn.setAttribute('aria-busy', 'true');
    } else {
      refreshBtn.removeAttribute('aria-busy');
    }
  }

  function renderNotifications(items, summary) {
    const highlights = buildHighlights(summary);
    const summaryFingerprint = buildSummaryFingerprint(summary, highlights);
    const filtered = Array.isArray(items)
      ? items.filter(item => SYSTEM_ALERT_ACTIONS.has(String(item?.action || '').toLowerCase()))
      : [];
    if (filtered.length === 0) {
      if (menuBodyEl) {
        menuBodyEl.dataset.state = 'rendered';
        menuBodyEl.innerHTML = '<div class="notif-empty text-muted">No notifications yet.</div>';
      }
      hideBadge();
      updateSummary(0, 0, summary, highlights);
      updateManagementView([], highlights, '');
      lastRenderAt = new Date();
      setManagementTimestamp(lastRenderAt);
      lastRenderedFingerprint = summaryFingerprint;
      return;
    }

    const enriched = filtered.map(enrichNotification);
    processNewNotificationAlerts(enriched);
    const recentCount = enriched.filter(item => item._isRecent).length;
    const unreadItems = enriched.filter(item => item._isUnread && item.audit_id);
    const unreadCount = unreadItems.length;
    const fingerprint = `${
      summaryFingerprint
    }|${
      enriched.map(item => `${item.audit_id}-${item.created_at}`).join('|')
    }|u:${unreadItems.map(item => item.audit_id).join(',')}`;

    if (badgeEl) {
      const badgeCount = unreadCount;
      badgeEl.classList.toggle('d-none', badgeCount === 0);
      badgeEl.textContent = badgeCount > 99 ? '99+' : String(badgeCount);
    }

    updateSummary(enriched.length, recentCount, summary, highlights);

    if (fingerprint === lastRenderedFingerprint && menuBodyEl?.dataset?.state === 'rendered') {
      lastRenderAt = new Date();
      setManagementTimestamp(lastRenderAt);
      refreshRelativeTimes();
      return;
    }

    lastRenderedFingerprint = fingerprint;

    const groupedHtml = buildGroupedNotificationsHtml(enriched);
    const highlightMarkup = renderHighlights(highlights);
    if (menuBodyEl) {
      menuBodyEl.dataset.state = 'rendered';
      const parts = [];
      if (highlightMarkup) {
        parts.push(highlightMarkup);
      }
      parts.push(groupedHtml);
      menuBodyEl.innerHTML = parts.join('');
    }
    updateManagementView(enriched, highlights, groupedHtml);
    lastRenderAt = new Date();
    setManagementTimestamp(lastRenderAt);
  }

  function getNotificationGroupKey(action) {
    const normalized = String(action || '').toLowerCase();
    for (const group of NOTIFICATION_GROUPS) {
      if (group.actions.includes(normalized)) {
        return group.key;
      }
    }
    return 'other';
  }

  function buildGroupedNotificationsHtml(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return '<div class="notif-empty text-muted">No notifications yet.</div>';
    }

    const buckets = new Map();
    NOTIFICATION_GROUPS.forEach(group => buckets.set(group.key, []));
    buckets.set('other', []);

    items.forEach(item => {
      const key = getNotificationGroupKey(item?.action);
      if (!buckets.has(key)) {
        buckets.set(key, []);
      }
      buckets.get(key).push(item);
    });

    const sections = [];
    NOTIFICATION_GROUPS.forEach(group => {
      const groupItems = buckets.get(group.key) || [];
      if (groupItems.length === 0) {
        return;
      }

      const itemsHtml = groupItems.map(renderNotificationItem).join('');
      sections.push(`
        <section class="notif-group" aria-labelledby="notif-group-${group.key}">
          <div class="notif-group-header" id="notif-group-${group.key}">
            <span class="fw-semibold">${escapeHtml(group.title)}</span>
            <span class="badge rounded-pill text-bg-light border">${groupItems.length}</span>
          </div>
          ${group.description ? `<div class="notif-group-desc small text-muted px-3 pb-1">${escapeHtml(group.description)}</div>` : ''}
          <div class="list-group list-group-flush">${itemsHtml}</div>
        </section>
      `);
    });

    const otherItems = buckets.get('other') || [];
    if (otherItems.length > 0) {
      const itemsHtml = otherItems.map(renderNotificationItem).join('');
      sections.push(`
        <section class="notif-group" aria-labelledby="notif-group-other">
          <div class="notif-group-header" id="notif-group-other">
            <span class="fw-semibold">Other alerts</span>
            <span class="badge rounded-pill text-bg-light border">${otherItems.length}</span>
          </div>
          <div class="list-group list-group-flush">${itemsHtml}</div>
        </section>
      `);
    }

    return sections.join('');
  }

  function buildHighlights(summary) {
    if (!summary || typeof summary !== 'object') {
      return [];
    }

    const highlights = [];

    const windowHoursValue = toFiniteNumber(summary.recent_window_hours);
    const windowHours = windowHoursValue && windowHoursValue > 0 ? windowHoursValue : 24;
    const recentPendingSource = Object.prototype.hasOwnProperty.call(summary, 'unapproved_recent')
      ? summary.unapproved_recent
      : summary.recent_pending;
    const recentPendingCount = toFiniteNumber(recentPendingSource);
    const recentCount = recentPendingCount !== null
      ? recentPendingCount
      : toFiniteNumber(summary.recent_submissions);
    const nominationSecondsRaw = summary.nomination?.seconds_remaining ?? summary.event?.nomination_seconds_left;
    const nominationSeconds = toFiniteNumber(nominationSecondsRaw);
    const nominationIso = summary.nomination?.deadline_iso || summary.event?.nomination_end_iso || null;
    const nominationDeadlineDate = nominationIso ? parseDate(nominationIso) : null;
    const nominationDeadlineRelative = nominationDeadlineDate ? formatRelativeTime(nominationDeadlineDate) : '';
    const nominationDeadlineAbsolute = nominationDeadlineDate ? formatAbsoluteTime(nominationDeadlineDate) : '';
    const pendingDeadlineWarningSource = Object.prototype.hasOwnProperty.call(summary, 'unapproved_deadline_warning')
      ? summary.unapproved_deadline_warning
      : summary.pending_deadline_warning;
    const pendingDeadlineWarning = Boolean(pendingDeadlineWarningSource);
    if (recentCount !== null) {
      const windowLabel = windowHours === 24 ? '24 hours' : `${windowHours} hours`;
      const hasNew = recentCount > 0;
      highlights.push({
        key: 'recent',
        icon: 'bi bi-person-plus-fill',
        tone: hasNew ? 'primary' : 'secondary',
        title: 'New registrations',
        description: hasNew
          ? `${recentCount} ${pluralize('registration', recentCount)} in the last ${windowLabel}.`
          : `No new registrations recorded in the last ${windowLabel}.`,
        meta: `Last ${windowLabel}`,
        urgent: false,
        href: 'nominations.php',
        cta: hasNew
          ? 'Review them in Registration.'
          : 'Browse all entries in Registration.',
      });
    }

    const pendingCountSource = Object.prototype.hasOwnProperty.call(summary, 'unapproved')
      ? summary.unapproved
      : summary.pending;
    const pendingCount = toFiniteNumber(pendingCountSource);
    if (pendingCount !== null) {
      const hasPending = pendingCount > 0;
     const deadlineIsTight = Number.isFinite(nominationSeconds)
        ? (nominationSeconds <= URGENT_THRESHOLD_SECONDS && nominationSeconds >= 0)
        : false;
      const deadlineIsPassed = Number.isFinite(nominationSeconds) && nominationSeconds < 0;
      const needsUrgentTone = hasPending && (pendingDeadlineWarning || deadlineIsTight || deadlineIsPassed);

      let deadlineNote = '';
      if (needsUrgentTone) {
        if (deadlineIsPassed) {
          deadlineNote = nominationDeadlineRelative
            ? ` Deadline passed ${nominationDeadlineRelative}.`
            : ' Deadline has passed.';
        } else if (nominationDeadlineRelative) {
          deadlineNote = ` Deadline ${nominationDeadlineRelative}.`;
        } else if (nominationDeadlineAbsolute) {
          deadlineNote = ` Deadline on ${nominationDeadlineAbsolute}.`;
        } else {
          deadlineNote = ' Deadline approaching.';
        }
      }
      highlights.push({
        key: 'pending',
        icon: 'bi bi-inbox-fill',
        tone: hasPending
          ? (needsUrgentTone ? 'danger' : 'warning')
          : 'success',
        title: 'Unapproved registrations',
        description: hasPending
          ? `${pendingCount} ${pluralize('registration', pendingCount)} awaiting approval.${deadlineNote}`.trim()
          : 'All registrations are approved or declined.',
        urgent: hasPending && (needsUrgentTone || pendingCount > 0),
        href: 'nominations.php',
        cta: hasPending
          ? (needsUrgentTone
              ? 'Approve or request updates before the deadline.'
              : 'Finish reviewing registrations in the admin panel.')
          : 'Keep an eye on Registration for new submissions.',
      });
    }

    if (Number.isFinite(nominationSeconds) || nominationIso) {

      let description = 'Deadline details unavailable.';
      let urgent = false;

      if (Number.isFinite(nominationSeconds)) {
        if (nominationSeconds < 0) {
          description = nominationDeadlineRelative ? `Ended ${nominationDeadlineRelative}` : 'Deadline has passed.';
          urgent = true;
        } else {
          description = nominationDeadlineRelative ? `Ends ${nominationDeadlineRelative}` : 'Deadline approaching.';
          urgent = nominationSeconds <= URGENT_THRESHOLD_SECONDS;
        }
      } else if (nominationDeadlineRelative) {
        description = `Ends ${nominationDeadlineRelative}`;
      }

      highlights.push({
        key: 'registration',
        icon: 'bi bi-hourglass-split',
        tone: urgent ? 'warning' : 'info',
        title: 'Registration deadline',
        description,
        time: nominationDeadlineDate
          ? {
              iso: nominationDeadlineDate.toISOString(),
              label: nominationDeadlineAbsolute,
              relative: nominationDeadlineRelative || nominationDeadlineAbsolute,
            }
          : null,
        urgent,
        href: 'events.php',
        cta: 'Edit this deadline in Events.',
      });
    }

    const voting = summary.voting || {};
    const votingStatusDetail = String(voting.status || '').toLowerCase();
    const startDate = parseDate(voting.start_iso || voting.start || '');
    const endDate = parseDate(voting.end_iso || voting.end || '');
    const secondsUntilStart = toFiniteNumber(voting.seconds_until_start);
    const secondsUntilEnd = toFiniteNumber(voting.seconds_until_end);

    let votingDescription = '';
    let votingTone = 'info';
    let votingTime = null;
    let votingUrgent = false;

    if (votingStatusDetail === 'upcoming') {
      const rel = startDate ? formatRelativeTime(startDate) : '';
      const absolute = startDate ? formatAbsoluteTime(startDate) : '';
      votingDescription = rel ? `Starts ${rel}` : 'Voting is scheduled soon.';
      votingTime = startDate
        ? {
            iso: startDate.toISOString(),
            label: absolute,
            relative: rel || absolute,
          }
        : null;
      votingTone = 'primary';
      votingUrgent = Number.isFinite(secondsUntilStart)
        ? (secondsUntilStart <= URGENT_THRESHOLD_SECONDS && secondsUntilStart >= 0)
        : false;
    } else if (votingStatusDetail === 'ongoing') {
      if (Number.isFinite(secondsUntilEnd) && secondsUntilEnd >= 0 && endDate) {
        const rel = formatRelativeTime(endDate);
        const absolute = formatAbsoluteTime(endDate);
        votingDescription = rel ? `Ends ${rel}` : 'Voting is underway.';
        votingTime = {
          iso: endDate.toISOString(),
          label: absolute,
          relative: rel || absolute,
        };
        votingUrgent = secondsUntilEnd <= URGENT_THRESHOLD_SECONDS;
      } else {
        votingDescription = 'Voting is underway.';
        if (endDate) {
          const absolute = formatAbsoluteTime(endDate);
          votingTime = {
            iso: endDate.toISOString(),
            label: absolute,
            relative: absolute,
          };
        }
      }
      votingTone = 'success';
    } else if (votingStatusDetail === 'ended') {
      const target = endDate || startDate;
      const rel = target ? formatRelativeTime(target) : '';
      const absolute = target ? formatAbsoluteTime(target) : '';
      votingDescription = rel ? `Ended ${rel}` : 'Voting period has ended.';
      votingTime = target
        ? {
            iso: target.toISOString(),
            label: absolute,
            relative: rel || absolute,
          }
        : null;
      votingTone = 'secondary';
    } else {
      votingDescription = 'Voting schedule not set yet.';
      votingTone = 'secondary';
    }

    if (votingDescription) {
      const metaParts = [];
      if (startDate) {
        const formatted = formatAbsoluteTime(startDate);
        if (formatted) metaParts.push(formatted);
      }
      if (endDate) {
        const formatted = formatAbsoluteTime(endDate);
        if (formatted) metaParts.push(formatted);
      }

      highlights.push({
        key: 'voting',
        icon: 'bi bi-megaphone-fill',
        tone: votingTone,
        title: 'Voting schedule',
        description: votingDescription,
        time: votingTime,
        meta: metaParts.join(' – '),
        urgent: votingUrgent,
        href: 'events.php',
        cta: 'Adjust the voting dates in Events.',
      });
    }

    return highlights;
  }

  function renderHighlights(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return '';
    }
    const markup = items.map(renderHighlight).join('');
    return `<div class="notif-highlights" role="presentation">${markup}</div>`;
  }

  function renderHighlight(item) {
    if (!item) {
      return '';
    }
    const classes = ['notif-highlight'];
    if (item.urgent) {
      classes.push('notif-highlight-urgent');
    }
    const isLink = Boolean(item?.href);
    if (isLink) {
      classes.push('notif-highlight-action');
    }

    const toneAttr = item.tone ? ` data-tone="${escapeHtml(item.tone)}"` : '';
    const iconClass = item.tone ? `${item.icon} text-${item.tone}` : item.icon;
    const descriptionHtml = item.description
      ? `<div class="text-muted small">${escapeHtml(item.description)}</div>`
      : '';

    let timeHtml = '';
    if (item.time && item.time.iso && item.time.relative) {
      const titleAttr = item.time.label ? ` title="${escapeHtml(item.time.label)}"` : '';
      timeHtml = `<div class="text-muted small"><time datetime="${escapeHtml(item.time.iso)}"${titleAttr}>${escapeHtml(item.time.relative)}</time></div>`;
    }

    const metaHtml = item.meta
      ? `<div class="text-muted small">${escapeHtml(item.meta)}</div>`
      : '';

    const ctaHtml = item.cta
      ? `<div class="text-primary small fw-semibold">${escapeHtml(item.cta)}</div>`
      : '';

    const tagName = isLink ? 'a' : 'div';
    const roleAttr = isLink ? 'role="link"' : 'role="presentation"';
    const hrefAttr = isLink ? ` href="${escapeHtml(item.href)}"` : '';

    return `
      <${tagName} class="${classes.join(' ')}" ${roleAttr}${hrefAttr}>
        <div class="notif-icon-wrap"${toneAttr}>
          <i class="${escapeHtml(iconClass)}" aria-hidden="true"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold">${escapeHtml(item.title || '')}</div>
          ${descriptionHtml}
          ${timeHtml}
          ${metaHtml}
          ${ctaHtml}
        </div>
      </${tagName}>
    `;
  }

  function buildSummaryFingerprint(summary, highlights) {
    const base = summary ? JSON.stringify(summary) : '';
    const highlightStamp = Array.isArray(highlights)
      ? JSON.stringify(highlights.map(item => [
          item?.key,
          item?.description,
          item?.meta,
          item?.time?.iso || null,
          item?.href || null,
          item?.cta || null,
        ]))
      : '';
    return `${base}|${highlightStamp}`;
  }

  function updateManagementView(items, highlights, itemsHtml) {
    if (manageHighlightsEl) {
      const highlightMarkup = renderHighlights(highlights);
      if (highlightMarkup) {
        manageHighlightsEl.innerHTML = highlightMarkup;
        manageHighlightsEl.classList.remove('d-none');
      } else {
        manageHighlightsEl.innerHTML = '';
        manageHighlightsEl.classList.add('d-none');
      }
    }

    if (manageCountEl) {
      const total = Array.isArray(items) ? items.length : 0;
      manageCountEl.textContent = formatAlertCount(total);
    }

    if (manageListEl) {
      if (!itemsHtml || !items || items.length === 0) {
        manageListEl.innerHTML = '<div class="text-center text-muted py-4">All caught up! No system notifications right now.</div>';
      } else if (itemsHtml.includes('notif-group')) {
        manageListEl.innerHTML = itemsHtml;
      } else {
        manageListEl.innerHTML = `<div class="list-group list-group-flush">${itemsHtml}</div>`;
      }
    }
  }

  function updateManagementError(message) {
    if (manageListEl) {
      const text = message ? escapeHtml(message) : 'Unable to load notifications.';
      manageListEl.innerHTML = `<div class="text-center text-danger py-4">${text}</div>`;
    }
    if (manageHighlightsEl) {
      manageHighlightsEl.innerHTML = '';
      manageHighlightsEl.classList.add('d-none');
    }
    if (manageCountEl) {
      manageCountEl.textContent = formatAlertCount(0);
    }
  }

  function setManagementStatusText(text) {
    if (!manageUpdatedEl) {
      return;
    }
    manageUpdatedEl.textContent = text || 'Not yet refreshed';
    manageUpdatedEl.removeAttribute('data-iso');
    manageUpdatedEl.removeAttribute('title');
  }

  function setManagementTimestamp(date) {
    if (!manageUpdatedEl) {
      return;
    }

    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
      setManagementStatusText('Not yet refreshed');
      return;
    }

    const iso = date.toISOString();
    const formatted = formatAbsoluteTime(date) || date.toLocaleString();
    manageUpdatedEl.textContent = formatted;
    manageUpdatedEl.dataset.iso = iso;
    manageUpdatedEl.setAttribute('title', date.toLocaleString());
  }

  function formatAlertCount(count) {
    if (!Number.isFinite(count) || count <= 0) {
      return '0 alerts';
    }
    const value = Math.max(0, Math.round(count));
    return value === 1 ? '1 alert' : `${value} alerts`;
  }
  
  function hideBadge() {
    if (!badgeEl) {
      return;
    }
    badgeEl.textContent = '';
    badgeEl.classList.add('d-none');
  }

  function renderNotificationItem(item) {
    const {
      icon,
      tone,
      headline,
      description,
    } = describeNotification(item);

    const createdAt = parseDate(item?.created_at);
    const relativeTime = formatRelativeTime(createdAt);
    const absoluteTime = formatAbsoluteTime(createdAt);
    const isoTime = createdAt ? createdAt.toISOString() : '';
    const isUnread = Boolean(item?._isUnread);

    const href = buildNotificationHref(item);

    const iconClass = tone ? `${icon} text-${tone}` : icon;
    const toneAttr = tone ? ` data-tone="${escapeHtml(tone)}"` : '';
    const relTimeHtml = relativeTime
      ? `<div class="text-muted small"><time datetime="${escapeHtml(isoTime)}" title="${escapeHtml(absoluteTime)}">${escapeHtml(relativeTime)}</time></div>`
      : '';

    const descriptionHtml = description
      ? `<div class="text-muted small">${escapeHtml(description)}</div>`
      : '';

    const classes = ['list-group-item', 'list-group-item-action', 'notif-entry'];
    if (isUnread) {
      classes.push('notif-entry-new');
    }

    const auditIdAttr = escapeHtml(String(item?.audit_id ?? ''));
    const deleteControlHtml = `
      <span
        class="notif-delete-btn"
        role="button"
        tabindex="0"
        aria-label="Delete this notification"
        data-action="delete"
        data-audit-id="${auditIdAttr}"
      >
        <i class="bi bi-x-lg" aria-hidden="true"></i>
        <span class="visually-hidden">Delete</span>
      </span>
    `;

    const metaParts = [deleteControlHtml];
    if (isUnread) {
      metaParts.unshift('<span class="notif-new-dot" aria-hidden="true"></span>');
    }

    const metaHtml = `
      <div class="notif-entry-meta ms-3 d-flex align-items-center gap-2">
        ${metaParts.join('')}
      </div>
    `;

    return `
      <a
        class="${classes.join(' ')}"
        href="${escapeHtml(href)}"
        role="link"
        data-audit-id="${auditIdAttr}"
      >
        <div class="notif-icon-wrap"${toneAttr}>
          <i class="${escapeHtml(iconClass)}" aria-hidden="true"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold">${escapeHtml(headline)}</div>
          ${descriptionHtml}
          ${relTimeHtml}
        </div>
        ${metaHtml}
      </a>
    `;
  }

  function describeNotification(item) {
    const action = String(item?.action || '').toLowerCase();
    const details = String(item?.details || '');
    const fallbackName = buildFallbackName(item);
    const subject = formatNominationSubject(fallbackName);
    const isGenericSubject = subject.toLowerCase() === 'this registration';
    const subjectHeadline = isGenericSubject ? 'Registration' : subject;
    const subjectBody = isGenericSubject ? 'This registration' : subject;

    switch (action) {
      case 'system_pending_nomination': {
        const payload = parseNotificationPayload(details) || {};
        const totalSource = Object.prototype.hasOwnProperty.call(payload, 'unapproved_count')
          ? payload.unapproved_count
          : payload.pending_count;
        const total = toFiniteNumber(totalSource);
        const delta = toFiniteNumber(payload.new_entries);
        const totalCount = Number.isFinite(total) && total > 0 ? Math.max(0, total) : 0;
        const newCount = Number.isFinite(delta) && delta > 0 ? Math.max(0, delta) : 0;
        const eventLabel = formatEventName(payload.event_name);
        const eventNote = eventLabel === 'this event' ? '' : ` for ${eventLabel}`;

        let headline;
        if (newCount > 0) {
          headline = `${newCount} new ${pluralize('registration', newCount)}`;
        } else if (totalCount > 0) {
          headline = `${totalCount} ${pluralize('registration', totalCount)}`;
        } else {
          headline = 'All registrations reviewed';
        }

        const description = totalCount > 0
          ? `Review the registration entries${eventNote}.`
          : `You're up to date${eventNote}.`;

        return {
          icon: 'bi bi-inbox-fill',
          tone: totalCount > 0 ? 'warning' : 'success',
          headline,
          description,
        };
      }
      case 'system_nomination_start': {
        const payload = parseNotificationPayload(details) || {};
        const thresholdHoursRaw = extractThresholdHoursFromPayload(payload);
        const thresholdInfo = describeThresholdHours(thresholdHoursRaw);
        const eventLabel = formatEventName(payload.event_name);
        const startDate = parseDate(payload.start_iso || payload.start || '');
        const relative = formatRelativeTime(startDate);
        const absolute = formatAbsoluteTime(startDate);
        const eventNote = eventLabel === 'this event' ? '' : ` for ${eventLabel}`;

        let tone = 'primary';
        let headline;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            tone = 'success';
            headline = 'Registration opened';
          } else if (thresholdInfo.direction === 0) {
            headline = 'Registration opens today';
          } else if (thresholdInfo.isWholeDay && thresholdInfo.isSingle) {
            headline = 'Registration opens tomorrow';
          } else if (thresholdInfo.isWholeDay) {
            headline = `Registration opens in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'day' : 'days'}`;
          } else {
            headline = `Registration opens in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'hour' : 'hours'}`;
          }
        } else {
          headline = 'Registration schedule updated';
        }

        let timing;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            timing = relative ? `opened ${relative}.` : `opened ${thresholdInfo.text}.`;
          } else if (thresholdInfo.direction === 0) {
            timing = 'open today.';
          } else if (thresholdInfo.label === 'tomorrow') {
            timing = 'open tomorrow.';
          } else if (relative) {
            timing = `open ${relative}.`;
          } else if (absolute) {
            timing = `open on ${absolute}.`;
          } else {
            timing = `open ${thresholdInfo.text}.`;
          }
        } else if (relative) {
          timing = `open ${relative}.`;
        } else if (absolute) {
          timing = `open on ${absolute}.`;
        } else {
          timing = 'open soon.';
        }

        return {
          icon: 'bi bi-calendar-event',
          tone,
          headline,
          description: `Registration${eventNote} ${timing}`,
        };
      }
      case 'system_nomination_deadline': {
        const payload = parseNotificationPayload(details) || {};
        const thresholdHoursRaw = extractThresholdHoursFromPayload(payload);
        const thresholdInfo = describeThresholdHours(thresholdHoursRaw);
        const pendingSource = Object.prototype.hasOwnProperty.call(payload, 'unapproved_count')
          ? payload.unapproved_count
          : payload.pending_count;
        const pendingCount = toFiniteNumber(pendingSource);
        const eventLabel = formatEventName(payload.event_name);
        const deadline = parseDate(payload.deadline_iso || payload.deadline || '');
        const relative = formatRelativeTime(deadline);
        const absolute = formatAbsoluteTime(deadline);
        const pendingText = Number.isFinite(pendingCount) && pendingCount > 0
          ? ` ${pendingCount} ${pluralize('registration', pendingCount)} unapproved.`
          : '';
        const eventNote = eventLabel === 'this event' ? '' : ` for ${eventLabel}`;
        let tone = 'warning';
        let headline;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            tone = 'danger';
            headline = 'Registration period ended';
          } else if (thresholdInfo.direction === 0) {
            tone = 'danger';
            headline = 'Registration deadline today';
          } else if (thresholdInfo.isWholeDay && thresholdInfo.isSingle) {
            headline = 'Registration deadline tomorrow';
          } else if (thresholdInfo.isWholeDay) {
            headline = `Registration deadline in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'day' : 'days'}`;
          } else {
            headline = `Registration deadline in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'hour' : 'hours'}`;
          }
        } else {
          headline = 'Registration deadline approaching';
        }

        let timing;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            timing = relative ? `closed ${relative}.` : `closed ${thresholdInfo.text}.`;
          } else if (thresholdInfo.direction === 0) {
            timing = 'ends now.';
          } else if (thresholdInfo.label === 'tomorrow') {
            timing = 'ends tomorrow.';
          } else if (relative) {
            timing = `ends ${relative}.`;
          } else if (absolute) {
            timing = `ends on ${absolute}.`;
          } else {
            timing = `ends ${thresholdInfo.text}.`;
          }
        } else if (relative) {
          timing = `ends ${relative}.`;
        } else if (absolute) {
          timing = `ends on ${absolute}.`;
        } else {
          timing = 'ends soon.';
        }

        return {
          icon: 'bi bi-hourglass-split',
          tone,
          headline,
          description: `Registration${eventNote} ${timing}${pendingText}`.trim(),
        };
      }
      case 'system_voting_start': {
        const payload = parseNotificationPayload(details) || {};
        const thresholdHoursRaw = extractThresholdHoursFromPayload(payload);
        const thresholdInfo = describeThresholdHours(thresholdHoursRaw);
        const eventLabel = formatEventName(payload.event_name);
        const startDate = parseDate(payload.start_iso || payload.start || '');
        const relative = formatRelativeTime(startDate);
        const absolute = formatAbsoluteTime(startDate);
        const eventNote = eventLabel === 'this event' ? '' : ` for ${eventLabel}`;

        let tone = 'primary';
        let headline;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            tone = 'success';
            headline = 'Voting period started';
          } else if (thresholdInfo.direction === 0) {
            tone = 'success';
            headline = 'Voting starts now';
          } else if (thresholdInfo.isWholeDay && thresholdInfo.isSingle) {
            headline = 'Voting starts tomorrow';
          } else if (thresholdInfo.isWholeDay) {
            headline = `Voting starts in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'day' : 'days'}`;
          } else {
            headline = `Voting starts in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'hour' : 'hours'}`;
          }
        } else {
          headline = 'Voting schedule updated';
        }

        let timing;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            timing = relative ? `opened ${relative}.` : `opened ${thresholdInfo.text}.`;
          } else if (thresholdInfo.direction === 0) {
            timing = 'starts right now.';
          } else if (thresholdInfo.label === 'tomorrow') {
            timing = 'starts tomorrow.';
          } else if (relative) {
            timing = `starts ${relative}.`;
          } else if (absolute) {
            timing = `starts on ${absolute}.`;
          } else {
            timing = `starts ${thresholdInfo.text}.`;
          }
        } else if (relative) {
          timing = `starts ${relative}.`;
        } else if (absolute) {
          timing = `starts on ${absolute}.`;
        } else {
          timing = 'starts soon.';
        }

        let statusNote = '';
        if (thresholdInfo) {
          if (thresholdInfo.direction > 0) {
            statusNote = ' Please prepare to open the ballots for voters.';
          } else if (thresholdInfo.direction === 0) {
            statusNote = ' Ballots are now open to voters.';
          } else {
            statusNote = ' Voting is underway.';
          }
        } else {
          statusNote = ' Voting schedule updated.';
        }

        return {
          icon: 'bi bi-megaphone-fill',
          tone,
          headline,
          description: `Voting${eventNote} ${timing}${statusNote}`,
        };
      }
      case 'system_voting_end': {
        const payload = parseNotificationPayload(details) || {};
        const thresholdHoursRaw = extractThresholdHoursFromPayload(payload);
        const thresholdInfo = describeThresholdHours(thresholdHoursRaw);
        const eventLabel = formatEventName(payload.event_name);
        const endDate = parseDate(payload.end_iso || payload.end || '');
        const relative = formatRelativeTime(endDate);
        const absolute = formatAbsoluteTime(endDate);
        const eventNote = eventLabel === 'this event' ? '' : ` for ${eventLabel}`;
        let tone = 'warning';
        let headline;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            tone = 'secondary';
            headline = 'Voting ended';
          } else if (thresholdInfo.direction === 0) {
            tone = 'danger';
            headline = 'Voting ends now';
          } else if (thresholdInfo.isWholeDay && thresholdInfo.isSingle) {
            headline = 'Voting ends tomorrow';
          } else if (thresholdInfo.isWholeDay) {
            headline = `Voting ends in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'day' : 'days'}`;
          } else {
            headline = `Voting ends in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'hour' : 'hours'}`;
          }
        } else {
          headline = 'Voting schedule updated';
        }

        let timing;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            timing = relative ? `closed ${relative}.` : `ended ${thresholdInfo.text}.`;
          } else if (thresholdInfo.direction === 0) {
            timing = 'closes now.';
          } else if (thresholdInfo.label === 'tomorrow') {
            timing = 'ends tomorrow.';
          } else if (relative) {
            timing = `ends ${relative}.`;
          } else if (absolute) {
            timing = `ends on ${absolute}.`;
          } else {
            timing = `ends ${thresholdInfo.text}.`;
          }
        } else if (relative) {
          timing = `ends ${relative}.`;
        } else if (absolute) {
          timing = `ends on ${absolute}.`;
        } else {
          timing = 'is underway.';
        }

        let closingNote = '';
        if (thresholdInfo) {
          if (thresholdInfo.direction > 0) {
            closingNote = ' Remind voters to submit their ballots before the deadline.';
          } else if (thresholdInfo.direction === 0) {
            closingNote = ' Ballots are closing immediately—wrap up any final submissions.';
          } else {
            closingNote = ' You can now begin preparing the results for review.';
          }
        } else {
          closingNote = ' Voting schedule updated.';
        }

        return {
          icon: 'bi bi-megaphone-fill',
          tone,
          headline,
          description: `Voting${eventNote} ${timing}${closingNote}`,
        };
      }
      case 'system_pending_deadline': {
        const payload = parseNotificationPayload(details) || {};
        const thresholdHoursRaw = extractThresholdHoursFromPayload(payload);
        const thresholdInfo = describeThresholdHours(thresholdHoursRaw);
        const pendingSource = Object.prototype.hasOwnProperty.call(payload, 'unapproved_count')
          ? payload.unapproved_count
          : payload.pending_count;
        const pendingCount = toFiniteNumber(pendingSource);
        const total = Number.isFinite(pendingCount) ? Math.max(0, pendingCount) : 0;
        const eventLabel = formatEventName(payload.event_name);
        const deadline = parseDate(payload.deadline_iso || payload.deadline || '');
        const relative = formatRelativeTime(deadline);
        const absolute = formatAbsoluteTime(deadline);
        const eventNote = eventLabel === 'this event' ? '' : ` for ${eventLabel}`;
        let headline;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            headline = 'Reviews overdue after deadline';
          } else if (thresholdInfo.direction === 0) {
            headline = 'Reviews due today';
          } else if (thresholdInfo.isWholeDay && thresholdInfo.isSingle) {
            headline = 'Reviews due tomorrow';
          } else if (thresholdInfo.isWholeDay) {
            headline = `Reviews due in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'day' : 'days'}`;
          } else {
            headline = `Reviews due in ${thresholdInfo.value} ${thresholdInfo.value === 1 ? 'hour' : 'hours'}`;
          }
        } else {
          headline = 'Reviews due soon';
        }

        let deadlineNote;
        if (thresholdInfo) {
          if (thresholdInfo.direction < 0) {
            deadlineNote = 'The deadline has passed.';
          } else if (thresholdInfo.direction === 0) {
            deadlineNote = 'Deadline today.';
          } else if (thresholdInfo.label === 'tomorrow') {
            deadlineNote = 'Deadline tomorrow.';
          } else if (relative) {
            deadlineNote = `Deadline ${relative}.`;
          } else if (absolute) {
            deadlineNote = `Deadline on ${absolute}.`;
          } else {
            deadlineNote = `Deadline ${thresholdInfo.text}.`;
          }
        } else if (relative) {
          deadlineNote = `Deadline ${relative}.`;
        } else if (absolute) {
          deadlineNote = `Deadline on ${absolute}.`;
        } else {
          deadlineNote = 'Deadline approaching.';
        }

        const pendingText = total > 0
          ? `${total} ${pluralize('registration', total)} awaiting approval${eventNote}.`
          : `No registrations${eventNote}.`;

        return {
          icon: 'bi bi-exclamation-triangle-fill',
          tone: total > 0 ? 'danger' : 'success',
          headline,
          description: `${pendingText} ${deadlineNote}`.trim(),
        };
      }
      case 'system_nomination_submission': {
        const payload = parseNotificationPayload(details) || {};
        const eventLabel = formatEventName(payload.event_name);
        const eventNote = eventLabel === 'this event' ? '' : ` for ${eventLabel}`;
        const nomineeRaw = String(payload.nominee || '').trim();
        const nomineeHeadline = nomineeRaw
          ? formatNominationSubject(nomineeRaw)
          : subjectHeadline;
        const status = String(payload.status || '').trim();
        const normalizedStatus = status ? status.replace(/[_-]+/g, ' ') : '';
        const statusLabelRaw = String(payload.status_label || '').trim();
        const statusDisplay = statusLabelRaw !== ''
          ? statusLabelRaw
          : (normalizedStatus ? toTitleCase(normalizedStatus) : '');
        const statusNote = statusDisplay ? ` Status: ${statusDisplay}.` : '';

        return {
          icon: 'bi bi-person-plus-fill',
          tone: 'info',
          headline: `${nomineeHeadline} submitted`,
          description: `A new registration${eventNote} was submitted and is awaiting approval.${statusNote}`.trim(),
        };
      }
      case 'create':
        const normalizedDetails = details.toLowerCase();
        const isPublic = normalizedDetails.includes('public');
        return {
          icon: 'bi bi-plus-circle-fill',
          tone: 'primary',
          headline: isGenericSubject
            ? 'New registration recorded'
            : `New registration from ${subject}`,
          description: isPublic
            ? `${subjectBody} was submitted through the public form.`
            : `${subjectBody} was added by an administrator.`,
        };
      case 'update':
        return {
          icon: 'bi bi-pencil-square',
          tone: 'info',
          headline: isGenericSubject
            ? 'Registration updated'
            : `${subjectHeadline} updated`,
          description: `${subjectBody} was updated.`,
        };
      case 'delete':
        return {
          icon: 'bi bi-trash-fill',
          tone: 'danger',
          headline: isGenericSubject
            ? 'Registration removed'
            : `${subjectHeadline} removed`,
          description: `${subjectBody} was deleted from the registrations list.`,
        };
      case 'merge':
        return {
          icon: 'bi bi-shuffle',
          tone: 'info',
          headline: isGenericSubject
            ? 'Registration merged'
            : `${subjectHeadline} merged`,
          description: `${subjectBody} was merged with another entry.`,
        };
      case 'approve':
        return {
          icon: 'bi bi-patch-check-fill',
          tone: 'success',
          headline: isGenericSubject
            ? 'Registration approved'
            : `${subjectHeadline} approved`,
          description: `${subjectBody} was approved.`,
        };
      case 'status_update': {
        const from = extractDetail(details, 'from');
        const to = extractDetail(details, 'to');
        const normalizedOutcome = normalizeStatusOutcome(to);

        if (normalizedOutcome === 'approved') {
          return {
            icon: 'bi bi-patch-check-fill',
            tone: 'success',
            headline: isGenericSubject
              ? 'Registration approved'
              : `${subjectHeadline} approved`,
            description: `${subjectBody} has been approved.`,
          };
        }

        if (normalizedOutcome === 'rejected') {
          return {
            icon: 'bi bi-x-octagon-fill',
            tone: 'danger',
            headline: isGenericSubject
              ? 'Registration rejected'
              : `${subjectHeadline} rejected`,
            description: `${subjectBody} was rejected.`,
          };
        }

        if (normalizedOutcome === 'needs_info') {
          return {
            icon: 'bi bi-exclamation-circle-fill',
            tone: 'warning',
            headline: isGenericSubject
              ? 'Additional requirements needed'
              : `${subjectHeadline} needs additional requirements`,
            description: `${subjectBody} must submit additional requirements.`,
          };
        }

        const transitions = [];
        if (from) transitions.push(formatStatus(from));
        if (to) transitions.push(formatStatus(to));
        const change = transitions.join(' → ');
        return {
          icon: 'bi bi-arrow-left-right',
          tone: 'primary',
          headline: `Status update for ${subjectHeadline}`,
          description: change
            ? `${subjectBody} status changed: ${change}.`
            : `${subjectBody} status was updated.`,
        };
      }
      case 'email_send_attempt': {
        const status = extractDetail(details, 'status');
        const id = extractDetail(details, 'id');
        const pieces = [];
        if (status) pieces.push(`Status: ${formatStatus(status)}`);
        if (id) pieces.push(`Queue ID: ${id}`);
        const detailText = pieces.join(' • ');
        return {
          icon: 'bi bi-envelope-paper-fill',
          tone: 'secondary',
          headline: `Email update for ${subjectHeadline}`,
          description: detailText
            ? `${subjectBody} email log — ${detailText}`
            : `${subjectBody} email notification attempt recorded.`,
        };
      }
      default:
        return {
          icon: 'bi bi-bell-fill',
          tone: 'primary',
          headline: `${formatHeadline(action)} for ${subjectHeadline}`,
          description: details
            ? `${subjectBody}: ${details}`
            : `${subjectBody} had activity recorded.`,
        };
    }
  }

  function enrichNotification(item) {
    const copy = { ...item };
    const createdAt = parseDate(item?.created_at);
    copy._createdAt = createdAt;
    copy._isRecent = isRecentNotification(createdAt);
    copy._isUnread = isNotificationUnread(copy);
    return copy;
  }

  function initializeNotificationReadState() {
    isStorageReady = isStorageAvailable('localStorage');
    readAuditIds = loadStoredReadAuditIds();
  }

  function isStorageAvailable(type) {
    try {
      const storage = window?.[type];
      if (!storage) {
        return false;
      }
      const testKey = '__notif_storage_test__';
      storage.setItem(testKey, '1');
      storage.removeItem(testKey);
      return true;
    } catch (error) {
      return false;
    }
  }

  function loadStoredReadAuditIds() {
    if (!isStorageReady) {
      return new Set();
    }

    try {
      const raw = localStorage.getItem(STORAGE_KEYS.READ_AUDIT_IDS);
      if (!raw) {
        return new Set();
      }

      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) {
        return new Set();
      }

      const cleaned = parsed
        .map(value => Number.parseInt(value, 10))
        .filter(value => Number.isFinite(value) && value > 0);

      return new Set(cleaned);
    } catch (error) {
      return new Set();
    }
  }

  function persistReadAuditIds() {
    if (!isStorageReady) {
      return;
    }

    try {
      const ordered = Array.from(readAuditIds)
        .filter(value => Number.isFinite(value) && value > 0)
        .sort((a, b) => a - b);

      if (ordered.length > MAX_STORED_READ_IDS) {
        ordered.splice(0, ordered.length - MAX_STORED_READ_IDS);
      }

      localStorage.setItem(STORAGE_KEYS.READ_AUDIT_IDS, JSON.stringify(ordered));
    } catch (error) {
      // Ignore persistence errors
    }
  }

  function isNotificationUnread(item) {
    const auditId = Number.parseInt(item?.audit_id, 10);
    if (!Number.isFinite(auditId) || auditId <= 0) {
      return false;
    }

    return !readAuditIds.has(auditId);
  }

  function handleNotificationEntryClick(event) {
    const deleteControl = event.target.closest('.notif-delete-btn');
    if (deleteControl) {
      event.preventDefault();
      event.stopPropagation();

      if (deleteControl.getAttribute('aria-disabled') === 'true' || deleteControl.classList.contains('is-busy')) {
        return;
      }

      const auditId = Number.parseInt(deleteControl.dataset.auditId || deleteControl.getAttribute('data-audit-id') || '', 10);
      if (Number.isFinite(auditId) && auditId > 0) {
        requestNotificationDeletion(auditId, deleteControl);
      }
      return;
    }

    const anchor = event.target.closest('a.notif-entry');
    if (!anchor) {
      return;
    }

    const auditId = Number.parseInt(anchor.dataset.auditId || anchor.getAttribute('data-audit-id') || '', 10);
    if (!Number.isFinite(auditId) || auditId <= 0) {
      return;
    }

    if (!readAuditIds.has(auditId)) {
      readAuditIds.add(auditId);
      persistReadAuditIds();
      anchor.classList.remove('notif-entry-new');
      const dot = anchor.querySelector('.notif-new-dot');
      if (dot) {
        dot.remove();
      }
      decrementBadgeCount();
    }
  }

  function handleNotificationEntryKeyDown(event) {
    const deleteControl = event.target.closest('.notif-delete-btn');
    if (!deleteControl) {
      return;
    }

    const key = event.key || '';
    if (key !== 'Enter' && key !== ' ') {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    if (deleteControl.getAttribute('aria-disabled') === 'true' || deleteControl.classList.contains('is-busy')) {
      return;
    }

    const auditId = Number.parseInt(deleteControl.dataset.auditId || deleteControl.getAttribute('data-audit-id') || '', 10);
    if (Number.isFinite(auditId) && auditId > 0) {
      requestNotificationDeletion(auditId, deleteControl);
    }
  }

  async function requestNotificationDeletion(auditId, controlEl) {
    if (!Number.isFinite(auditId) || auditId <= 0 || !controlEl) {
      return;
    }

    if (deletingAuditIds.has(auditId)) {
      return;
    }

    deletingAuditIds.add(auditId);

    const control = controlEl;
    const originalHtml = control.innerHTML;
    control.classList.add('is-busy');
    control.setAttribute('aria-disabled', 'true');
    control.setAttribute('aria-busy', 'true');
    control.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></span><span class="visually-hidden">Deleting…</span>';

    let succeeded = false;
    let failureMessage = 'Unable to delete notification. Please try again.';

    try {
      const response = await fetch(DELETE_API_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ audit_id: auditId }),
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch (parseError) {
        payload = null;
      }

      if (!response.ok || !payload || payload.status !== 'success') {
        if (payload && typeof payload.message === 'string' && payload.message.trim() !== '') {
          failureMessage = payload.message.trim();
        }
        throw new Error(failureMessage);
      }

      succeeded = true;

      if (!readAuditIds.has(auditId)) {
        readAuditIds.add(auditId);
        persistReadAuditIds();
      }

      const anchor = control.closest('a.notif-entry');
      const wasUnread = Boolean(anchor && anchor.classList.contains('notif-entry-new'));

      if (anchor) {
        anchor.remove();
      }

      if (wasUnread) {
        decrementBadgeCount();
      }

      loadNotifications({ force: true });
    } catch (error) {
      console.error('Notification delete failed:', error);
      window.alert(error?.message || failureMessage);
    } finally {
      deletingAuditIds.delete(auditId);

      if (document.body.contains(control)) {
        if (!succeeded) {
          control.innerHTML = originalHtml;
          control.focus();
        }
        control.classList.remove('is-busy');
        control.removeAttribute('aria-disabled');
        control.removeAttribute('aria-busy');
      }
    }
  }

  function decrementBadgeCount() {
    if (!badgeEl) {
      return;
    }

    const current = parseBadgeCount(badgeEl.textContent);
    const next = Math.max(0, current - 1);

    if (next <= 0) {
      hideBadge();
      return;
    }

    badgeEl.classList.remove('d-none');
    badgeEl.textContent = next > 99 ? '99+' : String(next);
  }

  function parseBadgeCount(text) {
    if (!text) {
      return 0;
    }

    const trimmed = String(text).trim();
    if (!trimmed) {
      return 0;
    }

    const normalized = trimmed.endsWith('+') ? trimmed.slice(0, -1) : trimmed;
    const value = Number.parseInt(normalized, 10);
    return Number.isFinite(value) && value > 0 ? value : 0;
  }

  function processNewNotificationAlerts(items) {
    if (!Array.isArray(items)) {
      return;
    }

    const ids = items
      .map(item => Number.parseInt(item?.audit_id, 10))
      .filter(Number.isFinite);

    const maxId = ids.length ? Math.max(...ids) : 0;

    if (!hasAuditBaseline) {
      hasAuditBaseline = true;
      lastSeenMaxAuditId = maxId;
      ensureNotificationLiveUpdates();
      return;
    }

    const baseline = Number.isFinite(lastSeenMaxAuditId) && lastSeenMaxAuditId !== null
      ? lastSeenMaxAuditId
      : 0;

    const newItems = items.filter(item => Number.parseInt(item?.audit_id, 10) > baseline);

    if (newItems.length > 0) {
      announceNewNotifications(newItems);
    }

    if (maxId > baseline) {
      lastSeenMaxAuditId = maxId;
    }

    ensureNotificationLiveUpdates();
  }

  function ensureNotificationLiveUpdates() {
    if (liveStreamSource || liveStreamRestartTimer) {
      return;
    }

    if (typeof EventSource === 'undefined') {
      return;
    }

    if (document.hidden) {
      return;
    }

    if (!hasAuditBaseline) {
      return;
    }

    startNotificationLiveStream();
  }

  function startNotificationLiveStream() {
    if (liveStreamSource) {
      return;
    }

    if (typeof EventSource === 'undefined' || document.hidden) {
      return;
    }

    const params = new URLSearchParams();
    const baseline = Number.parseInt(lastSeenMaxAuditId ?? '', 10);
    if (Number.isFinite(baseline) && baseline > 0) {
      params.set('since', String(baseline));
    }

    const url = params.toString()
      ? `${LIVE_STREAM_URL}?${params.toString()}`
      : LIVE_STREAM_URL;

    let source;
    try {
      source = new EventSource(url);
    } catch (error) {
      restartNotificationLiveStream(LIVE_STREAM_RETRY_MS);
      return;
    }

    liveStreamSource = source;

    source.addEventListener('notification', handleNotificationStreamMessage);
    source.addEventListener('ping', handleNotificationStreamPing);
    source.addEventListener('complete', () => {
      restartNotificationLiveStream(250);
    });
    source.onerror = () => {
      restartNotificationLiveStream(LIVE_STREAM_RETRY_MS);
    };
  }

  function handleNotificationStreamMessage(event) {
    if (!event) {
      return;
    }

    const data = parseJsonSafely(event.data);
    const latestId = extractNumericId(data);

    if (Number.isFinite(latestId) && latestId > 0) {
      if (!lastSeenMaxAuditId || latestId > lastSeenMaxAuditId) {
        lastSeenMaxAuditId = latestId;
      }
    }

    pendingForcedRefresh = true;
    loadNotifications({ force: true });
  }

  function handleNotificationStreamPing(event) {
    const data = parseJsonSafely(event?.data);
    const latestId = extractNumericId(data);
    if (Number.isFinite(latestId) && latestId > 0 && (!lastSeenMaxAuditId || latestId > lastSeenMaxAuditId)) {
      lastSeenMaxAuditId = latestId;
    }
  }

  function restartNotificationLiveStream(delayMs) {
    pauseNotificationLiveStream();

    if (document.hidden) {
      return;
    }

    const delay = Number.isFinite(delayMs) ? Math.max(250, Number(delayMs)) : LIVE_STREAM_RETRY_MS;
    liveStreamRestartTimer = window.setTimeout(() => {
      liveStreamRestartTimer = null;
      if (!document.hidden) {
        startNotificationLiveStream();
      }
    }, delay);
  }

  function pauseNotificationLiveStream() {
    if (liveStreamRestartTimer) {
      clearTimeout(liveStreamRestartTimer);
      liveStreamRestartTimer = null;
    }

    if (liveStreamSource) {
      try {
        liveStreamSource.removeEventListener('notification', handleNotificationStreamMessage);
        liveStreamSource.removeEventListener('ping', handleNotificationStreamPing);
        liveStreamSource.close();
      } catch (error) {
        // Ignore teardown errors
      }
      liveStreamSource = null;
    }
  }

  function parseJsonSafely(raw) {
    if (typeof raw !== 'string' || raw === '') {
      return null;
    }

    try {
      return JSON.parse(raw);
    } catch (error) {
      return null;
    }
  }

  function extractNumericId(payload) {
    if (!payload || typeof payload !== 'object') {
      return 0;
    }

    const candidates = [
      payload.latest_audit_id,
      payload.audit_id,
      payload.id,
    ];

    for (const value of candidates) {
      const parsed = Number.parseInt(value, 10);
      if (Number.isFinite(parsed) && parsed > 0) {
        return parsed;
      }
    }

    return 0;
  }

  function announceNewNotifications(newItems) {
    if (!Array.isArray(newItems) || newItems.length === 0) {
      return;
    }

    const sorted = [...newItems].sort((a, b) => {
      const left = Number.parseInt(a?.audit_id, 10) || 0;
      const right = Number.parseInt(b?.audit_id, 10) || 0;
      return right - left;
    });

    const newest = sorted[0];
    if (!newest) {
      return;
    }

    const descriptor = describeNotification(newest) || {};
    const count = sorted.length;

    let title = descriptor.headline || 'Event update';
    let message = descriptor.description || 'Check notifications for the latest event activity.';
    let cta = 'View details';

    if (count > 1) {
      title = descriptor.headline || 'Event update';
      message = descriptor.description || 'Check notifications for the latest event activity.';
      cta = 'Open notifications';
    }

    enqueueNotificationToast({
      title,
      message,
      tone: descriptor.tone || 'primary',
      icon: descriptor.icon || 'bi bi-bell-fill',
      href: buildNotificationHref(newest),
      cta,
    });
  }

  function enqueueNotificationToast(payload) {
    if (!payload || typeof payload !== 'object') {
      return;
    }

    toastState.queue.push({
      title: String(payload.title || 'Registration activity'),
      message: String(payload.message || 'Open notifications to review the latest activity.'),
      tone: normalizeTone(payload.tone),
      icon: String(payload.icon || 'bi bi-bell-fill'),
      href: typeof payload.href === 'string' ? payload.href : '',
      cta: payload.cta ? String(payload.cta) : 'View details',
      delay: Number.isFinite(payload.delay) ? Number(payload.delay) : 6000,
    });

    if (!toastState.active) {
      displayNextNotificationToast();
    }
  }

  function displayNextNotificationToast() {
    if (toastState.queue.length === 0) {
      toastState.active = false;
      return;
    }

    const elements = ensureNotificationToastElements();
    if (!elements) {
      toastState.queue.length = 0;
      toastState.active = false;
      return;
    }

    const payload = toastState.queue.shift();
    toastState.active = true;

    const { toastEl, titleEl, messageEl, iconEl, linkEl, instance } = elements;

    toastState.navigateHref = payload.href && payload.href.trim() ? payload.href.trim() : '';

    const tone = normalizeTone(payload.tone);
    toastEl.className = `toast notification-toast text-bg-${tone} border-0 shadow-lg`;
    toastEl.setAttribute('data-tone', tone);
    toastEl.style.cursor = toastState.navigateHref ? 'pointer' : 'default';
    toastEl.setAttribute('tabindex', toastState.navigateHref ? '0' : '-1');

    if (iconEl) {
      iconEl.className = `${payload.icon || 'bi bi-bell-fill'} fs-4`;
    }
    if (titleEl) {
      titleEl.textContent = payload.title || 'Registration activity';
    }
    if (messageEl) {
      messageEl.textContent = payload.message || 'Open notifications to review the latest activity.';
    }

    if (linkEl) {
      if (toastState.navigateHref) {
        linkEl.classList.remove('d-none');
        linkEl.setAttribute('href', toastState.navigateHref);
        linkEl.textContent = payload.cta || 'View details';
      } else {
        linkEl.classList.add('d-none');
        linkEl.removeAttribute('href');
      }
      const isLightTone = tone === 'warning' || tone === 'light';
      linkEl.classList.toggle('text-dark', isLightTone);
      linkEl.classList.toggle('text-white', !isLightTone);
    }

    const delay = Number.isFinite(payload.delay) ? payload.delay : 6000;
    toastEl.setAttribute('data-bs-delay', String(delay));
    if (instance && typeof instance._config === 'object') {
      instance._config.delay = delay;
    }

    if (toastState.hiddenHandler) {
      toastEl.removeEventListener('hidden.bs.toast', toastState.hiddenHandler);
    }
    toastState.hiddenHandler = function handleToastHidden() {
      toastEl.removeEventListener('hidden.bs.toast', handleToastHidden);
      toastState.hiddenHandler = null;
      toastState.active = false;
      displayNextNotificationToast();
    };
    toastEl.addEventListener('hidden.bs.toast', toastState.hiddenHandler);

    window.setTimeout(() => {
      instance.show();
    }, 10);
  }

  function ensureNotificationToastElements() {
    if (toastState.elements) {
      return toastState.elements;
    }

    if (typeof bootstrap === 'undefined' || !document?.body) {
      return null;
    }

    const container = document.createElement('div');
    container.id = 'notifLiveToastContainer';
    container.className = 'notification-toast-container position-fixed p-3';
    container.style.zIndex = '1080';
    container.style.bottom = '0';
    container.style.left = '0';
    container.style.maxWidth = '360px';
    container.style.width = '100%';
    container.style.pointerEvents = 'none';

    container.innerHTML = `
      <div id="notifLiveToast" class="toast notification-toast text-bg-primary border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="6000" tabindex="-1" style="pointer-events:auto;">
        <div class="toast-body">
          <div class="d-flex align-items-start gap-3">
            <div class="flex-shrink-0">
              <i id="notifLiveToastIcon" class="bi bi-bell-fill fs-4" aria-hidden="true"></i>
            </div>
            <div class="flex-grow-1">
              <div id="notifLiveToastTitle" class="fw-semibold mb-1">Registration activity</div>
              <div id="notifLiveToastMessage" class="small">Open notifications to review the latest updates.</div>
              <a id="notifLiveToastLink" class="notification-toast-link small fw-semibold text-decoration-underline mt-2 d-inline-flex align-items-center gap-1 text-white" href="#">
                <span>View details</span>
                <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
              </a>
            </div>
            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="toast" aria-label="Close"></button>
          </div>
        </div>
      </div>`;

    document.body.appendChild(container);

    const toastEl = container.querySelector('#notifLiveToast');
    const titleEl = container.querySelector('#notifLiveToastTitle');
    const messageEl = container.querySelector('#notifLiveToastMessage');
    const iconEl = container.querySelector('#notifLiveToastIcon');
    const linkEl = container.querySelector('#notifLiveToastLink');

    if (!toastEl) {
      return null;
    }

    const instance = bootstrap.Toast.getOrCreateInstance(toastEl);

    const handleActivate = event => {
      if (!toastState.navigateHref) {
        return;
      }

      if (event.type === 'click') {
        if (event.button !== 0) {
          return;
        }
        if (event.target.closest('[data-bs-dismiss="toast"]')) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
      } else if (event.type === 'keydown') {
        const key = event.key || '';
        if (key !== 'Enter' && key !== ' ') {
          return;
        }
        event.preventDefault();
      }

      instance.hide();
      window.location.href = toastState.navigateHref;
    };

    toastEl.addEventListener('click', handleActivate);
    toastEl.addEventListener('keydown', handleActivate);
    if (linkEl) {
      linkEl.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        handleActivate(event);
      });
    }

    toastState.elements = { container, toastEl, titleEl, messageEl, iconEl, linkEl, instance };
    return toastState.elements;
  }

  function normalizeTone(tone) {
    const allowed = ['primary', 'success', 'warning', 'danger', 'info', 'secondary', 'dark', 'light'];
    const normalized = String(tone || '').toLowerCase().trim();
    return allowed.includes(normalized) ? normalized : 'primary';
  }

  function buildNotificationHref(item) {
    const payload = parseNotificationPayload(item?.details);
    if (payload && typeof payload.href === 'string') {
      const trimmed = payload.href.trim();
      if (trimmed) {
        return trimmed;
      }
    }

    const fallbackHref = extractDetail(String(item?.details || ''), 'href');
    if (fallbackHref) {
      return fallbackHref;
    }
    const nominationId = Number(item?.nomination_id || 0);
    if (nominationId > 0) {
      return `nomination_profile.php?id=${encodeURIComponent(nominationId)}`;
    }
    return 'nominations.php';
  }

  function buildFallbackName(item) {
    const businessName = String(item?.business_name || '').trim();
    if (businessName && businessName !== '—') {
      return businessName;
    }
    const nominationId = Number(item?.nomination_id || 0);
    if (nominationId > 0) {
      return `Registration #${nominationId}`;
    }
    const auditId = Number(item?.audit_id || 0);
    return auditId > 0 ? `Activity #${auditId}` : 'Registration activity';
  }

  function formatNominationSubject(name) {
    const trimmed = String(name || '').trim();
    if (!trimmed || trimmed === '—' || trimmed.toLowerCase() === 'registration activity') {
      return 'This registration';
    }
    const normalized = trimmed.toLowerCase();
    if (normalized.startsWith('registration #') || normalized.startsWith('activity #')) {
      return trimmed;
    }
    return `"${trimmed}"`;
  }

  function extractDetail(details, key) {
    if (!details || !key) {
      return '';
    }
    const pattern = new RegExp(`${key}\s*=\s*([^;]+?)\s*(?=;|$|\s+\w+=)`, 'i');
    const match = details.match(pattern);
    if (match && match[1]) {
      return match[1].trim();
    }
    return '';
  }

  function parseNotificationPayload(details) {
    if (!details) {
      return null;
    }

    const text = typeof details === 'string' ? details.trim() : '';
    if (!text || (text[0] !== '{' && text[0] !== '[')) {
      return null;
    }

    try {
      const parsed = JSON.parse(text);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function formatEventName(name) {
    const trimmed = String(name || '').trim();
    if (!trimmed) {
      return 'this event';
    }

    const normalized = trimmed
      .replace(/["\u201C\u201D]/g, '')
      .replace(/^"|"$/g, '')
      .trim();

    return normalized || 'this event';
  }

  function normalizeStatusOutcome(value) {
    const normalized = String(value || '').trim().toLowerCase();
    if (!normalized) {
      return '';
    }

    if (normalized.includes('approve')) {
      return 'approved';
    }

    if (normalized.includes('reject') || normalized.includes('declin') || normalized.includes('denied')) {
      return 'rejected';
    }

    if (
      normalized.includes('need') ||
      normalized.includes('require') ||
      normalized.includes('missing') ||
      normalized.includes('incomplete')
    ) {
      return 'needs_info';
    }

    return '';
  }

  function formatStatus(value) {
    return toTitleCase(String(value || '').replace(/[_.-]+/g, ' '));
  }

  function formatHeadline(action) {
    if (!action) {
      return 'Registration activity';
    }
    return toTitleCase(action.replace(/[_.-]+/g, ' '));
  }

  function toTitleCase(str) {
    return str
      .split(' ')
      .filter(Boolean)
      .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
      .join(' ');
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function parseDate(value) {
    if (!value) {
      return null;
    }

    const input = String(value).trim();
    if (!input) {
      return null;
    }

    // Strings that explicitly declare a timezone (e.g. trailing "Z" or "+08:00")
    // should be parsed as-is so that the offset is respected instead of forcing
    // the value into the local time bucket.
    if (/([+-]\d{2}:?\d{2}|Z)$/i.test(input)) {
      const zoned = new Date(input);
      return Number.isFinite(zoned.getTime()) ? zoned : null;
    }

    const match = input.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (match) {
      const [, y, m, d, hh, mm, ss] = match;
      return new Date(
        Number(y),
        Number(m) - 1,
        Number(d),
        Number(hh),
        Number(mm),
        Number(ss || '0'),
      );
    }
    const parsed = new Date(value);
    return Number.isFinite(parsed.getTime()) ? parsed : null;
  }

  function isRecentNotification(date) {
    if (!date) {
      return false;
    }
    return Date.now() - date.getTime() <= NEWNESS_THRESHOLD_MS;
  }

  function formatRelativeTime(date) {
    if (!date) {
      return '';
    }
    const diffSeconds = Math.round((date.getTime() - Date.now()) / 1000);
    const divisions = [
      { amount: 60, unit: 'second' },
      { amount: 60, unit: 'minute' },
      { amount: 24, unit: 'hour' },
      { amount: 7, unit: 'day' },
      { amount: 4.34524, unit: 'week' },
      { amount: 12, unit: 'month' },
      { amount: Number.POSITIVE_INFINITY, unit: 'year' },
    ];

    let duration = diffSeconds;
    for (const division of divisions) {
      if (Math.abs(duration) < division.amount) {
        if (RELATIVE_FORMATTER) {
          return RELATIVE_FORMATTER.format(Math.round(duration), division.unit);
        }
        return fallbackRelative(Math.round(duration), division.unit);
      }
      duration /= division.amount;
    }
    return '';
  }

  function fallbackRelative(value, unit) {
    const abs = Math.abs(value);
    const suffix = value <= 0 ? 'ago' : 'from now';
    return `${abs} ${unit}${abs === 1 ? '' : 's'} ${suffix}`;
  }

  function formatAbsoluteTime(date) {
    if (!date) {
      return '';
    }
    try {
      const monthIndex = date.getMonth();
      const monthName = MONTH_NAMES[monthIndex] || '';
      const day = date.getDate();
      const year = date.getFullYear();
      let hours = date.getHours();
      const minutes = date.getMinutes();
      const period = hours >= 12 ? 'pm' : 'am';
      hours %= 12;
      if (hours === 0) {
        hours = 12;
      }
      const minuteStr = minutes.toString().padStart(2, '0');
      const datePart = monthName
        ? `${monthName} ${day}, ${year}`
        : date.toDateString();
      return `${datePart} at ${hours}:${minuteStr} ${period}`;
    } catch (err) {
      return date.toISOString();
    }
  }

  function updateSummary(total, recent, summary = null, highlights = []) {
    const summaryText = buildSummaryText(total, recent, summary);

    if (summaryEl) {
      if (summaryText) {
        summaryEl.textContent = summaryText;
      } else if (total <= 0) {
        summaryEl.textContent = 'No alerts yet. Stay tuned for updates.';
      } else {
        const shown = Math.min(total, 20);
        summaryEl.textContent = shown === 1
          ? 'Showing the latest event alert.'
          : `Showing the latest ${shown} event alerts.`;
      }
    }

    if (dropdownSummaryEl) {
      if (summaryText) {
        dropdownSummaryEl.textContent = summaryText;
      } else if (total <= 0) {
        dropdownSummaryEl.textContent = 'No alerts yet. Check back for schedule and registration updates.';
      } else {
        const unreadHint = recent > 0 ? `${recent} recent · ` : '';
        dropdownSummaryEl.textContent = `${unreadHint}${total} system ${total === 1 ? 'alert' : 'alerts'} loaded.`;
      }
    }

    if (dropdownCountEl) {
      const count = Math.max(0, Number(total) || 0);
      dropdownCountEl.classList.toggle('d-none', count <= 0);
      dropdownCountEl.textContent = count > 99 ? '99+' : String(count);
    }
  }

  function formatDurationCompact(seconds) {
    if (!Number.isFinite(seconds)) {
      return '';
    }

    const value = Math.max(0, Math.round(seconds));
    const days = Math.floor(value / 86_400);
    const hours = Math.floor((value % 86_400) / 3_600);
    const minutes = Math.floor((value % 3_600) / 60);

    const parts = [];
    if (days > 0) {
      parts.push(`${days}d`);
    }
    if (hours > 0 && parts.length < 2) {
      parts.push(`${hours}h`);
    }
    if (parts.length === 0 && minutes > 0) {
      parts.push(`${minutes}m`);
    }
    if (parts.length === 0) {
      parts.push('<1m');
    }

    return parts.join(' ');
  }

  function buildSummaryText(total, recent, summary) {
    if (!summary || typeof summary !== 'object') {
      return '';
    }

    const parts = [];
    const nominationSeconds = toFiniteNumber(summary.nomination?.seconds_remaining ?? summary.event?.nomination_seconds_left);
    const pendingDeadlineWarningSource = Object.prototype.hasOwnProperty.call(summary, 'unapproved_deadline_warning')
      ? summary.unapproved_deadline_warning
      : summary.pending_deadline_warning;
    const pendingDeadlineWarning = Boolean(pendingDeadlineWarningSource);

    const pendingCountSource = Object.prototype.hasOwnProperty.call(summary, 'unapproved')
      ? summary.unapproved
      : summary.pending;
    const pendingCount = toFiniteNumber(pendingCountSource);
    if (pendingCount !== null) {
      let pendingPart = pendingCount > 0
        ? `${pendingCount} ${pluralize('registration', pendingCount)}`
        : 'No registrations';

      if (pendingCount > 0 && pendingDeadlineWarning && nominationSeconds !== null && nominationSeconds >= 0) {
        const compactDeadline = formatDurationCompact(nominationSeconds);
        pendingPart += compactDeadline
          ? ` (deadline in ${compactDeadline})`
          : ' (deadline approaching)';
      }

      parts.push(pendingPart);
    }


    if (nominationSeconds !== null) {
      if (nominationSeconds > 0) {
        const compact = formatDurationCompact(nominationSeconds);
        parts.push(compact ? `Deadline in ${compact}` : 'Deadline approaching');
      } else {
        parts.push('Registration deadline passed');
      }
    }

    const votingStatus = String(summary.voting?.status || '').toLowerCase();
    const secondsUntilStart = toFiniteNumber(summary.voting?.seconds_until_start);
    const secondsUntilEnd = toFiniteNumber(summary.voting?.seconds_until_end);

    if (votingStatus === 'upcoming' && secondsUntilStart !== null && secondsUntilStart > 0) {
      const compact = formatDurationCompact(secondsUntilStart);
      parts.push(compact ? `Voting starts in ${compact}` : 'Voting starts soon');
    } else if (votingStatus === 'ongoing') {
      if (secondsUntilEnd !== null && secondsUntilEnd > 0) {
        const compact = formatDurationCompact(secondsUntilEnd);
        parts.push(compact ? `Voting ends in ${compact}` : 'Voting in progress');
      } else {
        parts.push('Voting in progress');
      }
    } else if (votingStatus === 'ended') {
      parts.push('Voting ended');
    } else if (votingStatus === 'upcoming') {
      parts.push('Voting starts soon');
    }

    if (parts.length) {
      return parts.join(' • ');
    }

    return '';
  }

  function toFiniteNumber(value) {
    if (typeof value === 'number') {
      return Number.isFinite(value) ? value : null;
    }
    if (typeof value === 'string') {
      const trimmed = value.trim();
      if (trimmed === '') {
        return null;
      }
      const parsed = Number(trimmed);
      return Number.isFinite(parsed) ? parsed : null;
    }
    return null;
  }

  function extractThresholdHoursFromPayload(payload) {
    if (!payload || typeof payload !== 'object') {
      return null;
    }
    const hoursRaw = toFiniteNumber(payload.threshold_hours);
    if (hoursRaw !== null) {
      return Math.round(hoursRaw);
    }
    const daysRaw = toFiniteNumber(payload.threshold_days);
    if (daysRaw !== null) {
      return Math.round(daysRaw * 24);
    }
    return null;
  }

  function describeThresholdHours(hours) {
    if (!Number.isFinite(hours)) {
      return null;
    }

    const normalized = Math.round(hours);
    const absHours = Math.abs(normalized);
    if (normalized === 0) {
      return {
        hours: 0,
        direction: 0,
        absHours: 0,
        isWholeDay: true,
        days: 0,
        value: 0,
        unit: 'day',
        isSingle: true,
        label: 'today',
        text: 'today',
      };
    }

    const direction = normalized > 0 ? 1 : -1;
    const isWholeDay = absHours % 24 === 0;
    const dayValue = absHours / 24;
    const value = isWholeDay ? dayValue : absHours;
    const unit = isWholeDay ? 'day' : 'hour';
    const isSingle = value === 1;

    let label = '';
    let text;
    if (isWholeDay && absHours === 24) {
      label = direction > 0 ? 'tomorrow' : 'yesterday';
      text = label;
    } else {
      const unitLabel = isSingle ? unit : `${unit}s`;
      if (direction > 0) {
        text = `in ${value} ${unitLabel}`;
      } else {
        text = `${value} ${unitLabel} ago`;
      }
    }

    return {
      hours: normalized,
      direction,
      absHours,
      isWholeDay,
      days: dayValue,
      value,
      unit,
      isSingle,
      label,
      text,
    };
  }
  
  function pluralize(word, count) {
    return count === 1 ? word : `${word}s`;
  }

  function refreshRelativeTimes() {
    const containers = [menuBodyEl, manageListEl];
    containers.forEach(container => {
      if (!container) {
        return;
      }
      const timeEls = container.querySelectorAll('time[datetime]');
      timeEls.forEach(el => {
        const date = parseDate(el.getAttribute('datetime'));
        const relative = formatRelativeTime(date);
        if (relative) {
          el.textContent = relative;
        }
      });
    });
  }
  window.ToccaAdminNotifications = { init, activate: activateNotifications };

  if (document.getElementById('notifCenterList')) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }
})();

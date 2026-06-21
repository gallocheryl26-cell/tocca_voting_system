/**
 * Dashboard: show nomination widgets during nomination period, voting widgets during voting period.
 */
(function () {
  'use strict';

  const nominationRangeEl = document.getElementById('nominationPeriodRange');
  const votingRangeEl = document.getElementById('votingPeriodRange');
  const phaseLabelEl = document.getElementById('activePhaseLabel');
  const countdownEl = document.getElementById('countdownTimer');
  const titleEl = document.getElementById('activeEventTitle');
  const idleBlock = document.getElementById('dashboardIdleBlock');
  const idleMessage = document.getElementById('dashboardIdleMessage');
  const nomStats = document.getElementById('dashboardNomStats');
  const voteStats = document.getElementById('dashboardVoteStats');
  const nomCharts = document.getElementById('dashboardNomCharts');
  const voteCharts = document.getElementById('dashboardVoteCharts');

  const fmt = new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
  let intervalId = null;

  function parseLocal(mysqlDT) {
    return mysqlDT ? new Date(String(mysqlDT).replace(' ', 'T')) : null;
  }

  function setCountdown(targetDate, endedLabel) {
    if (!countdownEl) return;
    if (!targetDate) {
      countdownEl.textContent = 'Awaiting schedule…';
      return;
    }
    if (intervalId) clearInterval(intervalId);

    function tick() {
      const diff = targetDate - new Date();
      if (diff <= 0) {
        countdownEl.innerHTML = '<span style="color:red;">' + (endedLabel || 'Ended') + '</span>';
        clearInterval(intervalId);
        return;
      }
      const d = Math.floor(diff / 86400000);
      const h = Math.floor((diff / 3600000) % 24);
      const m = Math.floor((diff / 60000) % 60);
      const s = Math.floor((diff / 1000) % 60);
      countdownEl.textContent = d + 'd ' + h + 'h ' + m + 'm ' + s + 's';
    }

    tick();
    intervalId = setInterval(tick, 1000);
  }

  function formatRange(start, end) {
    if (start && end) return fmt.format(start) + ' – ' + fmt.format(end);
    if (start) return 'From ' + fmt.format(start);
    if (end) return 'Until ' + fmt.format(end);
    return 'Unavailable';
  }

  function idleText(phase) {
    switch (phase) {
      case 'between':
        return 'Nominations have ended. Voting has not started yet — dashboard metrics will appear when voting opens.';
      case 'voting_closed':
        return 'Voting has ended for this event. Open Events to review schedules or activate the next cycle.';
      case 'unscheduled':
        return 'Set nomination and voting schedules under File Maintenance → Events to enable dashboard metrics.';
      default:
        return 'No active nomination or voting period for this event.';
    }
  }

  function phaseLabel(phase) {
    switch (phase) {
      case 'nominations_open':
        return 'Nominations Open';
      case 'between':
        return 'Waiting for Voting Start';
      case 'voting_open':
        return 'Voting Open';
      case 'voting_closed':
        return 'Voting Closed';
      case 'unscheduled':
        return 'Schedule Not Set';
      default:
        return 'Inactive';
    }
  }

  function applyPhaseLayout(phase) {
    // During voting period, show BOTH nomination + voting widgets.
    // During nomination period, keep voting widgets hidden.
    const showNom = phase === 'nominations_open' || phase === 'voting_open';
    const showVote = phase === 'voting_open';
    const showIdle = !showNom && !showVote;

    if (nomStats) nomStats.style.display = showNom ? '' : 'none';
    if (nomCharts) nomCharts.style.display = showNom ? '' : 'none';
    if (voteStats) voteStats.style.display = showVote ? '' : 'none';
    if (voteCharts) voteCharts.style.display = showVote ? '' : 'none';
    if (idleBlock) idleBlock.style.display = showIdle ? '' : 'none';
    if (idleMessage && showIdle) idleMessage.textContent = idleText(phase);

    document.documentElement.dataset.dashboardPhase = phase;

    if (showVote && typeof window.toccaDashboardLoadVoting === 'function') {
      window.toccaDashboardLoadVoting();
    }
    if (showNom && typeof window.toccaDashboardLoadNominations === 'function') {
      window.toccaDashboardLoadNominations();
    }
  }

  function applyScheduleUi(data) {
    const ns = parseLocal(data.nomination_start);
    const ne = parseLocal(data.nomination_end);
    const vs = parseLocal(data.voting_start);
    const ve = parseLocal(data.voting_end);
    const phase = data.phase || 'unscheduled';
    const now = new Date();

    if (titleEl && data.event_name) {
      titleEl.textContent = data.event_name;
    }

    if (nominationRangeEl) nominationRangeEl.textContent = formatRange(ns, ne);
    if (votingRangeEl) votingRangeEl.textContent = formatRange(vs, ve);
    if (phaseLabelEl) phaseLabelEl.textContent = phaseLabel(phase);

    applyPhaseLayout(phase);

    if (phase === 'nominations_open' && ne) {
      setCountdown(ne, 'Nomination Ended');
      return;
    }
    if (phase === 'between') {
      if (vs) {
        setCountdown(vs, 'Voting Started');
        return;
      }
      if (ns && now < ns) {
        setCountdown(ns, 'Nomination Started');
        return;
      }
      if (countdownEl) countdownEl.textContent = 'Awaiting schedule…';
      return;
    }
    if (phase === 'voting_open' && ve) {
      setCountdown(ve, 'Voting Ended');
      return;
    }
    if (phase === 'voting_closed') {
      if (countdownEl) {
        countdownEl.innerHTML = '<span style="color:red;">Voting Ended</span>';
      }
      return;
    }
    if (ns && now < ns) {
      setCountdown(ns, 'Nomination Started');
      return;
    }
    if (vs && now < vs) {
      setCountdown(vs, 'Voting Started');
      return;
    }
    if (countdownEl) countdownEl.textContent = 'Not scheduled';
  }

  function init() {
    fetch('save_event_schedule.php?check_status=1', { cache: 'no-store' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data.status !== 'success') {
          if (titleEl) titleEl.textContent = 'No active event';
          if (nominationRangeEl) nominationRangeEl.textContent = 'Activate an event under File Maintenance → Events.';
          if (votingRangeEl) votingRangeEl.textContent = 'Activate an event under File Maintenance → Events.';
          if (phaseLabelEl) phaseLabelEl.textContent = 'No Active Event';
          if (countdownEl) countdownEl.textContent = '—';
          applyPhaseLayout('unscheduled');
          return;
        }
        applyScheduleUi(data);
      })
      .catch(function () {
        if (nominationRangeEl && !nominationRangeEl.textContent) nominationRangeEl.textContent = 'Unavailable';
        if (votingRangeEl && !votingRangeEl.textContent) votingRangeEl.textContent = 'Unavailable';
        if (phaseLabelEl) phaseLabelEl.textContent = 'Unavailable';
        if (countdownEl) {
          countdownEl.innerHTML = '<span style="color:red;">Unavailable</span>';
        }
        applyPhaseLayout('unscheduled');
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

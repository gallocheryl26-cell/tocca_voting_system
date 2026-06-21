<?php
/**
 * Active Event Context bar.
 *
 * Renders a compact, always-visible indicator of:
 *   - the currently active event (name + year)
 *   - its current phase (Nominations / Voting / Between / Closed / Unscheduled)
 *   - a countdown to the next deadline (if any)
 *
 * When NO active event is set, renders a warning callout with a CTA to activate
 * one. The renderer is fully defensive: if $conn is missing, or the tables /
 * columns are not present, it silently renders nothing.
 *
 * Usage:
 *   echo render_admin_event_context();
 *
 * Requires $conn (mysqli) to be in scope when included.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin_event_phase.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

$adminEventCtx = null;

if (function_exists('admin_get_active_event')) {
    $adminEventCtx = admin_get_active_event($conn);
} else {
    try {
        $res = $conn->query(
            "SELECT event_id, event_name, year,
                    nomination_start, nomination_end, voting_start, voting_end
             FROM tbl_events
             WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
             ORDER BY year DESC, event_id DESC
             LIMIT 1"
        );
        if ($res instanceof mysqli_result && $res->num_rows > 0) {
            $adminEventCtx = $res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $adminEventCtx = null;
    }
}

$_aec_id = 'aec_' . bin2hex(random_bytes(4));
?>
<?php if ($adminEventCtx): ?>
    <?php
    [$phaseKey, $phaseLabel, $phaseColor, $deadline, $deadlineLabel] = admin_event_phase($adminEventCtx);
    $eventLabel = trim(($adminEventCtx['event_name'] ?? '') . ' ' . ($adminEventCtx['year'] ?? ''));
    ?>
    <div id="<?= htmlspecialchars($_aec_id, ENT_QUOTES); ?>"
         class="admin-event-context card border-0 shadow-sm mb-3"
         data-phase="<?= htmlspecialchars($phaseKey, ENT_QUOTES); ?>">
        <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-2">
            <span class="text-uppercase small text-muted me-1" style="letter-spacing:.05em;">
                <i class="bi bi-calendar-event"></i> Active event
            </span>
            <span class="fw-semibold text-truncate" style="max-width:42ch;"
                  title="<?= htmlspecialchars($eventLabel, ENT_QUOTES); ?>">
                <?= htmlspecialchars($eventLabel, ENT_QUOTES); ?>
            </span>
            <span class="badge bg-<?= htmlspecialchars($phaseColor, ENT_QUOTES); ?> ms-1">
                <?= htmlspecialchars($phaseLabel, ENT_QUOTES); ?>
            </span>
            <?php if ($deadline instanceof DateTime): ?>
                <small class="text-muted ms-1 admin-event-countdown"
                       data-deadline="<?= htmlspecialchars($deadline->format(DateTime::ATOM), ENT_QUOTES); ?>">
                    <i class="bi bi-clock"></i>
                    <span class="aec-label"><?= htmlspecialchars($deadlineLabel, ENT_QUOTES); ?></span>
                    <span class="aec-count">…</span>
                </small>
            <?php endif; ?>
            <div class="ms-auto d-flex gap-1">
                <a class="btn btn-sm btn-outline-secondary"
                   href="events.php"
                   title="Manage events">
                    <i class="bi bi-gear"></i> <span class="d-none d-md-inline">Manage event</span>
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="admin-event-context alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 py-2 px-3">
        <div>
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>No active event.</strong>
            <span class="text-muted small">Module data will be hidden until an event is activated.</span>
        </div>
        <a class="btn btn-sm btn-primary" href="events.php">
            <i class="bi bi-lightning-charge-fill"></i> Activate an event
        </a>
    </div>
<?php endif; ?>
<?php if (!defined('ADMIN_EVENT_CONTEXT_SCRIPT_EMITTED')): define('ADMIN_EVENT_CONTEXT_SCRIPT_EMITTED', true); ?>
<style>
    .admin-event-context { background: var(--bs-tertiary-bg, #f8f9fa); border-left: 4px solid var(--bs-primary, #0d6efd); }
    html.dark-mode .admin-event-context { background: #1e293b; }
    .admin-event-context[data-phase="voting_open"]     { border-left-color: var(--bs-success, #198754); }
    .admin-event-context[data-phase="nominations_open"]{ border-left-color: var(--bs-primary, #0d6efd); }
    .admin-event-context[data-phase="voting_closed"]   { border-left-color: var(--bs-secondary, #6c757d); }
    .admin-event-context[data-phase="between"]         { border-left-color: var(--bs-info, #0dcaf0); }
    .admin-event-context[data-phase="unscheduled"]     { border-left-color: var(--bs-warning, #ffc107); }
</style>
<script>
(function () {
    function pluralize(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }
    function formatDiff(ms) {
        if (ms <= 0) return 'Ended';
        var totalMin = Math.floor(ms / 60000);
        var d = Math.floor(totalMin / (60 * 24));
        var h = Math.floor((totalMin % (60 * 24)) / 60);
        var m = totalMin % 60;
        if (d > 0)  return pluralize(d, 'day')  + ' ' + pluralize(h, 'hr');
        if (h > 0)  return pluralize(h, 'hr')   + ' ' + pluralize(m, 'min');
        if (m > 0)  return pluralize(m, 'min');
        return 'under a minute';
    }
    function tickAll() {
        var els = document.querySelectorAll('.admin-event-countdown');
        if (!els.length) return;
        var now = Date.now();
        els.forEach(function (el) {
            var deadline = el.getAttribute('data-deadline');
            if (!deadline) return;
            var target = Date.parse(deadline);
            if (isNaN(target)) return;
            var diff = target - now;
            var span = el.querySelector('.aec-count');
            var lbl  = el.querySelector('.aec-label');
            if (!span) return;
            if (diff <= 0) {
                span.textContent = 'Ended';
                if (lbl) lbl.textContent = '';
            } else {
                span.textContent = formatDiff(diff);
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tickAll);
    } else {
        tickAll();
    }
    setInterval(tickAll, 30000);
})();
</script>
<?php endif; ?>

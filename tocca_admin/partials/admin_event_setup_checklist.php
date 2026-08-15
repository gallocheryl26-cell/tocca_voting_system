<?php
/** @var array $setup from admin_event_setup_steps() */
declare(strict_types=1);

if (!isset($setup) || !is_array($setup)) {
    return;
}

$doneCount = (int) ($setup['done_count'] ?? 0);
$total     = (int) ($setup['total'] ?? 0);
$complete  = !empty($setup['complete']);
$eventLabel = trim((string) ($setup['event_label'] ?? ''));
$progressPct = $total > 0 ? (int) round(($doneCount / $total) * 100) : 0;
?>
<div class="card mb-4 admin-event-setup-checklist" id="eventSetupChecklist">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
    <span class="fw-semibold">
      <i class="bi bi-list-check me-1"></i>Event setup checklist
    </span>
    <?php if ($complete): ?>
      <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Setup complete</span>
    <?php else: ?>
      <span class="badge bg-secondary"><?= $doneCount; ?> / <?= $total; ?> complete</span>
    <?php endif; ?>
  </div>
  <div class="card-body py-3">
    <?php if ($eventLabel !== ''): ?>
      <p class="small text-muted mb-2">
        Active event: <strong><?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
      </p>
    <?php else: ?>
      <p class="small text-muted mb-2">
        Activate an event below to unlock the rest of the setup steps.
      </p>
    <?php endif; ?>

    <div class="progress mb-3" style="height: 6px;" role="progressbar"
         aria-valuenow="<?= $progressPct; ?>" aria-valuemin="0" aria-valuemax="100"
         aria-label="Event setup progress">
      <div class="progress-bar <?= $complete ? 'bg-success' : ''; ?>"
           style="width: <?= $progressPct; ?>%;"></div>
    </div>

    <ol class="list-group list-group-flush">
      <?php foreach ($setup['steps'] as $i => $step):
        $done = !empty($step['done']);
        $disabled = ($i > 0 && empty($setup['event_id']));
        $count = (int) ($step['count'] ?? 0);
      ?>
        <li class="list-group-item d-flex align-items-start gap-2 px-0 py-2 border-0 border-bottom">
          <span class="mt-1 flex-shrink-0 <?= $done ? 'text-success' : 'text-muted'; ?>">
            <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle'; ?>"></i>
          </span>
          <div class="flex-grow-1 min-w-0">
            <?php if ($disabled): ?>
              <span class="fw-semibold text-muted"><?= htmlspecialchars((string) $step['label'], ENT_QUOTES); ?></span>
            <?php else: ?>
              <a href="<?= htmlspecialchars((string) $step['url'], ENT_QUOTES); ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars((string) $step['label'], ENT_QUOTES); ?>
              </a>
            <?php endif; ?>
            <div class="small text-muted">
              <?= htmlspecialchars((string) $step['hint'], ENT_QUOTES); ?>
              <?php if ($count > 0 && $step['key'] !== 'activate'): ?>
                <span class="ms-1 badge bg-light text-dark border"><?= $count; ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($done && $step['key'] !== 'activate'): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle flex-shrink-0">Done</span>
          <?php elseif (!$disabled && !$done): ?>
            <a href="<?= htmlspecialchars((string) $step['url'], ENT_QUOTES); ?>"
               class="btn btn-sm btn-outline-primary flex-shrink-0">Set up</a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>

    <?php if ($complete): ?>
      <div class="alert alert-success small mb-0 mt-3 py-2">
        <i class="bi bi-party-popper me-1"></i>
        <strong><?= htmlspecialchars($eventLabel, ENT_QUOTES); ?></strong> is fully configured.
        You can proceed with registrations, emails, and voting.
      </div>
    <?php endif; ?>
  </div>
</div>

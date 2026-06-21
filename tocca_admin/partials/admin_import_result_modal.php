<?php
declare(strict_types=1);

/** @var array<string, mixed>|null $importFlash from $_SESSION['import_flash'] */
if (empty($importFlash) || !is_array($importFlash)) {
    return;
}

$title = (string) ($importFlash['title'] ?? 'Import complete');
$type  = (string) ($importFlash['type'] ?? 'success');
$eventLabel = (string) ($importFlash['event_label'] ?? '');
$rows = is_array($importFlash['rows'] ?? null) ? $importFlash['rows'] : [];
$skippedLinks = (int) ($importFlash['skipped_links'] ?? 0);
$warnings = is_array($importFlash['warnings'] ?? null) ? $importFlash['warnings'] : [];

$headerClass = $type === 'danger' ? 'bg-danger text-white' : ($type === 'warning' ? 'bg-warning' : 'bg-success text-white');
$iconClass = $type === 'danger' ? 'bi-x-circle-fill' : ($type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
?>
<div class="modal fade" id="importResultModal" tabindex="-1" aria-labelledby="importResultModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header <?php echo h($headerClass); ?> border-0 pb-0">
        <h5 class="modal-title d-flex align-items-center gap-2" id="importResultModalLabel">
          <i class="bi <?php echo h($iconClass); ?>" aria-hidden="true"></i>
          <?php echo h($title); ?>
        </h5>
      </div>
      <div class="modal-body">
        <?php if ($eventLabel !== ''): ?>
          <p class="text-muted small mb-3 mb-md-2">Imported into active event: <strong><?php echo h($eventLabel); ?></strong></p>
        <?php endif; ?>
        <?php if ($rows): ?>
          <ul class="list-group list-group-flush mb-0">
            <?php foreach ($rows as $row): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                <span><?php echo h((string) ($row['label'] ?? '')); ?></span>
                <span class="badge rounded-pill text-bg-primary"><?php echo (int) ($row['count'] ?? 0); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($skippedLinks > 0): ?>
          <p class="small text-muted mt-3 mb-0">Skipped duplicate award links: <?php echo $skippedLinks; ?></p>
        <?php endif; ?>
        <?php foreach ($warnings as $warning): ?>
          <div class="alert alert-warning small mt-3 mb-0 py-2"><?php echo h((string) $warning); ?></div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a href="questions.php" class="btn btn-outline-primary btn-sm">Name of Awards</a>
        <a href="establishment_types.php" class="btn btn-outline-primary btn-sm">Establishment Types</a>
        <a href="choices.php" class="btn btn-outline-primary btn-sm">Establishments</a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>

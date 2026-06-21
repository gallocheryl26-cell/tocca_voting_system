<?php
/**
 * Standard page title row.
 * @var string $pageHeading
 * @var array<int, array<string, string>>|null $breadcrumbItems
 * @var string|null $pageLead Optional subtitle
 */
?>
<div class="admin-page-header d-flex flex-wrap align-items-end justify-content-between gap-3 mt-4 mb-4">
  <div class="min-w-0">
    <h1 class="admin-page-title mb-2"><?php echo h($pageHeading ?? 'Page'); ?></h1>
    <?php if (!empty($breadcrumbItems) && function_exists('render_breadcrumb')) {
        echo render_breadcrumb($breadcrumbItems);
    } ?>
    <?php if (!empty($pageLead)): ?>
      <p class="text-muted small mb-0 mt-2"><?php echo h($pageLead); ?></p>
    <?php endif; ?>
  </div>
  <?php if (!empty($pageHeaderActions)) {
      echo $pageHeaderActions;
  } ?>
</div>
<?php
$showAdminEventContext = $showAdminEventContext ?? true;
if ($showAdminEventContext) {
    if (function_exists('render_admin_event_context')) {
        echo render_admin_event_context();
    } elseif (is_file(__DIR__ . '/admin_event_context.php')) {
        include __DIR__ . '/admin_event_context.php';
    }
}

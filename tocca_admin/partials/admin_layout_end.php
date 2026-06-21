<?php
/**
 * Standard admin page shell — close after page content.
 * @var array<int, string>|null $pageScripts Extra JS files (page logic)
 * @var bool|null $useDataTables Load jQuery + DataTables before Bootstrap
 * @var bool|null $useSimpleDatatables Load simple-datatables UMD
 * @var string|null $extraFoot Raw HTML after shell (modals, page-only markup)
 */
$useDataTables = !empty($useDataTables);
$useSimpleDatatables = !empty($useSimpleDatatables);
?>
      </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; <?php echo date('Y'); ?> Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
    </div>
  </div>

<?php if (!empty($extraFoot)) {
    echo $extraFoot;
} ?>

  <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
    <div id="globalToast" class="toast align-items-center text-bg-success border-0 mx-auto" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div id="globalToastBody" class="toast-body">Done</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <?php if ($useDataTables): ?>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
  <?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <?php if ($useSimpleDatatables): ?>
  <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js"></script>
  <?php endif; ?>
  <script src="js/scripts.js" defer></script>
  <script src="js/dark_mode_toggle.js" defer></script>
  <script src="logout.js" defer></script>
  <script>
  window.showToast = window.showToast || function (message, type, delay) {
    type = type || 'success';
    delay = delay || 3000;
    var toastEl = document.getElementById('globalToast');
    var bodyEl = document.getElementById('globalToastBody');
    if (!toastEl || !bodyEl) return;
    var map = { success: 'text-bg-success', danger: 'text-bg-danger', info: 'text-bg-info', warning: 'text-bg-warning' };
    toastEl.className = 'toast align-items-center border-0 mx-auto ' + (map[type] || map.success);
    bodyEl.textContent = message;
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: delay }).show();
  };
  </script>
  <?php
  if (!empty($pageScripts)) {
      foreach ($pageScripts as $script) {
          echo '<script src="' . h($script) . '"></script>' . "\n";
      }
  }
  if (!empty($_SESSION['flash_toast'])) {
      $msg = $_SESSION['flash_toast']['message'] ?? 'Done';
      $type = $_SESSION['flash_toast']['type'] ?? 'success';
      unset($_SESSION['flash_toast']);
      echo '<script>document.addEventListener("DOMContentLoaded",function(){window.showToast('
          . json_encode($msg) . ',' . json_encode($type) . ');});</script>' . "\n";
  }
  if (!empty($pageInlineScripts)) {
      echo $pageInlineScripts;
  }
  ?>
</body>
</html>

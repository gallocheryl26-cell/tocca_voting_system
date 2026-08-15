<?php
/** Shared top navbar + logout modal. Requires $navbarBg, $miniLogoPath from get_logo.php */
$adminDisplayName = 'Admin';
if (function_exists('getAdminDisplayName')) {
    $adminDisplayName = getAdminDisplayName();
} elseif (!empty($_SESSION['username'])) {
    $adminDisplayName = h((string) $_SESSION['username']);
}
?>
<nav class="sb-topnav navbar navbar-expand" style="background-color: <?php echo h($navbarBg ?? '#1e40af'); ?> !important;">
  <a class="navbar-brand ps-3 d-flex align-items-center gap-2" href="dashboard.php">
    <img src="<?php echo h($miniLogoPath ?? ''); ?>" alt="Mini Logo" style="height: 40px;">
    <span class="fw-bold text-uppercase text-white">Tatak Ormoc</span>
  </a>
  <button class="btn btn-link btn-sm me-2 text-white" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
    <i class="bi bi-list"></i>
  </button>
  <ul class="navbar-nav ms-auto me-3 me-lg-4 align-items-center">
    <li class="nav-item dropdown">
      <button
        type="button"
        class="nav-link dropdown-toggle admin-user-toggle text-light border-0 bg-transparent"
        id="navbarDropdown"
        data-bs-toggle="dropdown"
        data-bs-auto-close="true"
        aria-expanded="false"
      >
        <i class="bi bi-person-circle" aria-hidden="true"></i>
        <span class="admin-identity-name d-none d-md-inline"><?php echo $adminDisplayName; ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end admin-user-menu shadow" aria-labelledby="navbarDropdown">
        <li class="dropdown-header d-md-none"><?php echo $adminDisplayName; ?></li>
        <li>
          <a class="dropdown-item" href="admin_profile.php">
            <i class="bi bi-person me-2" aria-hidden="true"></i>My Profile
          </a>
        </li>
        <li><hr class="dropdown-divider my-1" /></li>
        <li>
          <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
            <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Logout
          </a>
        </li>
      </ul>
    </li>
  </ul>
</nav>
<?php include __DIR__ . '/admin_logout_modal.php'; ?>

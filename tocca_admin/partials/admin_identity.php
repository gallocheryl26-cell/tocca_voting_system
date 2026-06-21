<?php
if (!function_exists('getAdminDisplayName')) {
    function getAdminDisplayName(): string
    {
        $name = isset($_SESSION['username']) ? (string) $_SESSION['username'] : 'Admin';
        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }
}

$adminDisplayName = getAdminDisplayName();
?>
<li class="nav-item d-flex align-items-center px-2">
  <span class="text-light fw-semibold text-nowrap"><?php echo $adminDisplayName; ?></span>
</li>
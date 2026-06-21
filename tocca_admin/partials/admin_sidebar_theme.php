<?php
declare(strict_types=1);

/**
 * Single source of truth for admin sidebar + topnav colors (all pages).
 */
if (!function_exists('getConfig')) {
    require_once dirname(__DIR__) . '/get_logo.php';
}
require_once dirname(__DIR__) . '/includes/admin_sidebar_helpers.php';

$adminSidebarBg = $sidebarBg ?? getConfig('sidebar_bg_color', getConfig('sidebar_color', '#1e293b'));
$adminNavbarBg  = $navbarBg ?? getConfig('navbar_bg_color', getConfig('navbar_color', '#0d6efd'));
$preferredText  = $sidebarText ?? getConfig('sidebar_text_color', '#ffffff');
$adminSidebarText = admin_pick_sidebar_text_color($adminSidebarBg, $preferredText);
$stateColors = admin_sidebar_state_colors($adminSidebarBg);
?>
<style id="adminSidebarTheme">
  :root {
    --admin-sidebar-bg: <?php echo h($adminSidebarBg); ?>;
    --admin-sidebar-text: <?php echo h($adminSidebarText); ?>;
    --admin-navbar-bg: <?php echo h($adminNavbarBg); ?>;
    --admin-sidebar-active-top: <?php echo h($stateColors['active_top']); ?>;
    --admin-sidebar-active-nested: <?php echo h($stateColors['active_nested']); ?>;
    --admin-sidebar-open-parent: <?php echo h($stateColors['open_parent']); ?>;
    --admin-sidebar-hover: <?php echo h($stateColors['hover']); ?>;
  }

  html:not(.dark-mode) .sb-topnav.navbar {
    background-color: var(--admin-navbar-bg) !important;
  }

  #layoutSidenav_nav,
  #layoutSidenav_nav .sb-sidenav {
    background-color: var(--admin-sidebar-bg) !important;
    color: var(--admin-sidebar-text) !important;
  }

  #layoutSidenav_nav .nav-link,
  #layoutSidenav_nav .nav-link .sb-nav-link-icon,
  #layoutSidenav_nav .nav-link i,
  #layoutSidenav_nav .sb-sidenav-collapse-arrow,
  #layoutSidenav_nav .sb-sidenav-collapse-arrow i,
  #layoutSidenav_nav .sb-sidenav-menu-heading,
  #layoutSidenav_nav .sb-nested-icon {
    color: var(--admin-sidebar-text) !important;
    fill: var(--admin-sidebar-text) !important;
  }

  #layoutSidenav_nav .admin-active-event-label {
    color: var(--admin-sidebar-text) !important;
    opacity: 0.72;
  }

  #layoutSidenav_nav .admin-active-event-title {
    color: var(--admin-sidebar-text) !important;
  }

  #layoutSidenav_nav .admin-active-event-block a {
    color: var(--admin-sidebar-text) !important;
    opacity: 0.9;
  }

  #layoutSidenav_nav .admin-active-event-title {
    display: block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
</style>

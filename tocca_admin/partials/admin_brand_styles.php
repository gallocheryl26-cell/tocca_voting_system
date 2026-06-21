<?php
/** Navbar color from tbl_config; sidebar colors come from partials/admin_sidebar_theme.php */
$brandNavbar = $navbarBg ?? getConfig('navbar_bg_color', getConfig('navbar_color', '#1e40af'));
?>
<style id="adminNavbarTheme">
  .sb-topnav { background-color: <?php echo h($brandNavbar); ?> !important; }
</style>

<?php
/**
 * Standard admin page shell — open through <main>.
 * @var string $pageTitle
 * @var string|null $extraHead Raw HTML for extra <head> tags
 * @var bool|null $useDataTables Include jQuery + DataTables CSS/JS in head
 * @var bool|null $useSimpleDatatables Include simple-datatables CSS
 */
if (!isset($pageTitle)) {
    $pageTitle = 'Admin';
}
$useDataTables = !empty($useDataTables);
$useSimpleDatatables = !empty($useSimpleDatatables);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title><?php echo h($pageTitle); ?> | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo h($faviconPath ?? ''); ?>">
  <?php if ($useDataTables): ?>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <?php endif; ?>
  <?php if ($useSimpleDatatables): ?>
  <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
  <?php endif; ?>
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <?php include dirname(__DIR__) . '/inline_style.php'; ?>
  <?php include __DIR__ . '/admin_brand_styles.php'; ?>
  <?php if (!empty($extraHead)) {
      echo $extraHead;
  } ?>
</head>
<body class="sb-nav-fixed">
  <?php include __DIR__ . '/admin_topnav.php'; ?>
  <div id="layoutSidenav">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>
    <div id="layoutSidenav_content">
      <main>

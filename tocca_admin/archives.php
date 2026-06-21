<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

include 'db_connection.php';

$event_id = null;
$result = $conn->query("SELECT event_id FROM tbl_events WHERE is_active = 1 LIMIT 1");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $event_id = (int)$row['event_id'];
}
?>

<!DOCTYPE html>
  <html lang="en">
  <head>
    <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>Archives | Tatak Ormoc</title>
        <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet"/>
        <link href="css/styles.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <?php include 'inline_style.php'; ?>
        <script>const currentEventId = <?= isset($event_id) ? (int)$event_id : 'null' ?>;</script>
        <style>
        .dataTables_wrapper .dt-buttons { float: right; margin-bottom: 10px; }
        .dataTables_filter { float: right !important; }
        .accordion-body ul li a.btn { margin-left: 10px; }
        </style>
        <style>
          .notif-badge { min-width: 90px; display: inline-block; text-align: center; }
          .notif-text  { flex: 1; }
        </style>	
    </head>
    <body class="sb-nav-fixed">
      <?php include __DIR__ . '/partials/admin_topnav.php'; ?>

<div id="layoutSidenav">
            <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

<div id="layoutSidenav_content">
            <main>
            <div class="container-fluid px-4">
                          <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">Archives</h1>
              <?php echo render_utilities_breadcrumb([['label' => 'Archives']]); ?>
            </div>
          </div>

                <div class="accordion" id="archiveAccordion">
                    <?php
                    $archivedEvents = $conn->query("SELECT * FROM tbl_events WHERE is_archived = 1 ORDER BY archived_date DESC");
                    if ($archivedEvents && $archivedEvents->num_rows > 0) {
                        $index = 0;
                        while ($event = $archivedEvents->fetch_assoc()) {
                            $eventId = $event['event_id'];
                            $eventName = htmlspecialchars($event['event_name']);
                            $year = $event['year'];
                            $archivedDate = date("F d, Y", strtotime($event['archived_date']));
                            echo "
                            <div class='accordion-item'>
                                <h2 class='accordion-header' id='heading{$index}'>
                                    <button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#collapse{$index}' aria-expanded='false' aria-controls='collapse{$index}'>
                                        {$eventName} {$year} - Archived on {$archivedDate}
                                    </button>
                                </h2>
                                <div id='collapse{$index}' class='accordion-collapse collapse' aria-labelledby='heading{$index}' data-bs-parent='#archiveAccordion'>
                                    <div class='accordion-body'>
                                        <ul>
                                            <li><strong>Categories:</strong> <a href='#' class='text-primary view-archive-section' data-type='categories' data-event-id='{$eventId}'>View</a></li>
                                            <li><strong>Questions:</strong> <a href='#' class='text-primary view-archive-section' data-type='questions' data-event-id='{$eventId}'>View</a></li>
                                            <li><strong>Choices:</strong> <a href='#' class='text-primary view-archive-section' data-type='choices' data-event-id='{$eventId}'>View</a></li>
                                            <li><strong>Results:</strong> <a href='#' class='text-primary view-archive-section' data-type='results' data-event-id='{$eventId}'>View</a></li>
                                            <li><strong>Voters:</strong> <a href='#' class='text-primary view-archive-section' data-type='voters' data-event-id='{$eventId}'>View</a></li>
                                        </ul>
                                        <button class='btn btn-success btn-sm restore-archive-btn mt-2' data-event-id='{$eventId}'>Restore</button>
                                        <button class='btn btn-danger btn-sm delete-archive-btn mt-2 ms-2' data-event-id='{$eventId}'>Delete</button>
                                    </div>
                                </div>
                            </div>
                            ";
                            $index++;
                        }
                    }else {
                        echo "<div class='alert alert-info'>No archived events found.</div>";
                    }
                    ?>
                </div>
            </div>
        </main>

    <!-- View Archive Modal -->
    <div class="modal fade" id="viewArchiveModal" tabindex="-1" aria-labelledby="viewArchiveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewArchiveModalLabel">Archive Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-center">Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast (top-right) -->
    <div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080;">
      <div id="toastMsg" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div id="toastBody" class="toast-body">Done.</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>
    </div>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
        <script src="archives.js"></script>
    
  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>

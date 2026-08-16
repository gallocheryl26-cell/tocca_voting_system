<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/admin_nav.php';



if (!function_exists('h')) {

    function h($s): string

    {

        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    }

}



admin_apply_nav_from_script();



global $adminActivePage, $adminActiveNested;

$regOpen = in_array($adminActiveNested ?? '', ['nominations', 'award_validation'], true);

$fmOpen = in_array($adminActiveNested ?? '', ['events', 'categories', 'questions', 'choices', 'nomination_fields', 'establishment_types'], true);

$txOpenNested = ['communications', 'communications_qr'];

$txOpen = in_array($adminActiveNested ?? '', $txOpenNested, true);

$fbOpen = in_array($adminActiveNested ?? '', ['nomination_feedbacks', 'voters_feedbacks'], true);

$rpOpen = in_array($adminActiveNested ?? '', ['nomination_reports', 'voters', 'vote_proofs', 'twg_evaluation', 'results'], true);

$utOpen = in_array($adminActiveNested ?? '', ['system_utilities', 'archives', 'audit_logs'], true);

$cuOpen = in_array($adminActiveNested ?? '', ['admin_settings', 'voter_portal', 'nomination_settings', 'public_url_config'], true);

$vpOpen = false;

?>

<div id="layoutSidenav_nav">

  <nav class="sb-sidenav accordion" id="sidenavAccordion">

    <div class="sb-sidenav-menu">

      <div class="nav">

        <div class="py-4 text-center">

          <img src="<?php echo h($logoPath ?? ''); ?>" alt="Sidebar Logo" style="height:130px;width:130px;display:block;margin:0 auto;">

        </div>

        <?php

        $activeEventLabel = null;

        if (isset($conn) && $conn instanceof mysqli) {

            if ($evRes = $conn->query(

                "SELECT event_name, year FROM tbl_events

                 WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0

                 ORDER BY year DESC, event_id DESC

                 LIMIT 1"

            )) {

                if ($er = $evRes->fetch_assoc()) {

                    $activeEventLabel = trim(($er['event_name'] ?? '') . ' ' . ($er['year'] ?? ''));

                }

                $evRes->free();

            }

        }

        if ($activeEventLabel !== null && $activeEventLabel !== '') : ?>

        <div class="admin-active-event-block">

          <div class="admin-active-event-label">Active event</div>

          <div class="admin-active-event-title" title="<?php echo h($activeEventLabel); ?>"><?php echo h($activeEventLabel); ?></div>

        </div>

        <?php elseif (isset($conn) && $conn instanceof mysqli) : ?>

        <div class="admin-active-event-block">

          <div class="small text-warning">No active event</div>

          <a class="small text-decoration-underline" href="events.php">Activate in Events</a>

        </div>

        <?php endif; ?>

        <a class="<?php echo ($adminActivePage ?? '') === 'dashboard.php' ? 'nav-link active' : 'nav-link'; ?>" href="dashboard.php">

          <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>Dashboard

          <div class="sb-sidenav-collapse-arrow sb-sidenav-collapse-arrow--spacer" aria-hidden="true"><i class="fas fa-angle-down"></i></div>

        </a>

        <a class="nav-link<?php echo $regOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseRegistration" aria-expanded="<?php echo $regOpen ? 'true' : 'false'; ?>" aria-controls="collapseRegistration">

          <div class="sb-nav-link-icon"><i class="fas fa-user-check"></i></div>Registration

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $regOpen ? ' show' : ''; ?>" id="collapseRegistration" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="<?php echo ($adminActiveNested ?? '') === 'nominations' ? 'nav-link active' : 'nav-link'; ?>" href="nominations.php"><i class="bi bi-person-check sb-nested-icon" aria-hidden="true"></i><span>Submissions</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'award_validation' ? 'nav-link active' : 'nav-link'; ?>" href="award_validation_log.php"><i class="bi bi-shield-check sb-nested-icon" aria-hidden="true"></i><span>Awards Validation</span></a>

          </nav>

        </div>

        <a class="nav-link<?php echo $fmOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseFileMaintenance" aria-expanded="<?php echo $fmOpen ? 'true' : 'false'; ?>" aria-controls="collapseFileMaintenance">

          <div class="sb-nav-link-icon"><i class="fas fa-folder-open"></i></div>File Maintenance

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $fmOpen ? ' show' : ''; ?>" id="collapseFileMaintenance" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="<?php echo ($adminActiveNested ?? '') === 'events' ? 'nav-link active' : 'nav-link'; ?>" href="events.php"><i class="bi bi-calendar-event sb-nested-icon" aria-hidden="true"></i><span>Events</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'categories' ? 'nav-link active' : 'nav-link'; ?>" href="categories.php"><i class="bi bi-tags sb-nested-icon" aria-hidden="true"></i><span>Categories</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'questions' ? 'nav-link active' : 'nav-link'; ?>" href="questions.php"><i class="bi bi-award sb-nested-icon" aria-hidden="true"></i><span>Name of Awards</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'establishment_types' ? 'nav-link active' : 'nav-link'; ?>" href="establishment_types.php"><i class="bi bi-diagram-3 sb-nested-icon" aria-hidden="true"></i><span>Nature of Business</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'choices' ? 'nav-link active' : 'nav-link'; ?>" href="choices.php"><i class="bi bi-shop sb-nested-icon" aria-hidden="true"></i><span>Businesses</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'nomination_fields' ? 'nav-link active' : 'nav-link'; ?>" href="nomination_fields.php"><i class="bi bi-ui-checks sb-nested-icon" aria-hidden="true"></i><span>Registration Form</span></a>

          </nav>

        </div>

        <a class="nav-link<?php echo $txOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseTransactions" aria-expanded="<?php echo $txOpen ? 'true' : 'false'; ?>" aria-controls="collapseTransactions">

          <div class="sb-nav-link-icon"><i class="fas fa-paper-plane"></i></div>Transactions

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $txOpen ? ' show' : ''; ?>" id="collapseTransactions" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="<?php echo ($adminActiveNested ?? '') === 'communications' ? 'nav-link active' : 'nav-link'; ?>" href="communications.php"><i class="bi bi-envelope sb-nested-icon" aria-hidden="true"></i><span>Registration Emails</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'communications_qr' ? 'nav-link active' : 'nav-link'; ?>" href="communications_qr.php"><i class="bi bi-qr-code sb-nested-icon" aria-hidden="true"></i><span>QR Emails</span></a>

          </nav>

        </div>

        <a class="nav-link<?php echo $fbOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseFeedbacks" aria-expanded="<?php echo $fbOpen ? 'true' : 'false'; ?>" aria-controls="collapseFeedbacks">

          <div class="sb-nav-link-icon"><i class="fas fa-comments"></i></div>Feedbacks

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $fbOpen ? ' show' : ''; ?>" id="collapseFeedbacks" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="<?php echo ($adminActiveNested ?? '') === 'nomination_feedbacks' ? 'nav-link active' : 'nav-link'; ?>" href="nomination_feedbacks.php"><i class="bi bi-chat-left-text sb-nested-icon" aria-hidden="true"></i><span>Registration</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'voters_feedbacks' ? 'nav-link active' : 'nav-link'; ?>" href="voters_feedbacks.php"><i class="bi bi-phone sb-nested-icon" aria-hidden="true"></i><span>Voting</span></a>

          </nav>

        </div>

        <a class="nav-link<?php echo $rpOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseReports" aria-expanded="<?php echo $rpOpen ? 'true' : 'false'; ?>" aria-controls="collapseReports">

          <div class="sb-nav-link-icon"><i class="fas fa-chart-bar"></i></div>Reports

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $rpOpen ? ' show' : ''; ?>" id="collapseReports" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="<?php echo ($adminActiveNested ?? '') === 'nomination_reports' ? 'nav-link active' : 'nav-link'; ?>" href="nomination_reports.php"><i class="bi bi-file-earmark-text sb-nested-icon" aria-hidden="true"></i><span>Registration</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'voters' ? 'nav-link active' : 'nav-link'; ?>" href="voters.php"><i class="bi bi-people sb-nested-icon" aria-hidden="true"></i><span>Voters</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'vote_proofs' ? 'nav-link active' : 'nav-link'; ?>" href="vote_proofs.php"><i class="bi bi-images sb-nested-icon" aria-hidden="true"></i><span>Proof of Purchase</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'twg_evaluation' ? 'nav-link active' : 'nav-link'; ?>" href="twg_evaluation.php"><i class="bi bi-clipboard-check sb-nested-icon" aria-hidden="true"></i><span>TWG Evaluation</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'results' ? 'nav-link active' : 'nav-link'; ?>" href="results.php"><i class="bi bi-bar-chart-line sb-nested-icon" aria-hidden="true"></i><span>Results</span></a>

          </nav>

        </div>

        <a class="nav-link<?php echo $utOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseUtilities" aria-expanded="<?php echo $utOpen ? 'true' : 'false'; ?>" aria-controls="collapseUtilities">

          <div class="sb-nav-link-icon"><i class="fas fa-tools"></i></div>Utilities

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $utOpen ? ' show' : ''; ?>" id="collapseUtilities" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="<?php echo ($adminActiveNested ?? '') === 'system_utilities' ? 'nav-link active' : 'nav-link'; ?>" href="system_utilities.php"><i class="bi bi-gear-wide-connected sb-nested-icon" aria-hidden="true"></i><span>System Utilities</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'archives' ? 'nav-link active' : 'nav-link'; ?>" href="archives.php"><i class="bi bi-archive sb-nested-icon" aria-hidden="true"></i><span>Archives</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'audit_logs' ? 'nav-link active' : 'nav-link'; ?>" href="audit_logs.php"><i class="bi bi-journal-text sb-nested-icon" aria-hidden="true"></i><span>Audit Logs</span></a>

          </nav>

        </div>

        <a class="nav-link<?php echo $cuOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseCustomizations" aria-expanded="<?php echo $cuOpen ? 'true' : 'false'; ?>" aria-controls="collapseCustomizations">

          <div class="sb-nav-link-icon"><i class="fas fa-palette"></i></div>Customizations

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $cuOpen ? ' show' : ''; ?>" id="collapseCustomizations" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="<?php echo ($adminActiveNested ?? '') === 'admin_settings' ? 'nav-link active' : 'nav-link'; ?>" href="admin_settings.php"><i class="bi bi-sliders sb-nested-icon" aria-hidden="true"></i><span>Admin Settings</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'voter_portal' ? 'nav-link active' : 'nav-link'; ?>" href="voter_portal_copy.php"><i class="bi bi-person-badge sb-nested-icon" aria-hidden="true"></i><span>Voter Portal</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'nomination_settings' ? 'nav-link active' : 'nav-link'; ?>" href="nomination_settings.php"><i class="bi bi-ui-checks sb-nested-icon" aria-hidden="true"></i><span>Registration Settings</span></a>

            <a class="<?php echo ($adminActiveNested ?? '') === 'public_url_config' ? 'nav-link active' : 'nav-link'; ?>" href="public_url_config.php"><i class="bi bi-link-45deg sb-nested-icon" aria-hidden="true"></i><span>Public Share Links</span></a>

          </nav>

        </div>

        <a class="nav-link<?php echo $vpOpen ? '' : ' collapsed'; ?>" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#collapseVoterPortal" aria-expanded="<?php echo $vpOpen ? 'true' : 'false'; ?>" aria-controls="collapseVoterPortal">

          <div class="sb-nav-link-icon"><i class="fas fa-id-card"></i></div>User Portal

          <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>

        </a>

        <div class="collapse<?php echo $vpOpen ? ' show' : ''; ?>" id="collapseVoterPortal" data-bs-parent="#sidenavAccordion">

          <nav class="sb-sidenav-menu-nested nav">

            <a class="nav-link" href="../nomination/nomination_form.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right sb-nested-icon" aria-hidden="true"></i><span>Open Registration Page</span></a>

            <a class="nav-link" href="../nomination/nomination_tracking.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right sb-nested-icon" aria-hidden="true"></i><span>Open Tracking Page</span></a>

            <a class="nav-link" href="../e-vote-final-enhanced/index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right sb-nested-icon" aria-hidden="true"></i><span>Open Voter Page</span></a>

          </nav>

        </div>

      </div>

    </div>

  </nav>

</div>


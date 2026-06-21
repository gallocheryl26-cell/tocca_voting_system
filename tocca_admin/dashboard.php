<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script();
$event_id = admin_active_event_id($conn);
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
    <title>Dashboard | Tatak Ormoc</title>
    <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <?php include 'inline_style.php'; ?>

    <style>
      .chart-wrap    { position: relative; height: 320px; width: 100%; }
      .chart-wrap-sm { position: relative; height: 260px; width: 100%; }
      canvas#categoryChart,
      canvas#nomsTrend,
      canvas#nomsStatus {
        width: 100% !important;
        height: 100% !important;
        display: block;
      }
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
                <h1 class="admin-page-title mb-2">Dashboard</h1>
                <?php echo render_breadcrumb([['label' => 'Dashboard']]); ?>
              </div>
            </div>
          <?php echo render_admin_event_context(); ?>

            <!-- Simplified Event Overview -->
            <div class="card mb-4">
              <div class="card-header">
                <i class="fas fa-info-circle me-1"></i>
                <span id="activeEventTitle">Loading event…</span>
              </div>
              <div class="card-body">
                <p class="mb-2"><strong>Nomination:</strong> <span id="nominationPeriodRange">Loading...</span></p>
                <p class="mb-2"><strong>Voting:</strong> <span id="votingPeriodRange">Loading...</span></p>
                <p class="mb-2"><strong>Status:</strong> <span id="activePhaseLabel">Loading...</span></p>
                <p class="mb-0"><strong>Time Remaining:</strong> <span id="countdownTimer">Loading...</span></p>
              </div>
            </div>

            <input type="hidden" id="currentEventId" value="<?= htmlspecialchars((string)$event_id) ?>">

            <div id="dashboardIdleBlock" class="alert alert-secondary mb-4" style="display:none;" role="status">
              <span id="dashboardIdleMessage">No active nomination or voting period for this event.</span>
            </div>

            <!-- Nomination period: stats -->
            <div id="dashboardNomStats" class="row" style="display:none;">
              <div class="col-xl-3 col-md-6">
                <div class="card bg-primary text-white mb-4">
                  <div class="card-body">Total Nominations</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-white" id="nomTotal">...</span>
                  </div>
                </div>
              </div>
              <div class="col-xl-3 col-md-6">
                <div class="card bg-warning text-dark mb-4">
                  <div class="card-body">Pending</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span id="nomPending">...</span>
                  </div>
                </div>
              </div>
              <div class="col-xl-3 col-md-6">
                <div class="card bg-info text-white mb-4">
                  <div class="card-body">In Review</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-white" id="nomInReview">...</span>
                  </div>
                </div>
              </div>
              <div class="col-xl-3 col-md-6">
                <div class="card bg-success text-white mb-4">
                  <div class="card-body">Approved</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-white" id="nomApproved">...</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Nomination period: charts -->
            <div id="dashboardNomCharts" class="row g-4 mb-4" style="display:none;">
              <div class="col-lg-8">
                <div class="card h-100 mb-0">
                  <div class="card-header"><i class="bi bi-graph-up-arrow me-1"></i>Nominations (Last 30 Days)</div>
                  <div class="card-body">
                    <div class="chart-wrap"><canvas id="nomsTrend"></canvas></div>
                    <div id="nomsTrendEmpty" class="text-muted text-center mt-3" style="display:none;">No nomination data in the selected window.</div>
                  </div>
                </div>
              </div>
              <div class="col-lg-4">
                <div class="card h-100 mb-0">
                  <div class="card-header"><i class="bi bi-segmented-nav me-1"></i>Nominations by Status</div>
                  <div class="card-body">
                    <div class="chart-wrap-sm"><canvas id="nomsStatus"></canvas></div>
                    <div id="nomsStatusEmpty" class="text-muted text-center mt-3" style="display:none;">No nominations found.</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Voting period: stats -->
            <div id="dashboardVoteStats" class="row" style="display:none;">
              <div class="col-xl-3 col-md-6">
                <div class="card bg-primary text-white mb-4">
                  <div class="card-body">Total Votes</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-white" id="totalVotes">...</span>
                  </div>
                </div>
              </div>
              <div class="col-xl-3 col-md-6">
                <div class="card bg-info text-white mb-4">
                  <div class="card-body">Registered Voters</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-white" id="registeredVoters">...</span>
                  </div>
                </div>
              </div>
              <div class="col-xl-3 col-md-6">
                <div class="card bg-success text-white mb-4">
                  <div class="card-body">Complete Votes</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-white" id="voted">...</span>
                  </div>
                </div>
              </div>
              <div class="col-xl-3 col-md-6">
                <div class="card bg-warning text-dark mb-4">
                  <div class="card-body">Drafted Votes</div>
                  <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-white" id="notVoted">...</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Voting period: charts -->
            <div id="dashboardVoteCharts" class="row" style="display:none;">
              <div class="col-xl-8 h-100">
                <div class="card mb-4 h-100 d-flex flex-column">
                  <div class="card-header">
                    <i class="fas fa-chart-bar me-1"></i>
                    Total Votes Per Category
                  </div>
                  <div class="card-body flex-grow-1">
                    <div class="chart-wrap">
                      <canvas id="categoryChart"></canvas>
                    </div>
                    <div id="noChartData" class="text-muted text-center mt-3" style="display: none;">
                      No vote data available for this event.
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-xl-4 h-100">
                <div class="card mb-4 h-100 d-flex flex-column">
                  <div class="card-header">
                    <i class="fas fa-trophy me-1"></i>
                    Leading Business per Category
                  </div>
                  <div class="card-body flex-grow-1 overflow-auto">
                    <ul class="list-group" id="voteResultList"></ul>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
</div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="get_top_votes.js"></script>
    <script src="nominations_charts.js"></script>

    <script src="js/dashboard_phase.js"></script>

  
  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>

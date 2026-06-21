<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/voter_portal_copy.php';
require_once __DIR__ . '/includes/voter_appearance.php';
require_once __DIR__ . '/includes/voter_portal_helpers.php';
require_once __DIR__ . '/audit_log.php';
require_once dirname(__DIR__) . '/nomination/rich_text_helpers.php';

admin_apply_nav_from_script(basename(__FILE__));

voter_portal_copy_ensure_schema($conn);

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'appearance') ? 'appearance' : 'content';

$activeEvent = admin_get_active_event($conn);
$eventId     = $activeEvent ? (int) ($activeEvent['event_id'] ?? 0) : 0;
$eventName   = $activeEvent ? (string) ($activeEvent['event_name'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saveSection = (string) ($_POST['save_section'] ?? 'content');
    $isAjax      = !empty($_POST['ajax'])
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    $ok      = false;
    $message = 'Save failed.';
    $jsonAppearance = null;

    if ($saveSection === 'appearance') {
        $ok = voter_appearance_save_from_request($conn, $_POST, $_FILES);
        $message = $ok
            ? 'Voter appearance saved.'
            : 'Some appearance settings failed to save. Please try again.';
        $activeTab = 'appearance';
        if ($ok) {
            $loaded = voter_appearance_load($conn);
            $jsonAppearance = [
                'bgColor'       => $loaded['bgColor'],
                'textColor'     => $loaded['textColor'],
                'headerLogo'    => $loaded['headerLogo'],
                'adminLogoSrc'  => voter_appearance_admin_logo_src($loaded['headerLogo']),
            ];
        }
    } elseif ($eventId <= 0) {
        $message = 'Select an active event before saving voter copy.';
        $activeTab = 'content';
    } else {
        $incoming = voter_portal_copy_from_post($_POST);
        $before   = voter_portal_copy_load($conn, $eventId);
        $ok       = voter_portal_copy_save($conn, $eventId, $incoming);

        if ($ok) {
            try {
                audit_log($conn, 'voter_portal', 'update', 'voter_portal_copy', $eventId, [
                    'event_id'   => $eventId,
                    'event_name' => $eventName,
                    'old'        => $before,
                    'new'        => voter_portal_copy_load($conn, $eventId),
                ]);
            } catch (Throwable $e) {
                error_log('voter_portal_copy audit failed: ' . $e->getMessage());
            }
            $message = 'Voter page content saved.';
        } else {
            $message = 'Could not save voter page content.';
        }
        $activeTab = 'content';
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status'     => $ok ? 'success' : 'error',
            'message'    => $message,
            'tab'        => $activeTab,
            'appearance' => $jsonAppearance,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['flash_toast'] = [
        'message' => $message,
        'type'    => $ok ? 'success' : ($eventId <= 0 && $saveSection !== 'appearance' ? 'warning' : 'danger'),
    ];
    session_write_close();
    header('Location: voter_portal_copy.php?tab=' . urlencode($activeTab));
    exit;
}

$appearance = voter_appearance_load($conn);
$bgColor    = $appearance['bgColor'];
$textColor  = $appearance['textColor'];
$headerLogo       = $appearance['headerLogo'];
$headerLogoAdmin  = voter_appearance_admin_logo_src($headerLogo);

$copy = voter_portal_copy_load($conn, $eventId);
$eventStats = voter_portal_event_stats($conn, $eventId);
$flowStages = voter_portal_flow_reference();
$suggestedLead = voter_portal_suggested_lead($eventStats);
$voterPageUrl = '../e-vote-final-enhanced/index.php';
$voterPreviewUrl = 'voter_portal_preview.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Voter Portal | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
  <link href="css/voter_portal_settings.css" rel="stylesheet" />
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
              <h1 class="admin-page-title mb-2">Voter Portal</h1>
              <?php echo render_customizations_breadcrumb([['label' => 'Voter Portal']]); ?>
              <p class="text-muted small mb-0 mt-1">Edit on the left — the voter page updates live on the right. Save when you are ready.</p>
              <p id="vpcSaveStatus" class="vpc-save-status text-muted small mb-0 mt-1" aria-live="polite"></p>
            </div>
            <a class="btn btn-outline-primary btn-sm shrink-0" href="<?php echo h($voterPageUrl); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i> Open voter page</a>
          </div>

          <?php echo render_admin_event_context(); ?>

          <ul class="nav nav-tabs voter-portal-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link<?php echo $activeTab === 'content' ? ' active' : ''; ?>"
                      id="tab-content-btn" data-bs-toggle="tab" data-bs-target="#tabContentPane"
                      type="button" role="tab" aria-controls="tabContentPane"
                      aria-selected="<?php echo $activeTab === 'content' ? 'true' : 'false'; ?>">
                <i class="bi bi-megaphone me-1"></i> Page content
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link<?php echo $activeTab === 'appearance' ? ' active' : ''; ?>"
                      id="tab-appearance-btn" data-bs-toggle="tab" data-bs-target="#tabAppearancePane"
                      type="button" role="tab" aria-controls="tabAppearancePane"
                      aria-selected="<?php echo $activeTab === 'appearance' ? 'true' : 'false'; ?>">
                <i class="bi bi-palette2 me-1"></i> Appearance
              </button>
            </li>
          </ul>

          <div class="vpc-card mb-3">
            <div class="vpc-card-head d-flex flex-wrap justify-content-between align-items-center gap-2">
              <span><i class="bi bi-diagram-3 me-1"></i> Voting flow (system logic)</span>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($voterPageUrl); ?>" target="_blank" rel="noopener">Test live site</a>
            </div>
            <div class="vpc-card-body">
              <div class="vpc-event-stats">
                <?php if ($eventId > 0): ?>
                  <span class="vpc-stat-pill"><?php echo h($eventStats['event_name']); ?><?php echo $eventStats['event_year'] !== '' ? ' · ' . h($eventStats['event_year']) : ''; ?></span>
                  <span class="vpc-stat-pill"><?php echo (int) $eventStats['category_count']; ?> categories</span>
                  <span class="vpc-stat-pill"><?php echo (int) $eventStats['award_count']; ?> awards</span>
                  <span class="vpc-stat-pill <?php echo $eventStats['voting_open'] ? 'is-open' : 'is-closed'; ?>">
                    <?php echo $eventStats['voting_open'] ? 'Voting open now' : 'Voting not open'; ?>
                  </span>
                <?php else: ?>
                  <span class="vpc-stat-pill is-closed">No active event</span>
                <?php endif; ?>
              </div>
              <p class="small text-muted mb-2">Blue steps are editable below. Grey steps use fixed pages and OTP logic (<code>lib/voter_flow.php</code>).</p>
              <ol class="vpc-flow-map" id="vpcFlowMap">
                <?php foreach ($flowStages as $i => $stage): ?>
                <li class="vpc-flow-item<?php echo $stage['editable'] ? ' is-editable' : ''; ?>"
                    data-flow-key="<?php echo h($stage['key']); ?>">
                  <span class="vpc-flow-num"><?php echo $i + 1; ?></span>
                  <div>
                    <div class="vpc-flow-label"><?php echo h($stage['label']); ?></div>
                    <div class="vpc-flow-meta"><?php echo h($stage['page']); ?></div>
                    <p class="vpc-flow-hint mb-0"><?php echo h($stage['hint']); ?></p>
                  </div>
                  <?php if ($stage['editable']): ?>
                  <button type="button" class="btn btn-outline-primary btn-sm vpc-flow-jump" data-flow-jump="<?php echo h($stage['key']); ?>">Edit</button>
                  <?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ol>
            </div>
          </div>

          <div class="vpc-studio">
            <div class="vpc-editor">
          <div class="tab-content">
            <div class="tab-pane fade<?php echo $activeTab === 'content' ? ' show active' : ''; ?>"
                 id="tabContentPane" role="tabpanel" aria-labelledby="tab-content-btn" tabindex="0">

          <?php if ($eventId <= 0): ?>
            <div class="alert alert-warning">No active event is selected. Activate an event under <a href="events.php">Events</a> before editing voter copy.</div>
          <?php endif; ?>

          <form method="post" id="voterPortalCopyForm" action="voter_portal_copy.php?tab=content"<?php echo $eventId <= 0 ? ' class="pe-none opacity-75"' : ''; ?>>
            <input type="hidden" name="save_section" value="content">
            <div class="vpc-toolbar-actions">
              <button type="submit" class="btn btn-primary"<?php echo $eventId <= 0 ? ' disabled' : ''; ?>>
                <i class="bi bi-save me-1"></i> Save page content
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="vpcUseSuggestedLead"<?php echo $eventId <= 0 ? ' disabled' : ''; ?>>
                Use suggested intro line
              </button>
              <span class="small text-muted">Ctrl+S to save · preview updates as you type</span>
            </div>

            <div class="accordion vpc-section-collapse" id="vpcContentAccordion">
              <div class="accordion-item" id="vpcSectionWelcome">
                <h2 class="accordion-header">
                  <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#vpcCollapseWelcome" aria-expanded="true">
                    <i class="bi bi-chat-square-text me-2"></i> Welcome modal
                  </button>
                </h2>
                <div id="vpcCollapseWelcome" class="accordion-collapse collapse show" data-bs-parent="#vpcContentAccordion">
                  <div class="accordion-body">
                    <div class="mb-3">
                      <label for="intro_title" class="form-label fw-semibold">Modal title</label>
                      <input type="text" class="form-control" id="intro_title" name="intro_title" maxlength="255"
                             value="<?php echo h($copy['intro_title']); ?>" data-preview-field="intro_title" />
                    </div>
                    <div class="mb-0">
                      <label for="intro_body" class="form-label fw-semibold">Welcome text</label>
                      <p class="text-muted small mb-2">Markdown: <code>**bold**</code>, <code>*italic*</code>, blank lines = paragraphs. Shown after voters accept Terms &amp; Privacy.</p>
                      <textarea class="form-control font-monospace" id="intro_body" name="intro_body" rows="12"
                                data-preview-field="intro_body"><?php echo h($copy['intro_body']); ?></textarea>
                    </div>
                  </div>
                </div>
              </div>

              <div class="accordion-item" id="vpcSectionLanding">
                <h2 class="accordion-header">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#vpcCollapseLanding">
                    <i class="bi bi-list-ol me-2"></i> How to vote (landing page)
                  </button>
                </h2>
                <div id="vpcCollapseLanding" class="accordion-collapse collapse" data-bs-parent="#vpcContentAccordion">
                  <div class="accordion-body">
                    <div class="mb-3">
                      <label for="how_to_title" class="form-label fw-semibold">Section heading (blue banner)</label>
                      <input type="text" class="form-control" id="how_to_title" name="how_to_title" maxlength="255"
                             value="<?php echo h($copy['how_to_title']); ?>" data-preview-field="how_to_title" />
                    </div>
                    <div class="mb-3">
                      <label for="how_to_lead" class="form-label fw-semibold">Intro paragraph</label>
                      <textarea class="form-control" id="how_to_lead" name="how_to_lead" rows="2"
                                data-preview-field="how_to_lead"
                                placeholder="<?php echo h($suggestedLead); ?>"><?php echo h($copy['how_to_lead']); ?></textarea>
                      <?php if ($eventId > 0): ?>
                      <div class="form-text">Suggested for this event: <?php echo h($suggestedLead); ?></div>
                      <?php endif; ?>
                    </div>
                    <?php foreach ($copy['steps'] as $idx => $step): $n = $idx + 1; ?>
                      <div class="border rounded p-3 mb-3 bg-light-subtle" id="vpcStepBlock<?php echo $n; ?>">
                        <div class="fw-semibold small text-muted mb-2">Step <?php echo $n; ?> <span class="text-muted fw-normal">(shown on landing — order voters read, not technical API order)</span></div>
                        <div class="mb-2">
                          <label class="form-label" for="step_title_<?php echo $n; ?>">Title (bold)</label>
                          <input type="text" class="form-control" id="step_title_<?php echo $n; ?>"
                                 name="step_title_<?php echo $n; ?>" value="<?php echo h($step['title']); ?>"
                                 data-preview-field="step_title_<?php echo $n; ?>" />
                        </div>
                        <div class="mb-0">
                          <label class="form-label" for="step_body_<?php echo $n; ?>">Description</label>
                          <textarea class="form-control" id="step_body_<?php echo $n; ?>"
                                    name="step_body_<?php echo $n; ?>" rows="2"
                                    data-preview-field="step_body_<?php echo $n; ?>"><?php echo h($step['body']); ?></textarea>
                        </div>
                      </div>
                    <?php endforeach; ?>
                    <div class="mb-0">
                      <label for="footer_note" class="form-label fw-semibold">Footer note (below steps)</label>
                      <textarea class="form-control" id="footer_note" name="footer_note" rows="2"
                                data-preview-field="footer_note"><?php echo h($copy['footer_note']); ?></textarea>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </form>
            </div><!-- /tabContentPane -->

            <div class="tab-pane fade<?php echo $activeTab === 'appearance' ? ' show active' : ''; ?>"
                 id="tabAppearancePane" role="tabpanel" aria-labelledby="tab-appearance-btn" tabindex="0">
              <form method="post" enctype="multipart/form-data" id="voterAppearanceForm" action="voter_portal_copy.php?tab=appearance">
                <input type="hidden" name="save_section" value="appearance">
                <div class="vpc-toolbar-actions">
                  <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save appearance
                  </button>
                  <span class="small text-muted">Applies to all events · Ctrl+S to save</span>
                </div>
                <div>
                    <div class="vs-card">
                      <div class="vs-card-head">
                        <h5>Quick Presets</h5>
                        <span class="head-icon"><i class="fas fa-magic-wand-sparkles"></i></span>
                      </div>
                      <div class="vs-card-body">
                        <p class="text-muted small mb-2">Presets update the live preview instantly. Save when you are happy with the look.</p>
                        <div class="d-flex flex-wrap gap-2 voter-presets" id="voterPresets"></div>
                      </div>
                    </div>

                    <div class="vs-card">
                      <div class="vs-card-head">
                        <h5>Voter Page Colors</h5>
                        <span class="head-icon"><i class="fas fa-palette"></i></span>
                      </div>
                      <div class="vs-card-body">
                        <div class="row g-3 vpc-color-row">
                          <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="voter_bg_color">Background color</label>
                            <input type="color" name="voter_bg_color" id="voter_bg_color"
                                   class="form-control form-control-color w-100 mb-2"
                                   value="<?php echo h($bgColor); ?>">
                            <input type="text" class="form-control vpc-hex-input" id="voter_bg_hex" maxlength="7"
                                   value="<?php echo h($bgColor); ?>" aria-label="Background hex" autocomplete="off">
                            <small class="text-muted d-block mt-1">Outside the white ballot card.</small>
                          </div>
                          <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="voter_text_color">Text color</label>
                            <input type="color" name="voter_text_color" id="voter_text_color"
                                   class="form-control form-control-color w-100 mb-2"
                                   value="<?php echo h($textColor); ?>">
                            <input type="text" class="form-control vpc-hex-input" id="voter_text_hex" maxlength="7"
                                   value="<?php echo h($textColor); ?>" aria-label="Text hex" autocomplete="off">
                            <small class="text-muted d-block mt-1">Content area below the blue hero (not hero text).</small>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="vs-card">
                      <div class="vs-card-head">
                        <h5>Voter Header Logo</h5>
                        <span class="head-icon"><i class="fas fa-image"></i></span>
                      </div>
                      <div class="vs-card-body">
                        <div class="mb-3">
                          <label class="form-label fw-bold">Current Logo</label>
                          <div class="preview-thumb mt-1" style="display:flex;">
                            <img id="voterCurrentLogo" src="<?php echo h($headerLogoAdmin); ?>" alt="Current Header Logo" style="max-height: 80px;">
                          </div>
                        </div>
                        <div class="mb-2">
                          <label for="voter_header_logo" class="form-label">Upload New Logo</label>
                          <input class="form-control" type="file" name="voter_header_logo" id="voter_header_logo" accept="image/*">
                          <small class="text-muted">PNG/JPG/WebP. Recommended height ~80px.</small>
                        </div>
                      </div>
                    </div>

                </div>
              </form>
            </div><!-- /tabAppearancePane -->
          </div><!-- /.tab-content -->
            </div><!-- /.vpc-editor -->

            <aside class="vpc-preview-pane" aria-label="Live voter page preview">
              <div class="vpc-preview-toolbar">
                <span class="small fw-semibold text-muted me-auto align-self-center">Live preview</span>
                <button type="button" class="btn btn-outline-secondary btn-sm active" id="vpcPreviewLanding" title="Landing page">Landing</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="vpcPreviewWelcome" title="Welcome modal">Welcome</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="vpcPreviewBallot" title="Ballot step bar">Ballot</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="vpcPreviewReload" title="Reload preview"><i class="bi bi-arrow-clockwise"></i></button>
              </div>
              <div class="vpc-preview-frame-wrap">
                <iframe id="voterLivePreview"
                        title="Voter portal live preview"
                        src="<?php echo h($voterPreviewUrl); ?>"
                        loading="lazy"></iframe>
              </div>
              <p class="small text-muted px-3 py-2 mb-0">Consent checkboxes and voting flows are not editable here.</p>
            </aside>
          </div><!-- /.vpc-studio -->
        </div>
      </main>
    </div>
  </div>

  <div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
    <div id="globalToast" class="toast align-items-center text-bg-success border-0 mx-auto" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div id="globalToastBody" class="toast-body">Done</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <script>
  document.querySelectorAll('.voter-portal-tabs [data-bs-toggle="tab"]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function (e) {
      const target = e.target.getAttribute('data-bs-target') || '';
      const tab = target === '#tabAppearancePane' ? 'appearance' : 'content';
      const url = new URL(window.location.href);
      url.searchParams.set('tab', tab);
      history.replaceState(null, '', url.pathname + url.search);
    });
  });

  function showToast(message, type = 'success', delay = 3500) {
    const toastEl = document.getElementById('globalToast');
    const bodyEl  = document.getElementById('globalToastBody');
    if (!toastEl || !bodyEl) return;
    const map = { success: 'text-bg-success', danger: 'text-bg-danger', info: 'text-bg-info', warning: 'text-bg-warning' };
    toastEl.className = 'toast align-items-center border-0 mx-auto ' + (map[type] || map.success);
    bodyEl.textContent = message;
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay }).show();
  }
  </script>
  <?php
  if (!empty($_SESSION['flash_toast'])) {
      $msg  = $_SESSION['flash_toast']['message'] ?? 'Done';
      $type = $_SESSION['flash_toast']['type'] ?? 'success';
      unset($_SESSION['flash_toast']);
      echo '<script>document.addEventListener("DOMContentLoaded",function(){ showToast('
          . json_encode($msg) . ', ' . json_encode($type) . '); });</script>';
  }
  ?>
  <script>
  window.TOCCA_VOTER_PORTAL = <?php echo json_encode([
      'eventId'         => $eventId,
      'previewUrl'      => $voterPreviewUrl,
      'headerLogoAdmin' => $headerLogoAdmin,
      'suggestedLead'   => $suggestedLead,
      'eventStats'      => $eventStats,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  </script>
  <script src="js/voter_portal_settings.js"></script>
  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>

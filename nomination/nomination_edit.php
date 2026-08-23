<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/nomination_field_helpers.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/establishment_type_event_helpers.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/award_entry_helpers.php';

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$conn = null;
foreach ([
    __DIR__ . '/../tocca_admin/db_connection.php',
    dirname(__DIR__) . '/tocca_admin/db_connection.php',
] as $p) {
    if (is_file($p)) {
        require_once $p;
        break;
    }
}
if (!$conn instanceof mysqli) {
    http_response_code(500);
    echo 'Database unavailable.';
    exit;
}
et_ensure_m2m_schema($conn);

$reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));
$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$error = '';
$nom = null;
$answersByField = [];
$selectedTypeIds = [];
$selectedAwardIds = [];
$fields = [];
$types = [];
$awards = [];
$awardEntriesInit = [];
$canEdit = false;

$logoIncludePaths = [
    __DIR__ . '/../tocca_admin/get_logo.php',
    dirname(__DIR__) . '/tocca_admin/get_logo.php',
];
foreach ($logoIncludePaths as $path) {
    if (is_file($path)) {
        require_once $path;
        break;
    }
}
$headerImage = $nominationBannerPath ?? 'img/default-banner.png';
$headerImageWebp = $nominationBannerWebpPath ?? null;

if ($reference !== '') {
    $nom = nf_fetch_nomination_by_reference($conn, $reference);
    if (!$nom) {
        $error = 'Reference number not found.';
    } else {
        $nominationId = (int) $nom['nomination_id'];
        $event_id = (int) $nom['event_id'];
        $status = strtolower((string) ($nom['status'] ?? ''));
        $canEdit = nf_nomination_is_editable($status);
        if (!$canEdit) {
            $error = 'This registration can no longer be edited.';
        } else {
            $fields = nf_load_fields($conn, $event_id, ['active_only' => true]);
            $ans = $conn->prepare('SELECT field_id, answer FROM tbl_nomination_answers WHERE nomination_id = ?');
            $ans->bind_param('i', $nominationId);
            $ans->execute();
            $res = $ans->get_result();
            while ($row = $res->fetch_assoc()) {
                $answersByField[(int) $row['field_id']] = (string) ($row['answer'] ?? '');
            }
            $ans->close();

            $selectedTypeIds = et_get_nomination_type_ids($conn, $nominationId);
            $types = et_fetch_types_for_event($conn, $event_id);

            $aq = $conn->prepare('SELECT question_id FROM tbl_nomination_questions WHERE nomination_id = ?');
            $aq->bind_param('i', $nominationId);
            $aq->execute();
            $ar = $aq->get_result();
            while ($row = $ar->fetch_assoc()) {
                $selectedAwardIds[] = (int) $row['question_id'];
            }
            $aq->close();

            if ($selectedTypeIds !== []) {
                $awards = et_fetch_awards_for_types($conn, $selectedTypeIds, $event_id);
            }

            award_entry_ensure_schema($conn);
            foreach (award_entry_get_for_nomination($conn, $nominationId) as $er) {
                $qid = (int) $er['question_id'];
                if (!isset($awardEntriesInit[$qid])) {
                    $awardEntriesInit[$qid] = [];
                }
                $awardEntriesInit[$qid][] = (string) $er['entry_name'];
            }
        }
    }
} else {
    $error = 'Missing reference number. Open this page from Track Registration.';
}

require_once __DIR__ . '/../tocca_admin/qr_url.php';
$trackUrl = qr_tracking_url_with_ref($conn ?? null, $reference);
$statusLabel = $nom ? ucwords(str_replace('_', ' ', (string) $nom['status'])) : '';
$bodyBg = $bodyBg ?? '#eef4fc';
if (!function_exists('tocca_emit_nomination_js_base') && is_file(__DIR__ . '/../tocca_admin/qr_url.php')) {
    require_once __DIR__ . '/../tocca_admin/qr_url.php';
}
$formCssV = (string) (@filemtime(__DIR__ . '/nomination_form.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#1e40af" />
  <title>Update Registration | Tatak Ormoc</title>
  <?php if (function_exists('tocca_emit_asset_base_tag')) { tocca_emit_asset_base_tag(); } ?>
  <?php if (function_exists('tocca_emit_nomination_js_base')) { tocca_emit_nomination_js_base(); } ?>
  <link rel="icon" type="image/png" href="<?php echo h($faviconPath ?? 'favicon.png'); ?>">
  <?php
    $nomPerfIconCss = 'bi';
    $nomPerfPreload = ($headerImageWebp ?: $headerImage);
    $nomPerfPreloadType = $headerImageWebp ? 'image/webp' : '';
    require __DIR__ . '/partials/nom_perf_head.php';
  ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="nomination_form.css?v=<?php echo h($formCssV); ?>">
  <style>
    :root { --voter-bg: <?php echo h($bodyBg); ?>; }

    html:has(body.nomination-edit-page),
    body.nomination-edit-page {
      overflow-x: hidden;
      max-width: 100%;
    }

    .nomination-edit-page .nomination-form-main {
      width: 100%;
      max-width: 100%;
      min-width: 0;
      box-sizing: border-box;
    }

    .nomination-edit-page .nom-edit-header {
      text-align: left;
      margin-bottom: 0.85rem;
    }

    .nomination-edit-page .nom-edit-header .hero-title {
      font-size: clamp(1.35rem, 5vw, 1.85rem);
      font-weight: 800;
      color: var(--tocca-navy);
      letter-spacing: -0.02em;
      margin-bottom: 0.35rem;
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    .nomination-edit-page .nom-edit-header .hero-sub {
      color: var(--tocca-text-muted);
      font-size: clamp(0.9rem, 2.6vw, 1rem);
      line-height: 1.5;
      margin: 0;
      overflow-wrap: anywhere;
    }

    .nomination-edit-page .section-card {
      max-width: 100%;
      min-width: 0;
    }

    .nomination-edit-page .section-card .card-body {
      min-width: 0;
      overflow-x: clip;
    }

    .nomination-edit-page .edit-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem 1.25rem;
      align-items: flex-start;
      margin-bottom: 1rem;
      padding-bottom: 0.85rem;
      border-bottom: 1px dashed var(--tocca-border);
    }

    .nomination-edit-page .edit-meta > div {
      min-width: 0;
      flex: 1 1 8rem;
    }

    .nomination-edit-page .edit-meta strong,
    .nomination-edit-page .form-label,
    .nomination-edit-page .form-text,
    .nomination-edit-page .form-check-label,
    .nomination-edit-page h2 {
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    .nomination-edit-page .form-control,
    .nomination-edit-page .form-select {
      max-width: 100%;
      min-width: 0;
    }

    .nomination-edit-page .edit-fields-row {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1rem;
      margin: 0;
      max-width: 100%;
    }

    @media (min-width: 768px) {
      .nomination-edit-page .edit-fields-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .nomination-edit-page .edit-fields-row > [data-field-width="full"] {
        grid-column: 1 / -1;
      }
    }

    .nomination-edit-page .edit-fields-row > * {
      min-width: 0;
      max-width: 100%;
    }

    .nomination-edit-page .edit-current-file {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      margin: 0.35rem 0 0.55rem;
      min-width: 0;
    }

    .nomination-edit-page .edit-current-file img {
      max-height: 64px;
      max-width: min(120px, 40vw);
      object-fit: contain;
      border-radius: 0.4rem;
      border: 1px solid #dbe4f3;
      background: #fff;
      flex-shrink: 0;
    }

    .nomination-edit-page .edit-awards-grid {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      min-width: 0;
    }

    .nomination-edit-page .edit-award-category {
      display: grid;
      grid-template-columns: 1fr;
      gap: 0.55rem;
      min-width: 0;
    }

    @media (min-width: 576px) {
      .nomination-edit-page .edit-award-category {
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
      }
    }

    .nomination-edit-page .edit-award-category-title {
      grid-column: 1 / -1;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--tocca-primary, #1e40af);
      margin: 0;
      padding-bottom: 0.35rem;
      border-bottom: 2px solid rgba(30, 64, 175, 0.12);
    }

    .nomination-edit-page .edit-awards-grid .form-check {
      border: 1px solid var(--tocca-border, #d7e0ef);
      border-radius: 0.6rem;
      padding: 0.65rem 0.75rem 0.65rem 2rem;
      margin: 0;
      background: #fff;
      min-width: 0;
    }

    .nomination-edit-page .award-entries-panel .award-entry-block {
      background: #fff;
    }

    .nomination-edit-page .award-entries-panel .award-entry-block.is-invalid-entry {
      border-color: #dc3545 !important;
      box-shadow: 0 0 0 0.15rem rgba(220, 53, 69, 0.15);
    }

    .nomination-edit-page .nom-est-type-list {
      grid-template-columns: 1fr;
      min-width: 0;
    }

    @media (min-width: 420px) {
      .nomination-edit-page .nom-est-type-list {
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 11rem), 1fr));
      }
    }

    .nomination-edit-page .nom-step-actions {
      display: flex;
      flex-direction: column-reverse;
      gap: 0.65rem;
      margin-top: 1.25rem;
    }

    .nomination-edit-page .nom-step-actions .btn {
      width: 100%;
      min-height: 2.75rem;
      font-weight: 700;
    }

    @media (min-width: 576px) {
      .nomination-edit-page .nom-step-actions {
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
      }
      .nomination-edit-page .nom-step-actions .btn {
        width: auto;
      }
    }

    @media (max-width: 767.98px) {
      .nomination-edit-page .section-card .card-body {
        padding: 1rem 0.9rem;
      }
      .nomination-edit-page .nom-edit-header {
        margin-bottom: 0.65rem;
      }
    }
  </style>
</head>
<body class="nomination-form-page nomination-edit-page">
  <header class="nom-page-header">
    <?php require __DIR__ . '/partials/nom_banner.php'; ?>
  </header>

  <main class="container my-2 my-md-4 px-2 px-sm-3 nomination-form-main">
    <header class="nom-edit-header">
      <h1 class="hero-title">Update Registration</h1>
      <p class="hero-sub">Revise your details, then save. You will return to tracking afterward.</p>
    </header>

    <?php if ($error !== ''): ?>
      <div class="alert alert-warning"><?php echo $error; ?></div>
      <a class="btn btn-outline-primary" href="<?php echo h($trackUrl); ?>">
        <i class="bi bi-arrow-left me-1"></i>Back to tracking
      </a>
    <?php else: ?>
      <div class="section-card mb-3">
        <div class="card-body">
          <div class="edit-meta">
            <div>
              <div class="small text-muted">Reference</div>
              <strong><?php echo h($reference); ?></strong>
            </div>
            <div>
              <div class="small text-muted">Current status</div>
              <span class="badge text-bg-primary"><?php echo h($statusLabel); ?></span>
            </div>
          </div>

          <div id="editAlert" class="alert alert-danger d-none" role="alert"></div>

          <form id="editRegistrationForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="nom_edit_ref" id="nomEditRef" value="<?php echo h($reference); ?>">
            <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
            <input type="hidden" name="selected_awards_json" id="selectedAwardsInput" value="[]">

            <div class="mb-3">
              <label class="form-label" for="verify_email">Confirm your registration email <span class="text-danger">*</span></label>
              <input type="email" class="form-control" id="verify_email" name="verify_email" required autocomplete="email" placeholder="Same email used when you registered">
              <div class="form-text">For security, updates require the email on your registration.</div>
              <div class="invalid-feedback js-field-error" role="alert">Please confirm your registration email.</div>
            </div>

            <hr class="my-4">
            <h2 class="h5 mb-3">Establishment type &amp; awards</h2>
            <div class="mb-3" id="establishmentTypeField" data-built-in="establishment_type">
              <span class="form-label d-block">Nature of Business <span class="text-danger">*</span></span>
              <div class="nom-est-type-list" id="establishmentTypeCheckboxes">
                <?php foreach ($types as $t):
                  $tid = (int) ($t['type_id'] ?? 0);
                  $checked = in_array($tid, $selectedTypeIds, true) ? ' checked' : '';
                ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="establishment_type_ids[]" value="<?php echo $tid; ?>" id="etype_<?php echo $tid; ?>"<?php echo $checked; ?>>
                    <label class="form-check-label" for="etype_<?php echo $tid; ?>"><?php echo h((string) ($t['type_name'] ?? '')); ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mb-4">
              <span class="form-label d-block">Awards <span class="text-danger">*</span></span>
              <div id="editAwards" class="edit-awards-grid">
                <?php if ($awards === []): ?>
                  <div class="text-muted small">Select a nature of business to load awards.</div>
                <?php else: ?>
                  <?php
                    $typeNameById = [];
                    foreach ($types as $t) {
                      $tidName = (int) ($t['type_id'] ?? 0);
                      if ($tidName > 0) {
                        $typeNameById[$tidName] = (string) ($t['type_name'] ?? '');
                      }
                    }
                    $editAwardGroups = [];
                    foreach ($selectedTypeIds as $tid) {
                      $tid = (int) $tid;
                      $groupAwards = [];
                      foreach ($awards as $a) {
                        $matchIds = $a['type_ids'] ?? [];
                        if ($matchIds === [] && isset($a['type_id'])) {
                          $matchIds = [(int) $a['type_id']];
                        }
                        $matchIds = array_map('intval', $matchIds);
                        if (in_array($tid, $matchIds, true)) {
                          $groupAwards[] = $a;
                        }
                      }
                      if ($groupAwards === []) {
                        continue;
                      }
                      $editAwardGroups[] = [
                        'id'     => $tid,
                        'name'   => ($typeNameById[$tid] ?? '') !== '' ? $typeNameById[$tid] : ('Type ' . $tid),
                        'awards' => $groupAwards,
                      ];
                    }
                  ?>
                  <?php foreach ($editAwardGroups as $group): ?>
                    <div class="edit-award-category">
                      <div class="edit-award-category-title"><?php echo h((string) $group['name']); ?></div>
                      <?php foreach ($group['awards'] as $a):
                        $qid = (int) ($a['question_id'] ?? 0);
                        $gid = (int) $group['id'];
                        $checked = in_array($qid, $selectedAwardIds, true) ? ' checked' : '';
                      ?>
                        <div class="form-check">
                          <input class="form-check-input award-cb" type="checkbox" value="<?php echo $qid; ?>" data-award-id="<?php echo $qid; ?>" id="award_<?php echo $qid; ?>_t<?php echo $gid; ?>"<?php echo $checked; ?>>
                          <label class="form-check-label" for="award_<?php echo $qid; ?>_t<?php echo $gid; ?>"><?php echo h((string) ($a['question_name'] ?? '')); ?></label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div id="editAwardEntriesPanel" class="award-entries-panel mt-3 d-none" aria-live="polite"></div>
              <input type="hidden" id="awardEntriesInput" name="award_entries_json" value="{}">
            </div>

            <hr class="my-4">
            <h2 class="h5 mb-3">Registration details</h2>
            <div class="edit-fields-row">
              <?php foreach ($fields as $f):
                $fid = (int) ($f['id'] ?? 0);
                $name = (string) ($f['name'] ?? '');
                if (preg_match('/^reference(_no)?$/i', $name)) {
                    continue;
                }
                $label = (string) ($f['label'] ?? '');
                $type = (string) ($f['type'] ?? 'text');
                $req = !empty($f['is_required']);
                $val = $answersByField[$fid] ?? '';
                $widthAttr = (($f['field_width'] ?? 'half') === 'full') ? 'full' : 'half';
                $inputId = 'edit_field_' . $fid;
                $role = (string) ($f['profile_role'] ?? 'custom');
              ?>
                <div data-field-id="<?php echo $fid; ?>" data-field-type="<?php echo h($type); ?>" data-field-width="<?php echo h($widthAttr); ?>" data-profile-role="<?php echo h($role); ?>">
                  <label class="form-label" for="<?php echo h($inputId); ?>">
                    <?php echo h($label); ?><?php echo $req ? ' <span class="text-danger">*</span>' : ''; ?>
                  </label>
                  <?php if ($type === 'textarea'): ?>
                    <textarea class="form-control" id="<?php echo h($inputId); ?>" name="<?php echo h($name); ?>" rows="3"<?php echo $req ? ' required' : ''; ?>><?php echo h($val); ?></textarea>
                  <?php elseif ($type === 'select'):
                    $opts = [];
                    if (!empty($f['options_arr']) && is_array($f['options_arr'])) {
                      $opts = $f['options_arr'];
                    } elseif (!empty($f['options'])) {
                      $decoded = json_decode((string) $f['options'], true);
                      if (is_array($decoded)) {
                        $opts = $decoded;
                      } else {
                        $parts = preg_split('/[\r\n,|]+/', (string) $f['options']);
                        $opts = is_array($parts) ? $parts : [];
                      }
                    }
                  ?>
                    <select class="form-select" id="<?php echo h($inputId); ?>" name="<?php echo h($name); ?>"<?php echo $req ? ' required' : ''; ?>>
                      <option value="">-- Select --</option>
                      <?php foreach ($opts as $opt):
                        $opt = trim((string) $opt);
                        if ($opt === '') continue;
                      ?>
                        <option value="<?php echo h($opt); ?>"<?php echo ($val === $opt) ? ' selected' : ''; ?>><?php echo h($opt); ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($type === 'file'):
                    $accept = nf_file_accept($f);
                    $isImg = (bool) preg_match('/\.(png|jpe?g|webp|gif)$/i', $val);
                  ?>
                    <?php if ($val !== ''): ?>
                      <div class="edit-current-file">
                        <?php if ($isImg): ?><img src="<?php echo h($val); ?>" alt="<?php echo h($label); ?>"><?php endif; ?>
                        <div class="small min-w-0">
                          <div class="fw-semibold">Current file on record</div>
                          <a href="<?php echo h($val); ?>" target="_blank" rel="noopener">View</a>
                        </div>
                      </div>
                      <input type="hidden" name="<?php echo h($name); ?>_keep" value="<?php echo h($val); ?>">
                    <?php endif; ?>
                    <input class="form-control" type="file" id="<?php echo h($inputId); ?>" name="<?php echo h($name); ?>" accept="<?php echo h($accept); ?>"<?php echo ($req && $val === '') ? ' required' : ''; ?>>
                    <div class="form-text">Leave empty to keep the current file. Accepted: <?php echo h($accept); ?></div>
                  <?php else:
                    $map = ['email'=>'email','tel'=>'tel','url'=>'url','number'=>'number','date'=>'date'];
                    $inType = $map[$type] ?? 'text';
                  ?>
                    <input class="form-control" type="<?php echo h($inType); ?>" id="<?php echo h($inputId); ?>" name="<?php echo h($name); ?>" value="<?php echo h($val); ?>"<?php echo $req ? ' required' : ''; ?>>
                  <?php endif; ?>
                  <div class="invalid-feedback js-field-error" role="alert"></div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="nom-step-actions">
              <a class="btn btn-outline-secondary" href="<?php echo h($trackUrl); ?>">
                <i class="bi bi-arrow-left me-1"></i> Cancel
              </a>
              <button type="submit" class="btn btn-success" id="saveEditBtn">
                <i class="bi bi-check2-circle me-1"></i> Save updates
              </button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </main>

  <?php require __DIR__ . '/partials/site_footer.php'; ?>

  <script>
  (() => {
    const form = document.getElementById('editRegistrationForm');
    if (!form) return;
    const awardsWrap = document.getElementById('editAwards');
    const awardsInput = document.getElementById('selectedAwardsInput');
    const entriesPanel = document.getElementById('editAwardEntriesPanel');
    const entriesInput = document.getElementById('awardEntriesInput');
    const alertEl = document.getElementById('editAlert');
    const eventId = <?php echo (int) $event_id; ?>;
    const selectedInit = <?php echo json_encode(array_values($selectedAwardIds)); ?>;
    let entriesState = <?php echo json_encode($awardEntriesInit, JSON_UNESCAPED_UNICODE); ?> || {};
    let awardsMeta = {};

    function selectedTypeIds() {
      return Array.from(form.querySelectorAll('input[name="establishment_type_ids[]"]:checked')).map(cb => cb.value);
    }
    function syncAwardsHidden() {
      const ids = Array.from(new Set(
        Array.from(form.querySelectorAll('.award-cb:checked')).map(cb => Number(cb.dataset.awardId || cb.value)).filter(Boolean)
      ));
      awardsInput.value = JSON.stringify(ids);
      return ids;
    }
    function collectEntriesFromDom() {
      const out = {};
      if (!entriesPanel) return out;
      entriesPanel.querySelectorAll('[data-award-entry-qid]').forEach((block) => {
        const qid = String(block.getAttribute('data-award-entry-qid') || '');
        if (!qid) return;
        const multi = block.getAttribute('data-entry-multiple') === '1';
        const values = [];
        block.querySelectorAll('input[data-entry-name]').forEach((inp) => {
          const v = String(inp.value || '').trim().replace(/\s+/g, ' ');
          if (v) values.push(v);
        });
        if (!values.length) return;
        out[qid] = multi
          ? Array.from(new Set(values.map((v) => v.toLowerCase()))).map((key) => values.find((v) => v.toLowerCase() === key) || key)
          : [values[0]];
      });
      return out;
    }
    function syncEntriesHidden() {
      entriesState = collectEntriesFromDom();
      if (entriesInput) entriesInput.value = JSON.stringify(entriesState);
      return entriesState;
    }
    function validateEntries() {
      const errors = [];
      if (!entriesPanel || entriesPanel.classList.contains('d-none')) return errors;
      entriesPanel.querySelectorAll('[data-award-entry-qid]').forEach((block) => {
        const label = block.getAttribute('data-entry-label') || 'name';
        const title = block.getAttribute('data-award-title') || 'this award';
        const values = [];
        block.querySelectorAll('input[data-entry-name]').forEach((inp) => {
          const v = String(inp.value || '').trim();
          if (v) values.push(v);
        });
        if (!values.length) {
          errors.push('Please enter at least one ' + label.toLowerCase() + ' for "' + title + '".');
          block.classList.add('is-invalid-entry');
        } else {
          block.classList.remove('is-invalid-entry');
        }
      });
      return errors;
    }
    function renderEntriesPanel() {
      if (!entriesPanel) return;
      syncEntriesHidden();
      const selected = Array.from(new Set(
        Array.from(form.querySelectorAll('.award-cb:checked')).map(cb => String(cb.dataset.awardId || cb.value || ''))
      )).filter(Boolean);
      const blocks = [];
      selected.forEach((qid) => {
        const award = awardsMeta[qid];
        if (!award || !award.entry_kind) return;
        const kind = String(award.entry_kind);
        const label = String(award.entry_label || 'Name');
        const multi = !!award.entry_multiple;
        const max = Math.max(1, Number(award.entry_max) || (multi ? 3 : 1));
        const title = String(award.question_name || '');
        const existing = Array.isArray(entriesState[qid]) ? entriesState[qid].map(String) : [];
        const seed = (existing.length ? existing : ['']).slice(0, max);
        const rows = seed.map((val) => `<div class="input-group mb-2 award-entry-row">
          <input type="text" class="form-control" data-entry-name maxlength="180"
            placeholder="${multi ? 'e.g. Burger' : ('Enter ' + label.toLowerCase())}"
            value="${String(val).replace(/"/g, '&quot;')}" aria-label="${label} for ${title}">
          ${multi ? `<button type="button" class="btn btn-outline-secondary award-entry-remove" title="Remove">&times;</button>` : ''}
        </div>`).join('');
        const atMax = seed.length >= max;
        blocks.push(`<div class="award-entry-block border rounded p-3 mb-2" data-award-entry-qid="${qid}"
          data-entry-kind="${kind}" data-entry-multiple="${multi ? '1' : '0'}" data-entry-max="${max}"
          data-entry-label="${label.replace(/"/g, '&quot;')}" data-award-title="${title.replace(/"/g, '&quot;')}">
          <div class="fw-semibold mb-1">${title.replace(/</g, '&lt;')}</div>
          <div class="small text-muted mb-2">${multi
            ? ('Add up to ' + max + ' product names for this award.')
            : ('Enter the ' + label.toLowerCase() + ' for this award.')}</div>
          <div class="award-entry-rows">${rows}</div>
          ${multi ? `<button type="button" class="btn btn-sm btn-outline-primary award-entry-add${atMax ? ' d-none' : ''}">
            <i class="bi bi-plus-lg me-1"></i>Add another product
          </button>` : ''}
        </div>`);
      });
      if (!blocks.length) {
        entriesPanel.classList.add('d-none');
        entriesPanel.innerHTML = '';
        if (entriesInput) entriesInput.value = '{}';
        return;
      }
      entriesPanel.classList.remove('d-none');
      entriesPanel.innerHTML = `<div class="fw-semibold mb-2">Details for selected award titles</div>${blocks.join('')}`;
      syncEntriesHidden();
    }
    function refreshEntryAddButtons(block) {
      if (!block) return;
      const max = Math.max(1, Number(block.getAttribute('data-entry-max')) || 1);
      const count = block.querySelectorAll('.award-entry-row').length;
      const addBtn = block.querySelector('.award-entry-add');
      if (addBtn) addBtn.classList.toggle('d-none', count >= max);
    }
    function renderAwards(list, preferSelected) {
      const prefer = new Set((preferSelected || []).map(String));
      const byId = new Map();
      awardsMeta = {};
      (list || []).forEach((a) => {
        const id = String(a.question_id);
        if (!id || byId.has(id)) return;
        byId.set(id, a);
        awardsMeta[id] = a;
      });
      const awards = Array.from(byId.values()).sort((a, b) =>
        String(a.question_name || '').localeCompare(String(b.question_name || ''), undefined, { sensitivity: 'base' })
      );
      if (!awards.length) {
        awardsWrap.innerHTML = '<div class="text-muted small">No award titles available for the selected type(s).</div>';
        syncAwardsHidden();
        renderEntriesPanel();
        return;
      }
      awardsWrap.innerHTML = awards.map((a) => {
        const id = String(a.question_id);
        const checked = prefer.has(id) ? ' checked' : '';
        const title = String(a.question_name || '').replace(/</g, '&lt;');
        const uid = 'award_' + id;
        return `<div class="form-check">
          <input class="form-check-input award-cb" type="checkbox" value="${id}" data-award-id="${id}" id="${uid}"${checked}>
          <label class="form-check-label" for="${uid}">${title}</label>
        </div>`;
      }).join('');
      syncAwardsHidden();
      renderEntriesPanel();
    }
    function loadAwards() {
      const ids = selectedTypeIds();
      if (!ids.length) {
        awardsWrap.innerHTML = '<div class="text-muted small">Select at least one nature of business.</div>';
        awardsMeta = {};
        syncAwardsHidden();
        renderEntriesPanel();
        return;
      }
      const qs = new URLSearchParams();
      qs.set('event_id', String(eventId));
      ids.forEach(id => qs.append('establishment_type_ids[]', id));
      fetch('load_questions.php?' + qs.toString(), { cache: 'no-store' })
        .then(r => r.json())
        .then(payload => {
          const list = (payload && payload.questions) ? payload.questions : [];
          const keep = Array.from(form.querySelectorAll('.award-cb:checked')).map(cb => cb.value);
          renderAwards(list, keep.length ? keep : selectedInit);
        })
        .catch(() => {
          awardsWrap.innerHTML = '<div class="text-danger small">Failed to load awards.</div>';
        });
    }

    form.querySelectorAll('input[name="establishment_type_ids[]"]').forEach(cb => {
      cb.addEventListener('change', loadAwards);
    });
    awardsWrap?.addEventListener('change', (e) => {
      const cb = e.target;
      if (cb && cb.classList.contains('award-cb')) {
        syncAwardsHidden();
        renderEntriesPanel();
      }
    });
    entriesPanel?.addEventListener('click', (e) => {
      const addBtn = e.target.closest?.('.award-entry-add');
      if (addBtn) {
        const block = addBtn.closest('[data-award-entry-qid]');
        const rows = block?.querySelector('.award-entry-rows');
        const max = Math.max(1, Number(block?.getAttribute('data-entry-max')) || 1);
        if (rows && rows.querySelectorAll('.award-entry-row').length < max) {
          const wrap = document.createElement('div');
          wrap.className = 'input-group mb-2 award-entry-row';
          wrap.innerHTML = `<input type="text" class="form-control" data-entry-name maxlength="180" placeholder="e.g. Cake">
            <button type="button" class="btn btn-outline-secondary award-entry-remove" title="Remove">&times;</button>`;
          rows.appendChild(wrap);
          wrap.querySelector('input')?.focus();
        }
        refreshEntryAddButtons(block);
        syncEntriesHidden();
        return;
      }
      const rm = e.target.closest?.('.award-entry-remove');
      if (rm) {
        const row = rm.closest('.award-entry-row');
        const block = rm.closest('[data-award-entry-qid]');
        const rows = block?.querySelectorAll('.award-entry-row') || [];
        if (rows.length > 1) row?.remove();
        else {
          const inp = row?.querySelector('input[data-entry-name]');
          if (inp) inp.value = '';
        }
        refreshEntryAddButtons(block);
        syncEntriesHidden();
      }
    });
    entriesPanel?.addEventListener('input', () => syncEntriesHidden());
    syncAwardsHidden();
    loadAwards();

    const pageRef = <?php echo json_encode($reference, JSON_UNESCAPED_SLASHES); ?>;
    const emailInput = document.getElementById('verify_email');
    const updateEndpoint = <?php echo json_encode(function_exists('tocca_nomination_url') ? tocca_nomination_url('update_nomination.php') : 'update_nomination.php', JSON_UNESCAPED_SLASHES); ?>;

    function sameOriginUrl(file) {
      try {
        const abs = new URL(file, window.location.href);
        if (abs.origin === window.location.origin) {
          return abs.href;
        }
      } catch (_) {}
      return new URL('update_nomination.php', window.location.href).href;
    }

    emailInput?.addEventListener('input', () => {
      emailInput.classList.remove('is-invalid');
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      syncAwardsHidden();
      syncEntriesHidden();
      alertEl.classList.add('d-none');
      alertEl.textContent = '';
      emailInput?.classList.remove('is-invalid');

      const email = String(emailInput?.value || '').trim();
      if (!email) {
        emailInput?.classList.add('is-invalid');
        emailInput?.focus();
        return;
      }
      const entryErrors = validateEntries();
      if (entryErrors.length) {
        alertEl.textContent = entryErrors[0];
        alertEl.classList.remove('d-none');
        entriesPanel?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
      }

      const btn = document.getElementById('saveEditBtn');
      if (btn) btn.disabled = true;
      try {
        const fd = new FormData(form);
        fd.set('nom_edit_ref', pageRef);
        fd.set('verify_email', email);
        fd.set('award_entries_json', entriesInput ? entriesInput.value : '{}');
        const res = await fetch(sameOriginUrl(updateEndpoint), {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });
        const raw = await res.text();
        let payload = null;
        try {
          payload = raw ? JSON.parse(raw) : null;
        } catch (_) {
          throw new Error('Unable to save your updates. Please try again.');
        }
        if (!payload || payload.status !== 'success') {
          const errs = Array.isArray(payload?.errors) ? payload.errors.join(' ') : '';
          throw new Error((payload && payload.message ? payload.message + (errs ? ': ' + errs : '') : 'Update failed.'));
        }
        window.location.href = payload.redirect || <?php echo json_encode($trackUrl); ?>;
      } catch (err) {
        const rawMsg = String(err && err.message ? err.message : '');
        alertEl.textContent = /failed to fetch|networkerror|load failed/i.test(rawMsg)
          ? 'Unable to save your updates. Please try again.'
          : (rawMsg || 'Update failed.');
        alertEl.classList.remove('d-none');
        alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  })();
  </script>
</body>
</html>

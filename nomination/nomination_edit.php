<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/nomination_field_helpers.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/establishment_type_event_helpers.php';

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

if ($reference !== '') {
    $st = $conn->prepare('SELECT nomination_id, event_id, reference_no, status FROM tbl_nominations WHERE reference_no = ? LIMIT 1');
    $st->bind_param('s', $reference);
    $st->execute();
    $nom = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    if (!$nom) {
        $error = 'Reference number not found.';
    } else {
        $nominationId = (int) $nom['nomination_id'];
        $event_id = (int) $nom['event_id'];
        $status = strtolower((string) ($nom['status'] ?? ''));
        $canEdit = in_array($status, ['pending', 'submitted', 'needs_info'], true);
        if (!$canEdit) {
            $error = 'This registration can no longer be edited (status: ' . h((string) $nom['status']) . ').';
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
        }
    }
} else {
    $error = 'Missing reference number. Open this page from Track Registration.';
}

$trackUrl = 'nomination_tracking.php' . ($reference !== '' ? ('?ref=' . rawurlencode($reference)) : '');
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
      display: grid;
      grid-template-columns: 1fr;
      gap: 0.55rem;
      min-width: 0;
    }

    @media (min-width: 576px) {
      .nomination-edit-page .edit-awards-grid {
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
      }
    }

    .nomination-edit-page .edit-awards-grid .form-check {
      border: 1px solid var(--tocca-border, #d7e0ef);
      border-radius: 0.6rem;
      padding: 0.65rem 0.75rem 0.65rem 2rem;
      margin: 0;
      background: #fff;
      min-width: 0;
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
    <div class="hero-banner">
      <div class="nom-banner-wrap">
        <img
          src="<?php echo h($headerImage); ?>"
          alt="Tatak Ormoc registration banner"
          class="nom-banner-img"
          width="1100"
          height="320"
          decoding="async"
          fetchpriority="high"
        />
      </div>
    </div>
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
            <input type="hidden" name="reference_no" value="<?php echo h($reference); ?>">
            <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
            <input type="hidden" name="selected_awards_json" id="selectedAwardsInput" value="[]">

            <div class="mb-3">
              <label class="form-label" for="verify_email">Confirm your registration email <span class="text-danger">*</span></label>
              <input type="email" class="form-control" id="verify_email" name="verify_email" required autocomplete="email" placeholder="Same email used when you registered">
              <div class="form-text">For security, updates require the email on your registration.</div>
            </div>

            <hr class="my-4">
            <h2 class="h5 mb-3">Establishment type &amp; awards</h2>
            <div class="mb-3" id="establishmentTypeField" data-built-in="establishment_type">
              <span class="form-label d-block">Establishment Type <span class="text-danger">*</span></span>
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
                  <div class="text-muted small">Select an establishment type to load awards.</div>
                <?php else: ?>
                  <?php foreach ($awards as $a):
                    $qid = (int) ($a['question_id'] ?? 0);
                    $checked = in_array($qid, $selectedAwardIds, true) ? ' checked' : '';
                  ?>
                    <div class="form-check">
                      <input class="form-check-input award-cb" type="checkbox" value="<?php echo $qid; ?>" id="award_<?php echo $qid; ?>"<?php echo $checked; ?>>
                      <label class="form-check-label" for="award_<?php echo $qid; ?>"><?php echo h((string) ($a['question_name'] ?? '')); ?></label>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <hr class="my-4">
            <h2 class="h5 mb-3">Registration details</h2>
            <div class="edit-fields-row">
              <?php foreach ($fields as $f):
                $fid = (int) ($f['id'] ?? 0);
                $name = (string) ($f['name'] ?? '');
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

  <script>
  (() => {
    const form = document.getElementById('editRegistrationForm');
    if (!form) return;
    const awardsWrap = document.getElementById('editAwards');
    const awardsInput = document.getElementById('selectedAwardsInput');
    const alertEl = document.getElementById('editAlert');
    const eventId = <?php echo (int) $event_id; ?>;
    const selectedInit = <?php echo json_encode(array_values($selectedAwardIds)); ?>;

    function selectedTypeIds() {
      return Array.from(form.querySelectorAll('input[name="establishment_type_ids[]"]:checked')).map(cb => cb.value);
    }
    function syncAwardsHidden() {
      const ids = Array.from(form.querySelectorAll('.award-cb:checked')).map(cb => Number(cb.value)).filter(Boolean);
      awardsInput.value = JSON.stringify(ids);
    }
    function renderAwards(list, preferSelected) {
      const prefer = new Set((preferSelected || []).map(String));
      if (!list.length) {
        awardsWrap.innerHTML = '<div class="text-muted small">No awards available for the selected type(s).</div>';
        syncAwardsHidden();
        return;
      }
      awardsWrap.innerHTML = list.map(a => {
        const id = String(a.question_id);
        const checked = prefer.has(id) ? ' checked' : '';
        return `<div class="form-check">
          <input class="form-check-input award-cb" type="checkbox" value="${id}" id="award_${id}"${checked}>
          <label class="form-check-label" for="award_${id}">${String(a.question_name || '').replace(/</g,'&lt;')}</label>
        </div>`;
      }).join('');
      syncAwardsHidden();
    }
    function loadAwards() {
      const ids = selectedTypeIds();
      if (!ids.length) {
        awardsWrap.innerHTML = '<div class="text-muted small">Select at least one establishment type.</div>';
        syncAwardsHidden();
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
      if (e.target && e.target.classList.contains('award-cb')) syncAwardsHidden();
    });
    syncAwardsHidden();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      syncAwardsHidden();
      alertEl.classList.add('d-none');
      alertEl.textContent = '';
      const btn = document.getElementById('saveEditBtn');
      if (btn) btn.disabled = true;
      try {
        const fd = new FormData(form);
        const res = await fetch('update_nomination.php', { method: 'POST', body: fd });
        const payload = await res.json();
        if (!payload || payload.status !== 'success') {
          const errs = Array.isArray(payload?.errors) ? payload.errors.join(' ') : '';
          throw new Error((payload && payload.message ? payload.message + (errs ? ': ' + errs : '') : 'Update failed.'));
        }
        window.location.href = payload.redirect || <?php echo json_encode($trackUrl); ?>;
      } catch (err) {
        alertEl.textContent = err.message || 'Update failed.';
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

<?php
require_once __DIR__ . '/require_admin_api.php';
require_once 'audit_log.php';
require_once __DIR__ . '/choice_token.php';
require_once __DIR__ . '/qr_url.php';
require_once __DIR__ . '/includes/establishment_type_event_helpers.php';
require_once __DIR__ . '/includes/award_removal_reasons.php';
require_once __DIR__ . '/includes/ballot_status.php';
$data = json_decode(file_get_contents("php://input"), true);
et_ensure_m2m_schema($conn);
function table_exists(mysqli $conn, string $table): bool {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
  if (!$stmt) return $cache[$table] = false;
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $ok = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  return $cache[$table] = $ok;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
  static $cache = [];
  $k = "$table.$column";
  if (isset($cache[$k])) return $cache[$k];
  $stmt = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
  if (!$stmt) return $cache[$k] = false;
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $ok = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  return $cache[$k] = $ok;
}

function sanitize_identifier(?string $id) { if ($id === null) return null; return preg_match('/^[A-Za-z0-9_]+$/', $id) ? $id : null; }

/* -------------------- environment -------------------- */
$event_id = et_get_active_event_id($conn);

function choice_validate_award_links(
  mysqli $conn,
  array $question_ids,
  ?int $event_id,
  array $establishment_type_ids,
  bool $hasTypeAwardMap
): ?string {
  if ($event_id === null || $event_id <= 0) {
    return 'No active event. Activate an event before managing establishments.';
  }
  if (!et_awards_belong_to_event($conn, $question_ids, $event_id)) {
    return 'One or more selected awards do not belong to the active event.';
  }
  $establishment_type_ids = et_ints($establishment_type_ids);
  if ($establishment_type_ids !== [] && $hasTypeAwardMap) {
    if (!et_awards_match_establishment_types($conn, $question_ids, $establishment_type_ids, $event_id)) {
      return 'One or more selected awards are not allowed for the chosen nature of business.';
    }
  }
  return null;
}

function choice_validate_establishment_types(
  mysqli $conn,
  array $establishment_type_ids,
  ?int $event_id,
  bool $canPersistType,
  ?string $typeStatusColumn
): ?string {
  if (!$canPersistType) {
    return null;
  }
  $establishment_type_ids = et_ints($establishment_type_ids);
  if ($establishment_type_ids === []) {
    $activeTypes = et_fetch_type_options_for_event($conn, $event_id, $typeStatusColumn);
    if (!empty($activeTypes)) {
      return 'Please select at least one nature of business.';
    }
    return null;
  }
  if ($event_id === null || !et_types_belong_to_event($conn, $establishment_type_ids, $event_id)) {
    return 'Invalid nature of business selected.';
  }
  return null;
}

function choice_parse_type_ids_from_request(array $data): array {
  if (isset($data['establishment_type_ids'])) {
    return et_parse_type_ids($data['establishment_type_ids']);
  }
  if (isset($data['establishment_type_id'])) {
    return et_parse_type_ids($data['establishment_type_id']);
  }
  return [];
}

$hasTypesTable       = table_exists($conn, 'tbl_establishment_types');
$hasChoiceTypeColumn = column_exists($conn, 'tbl_choices', 'establishment_type_id');
$hasOnBallotColumn   = ballot_status_ensure_column($conn);
$hasTypeAwardMap     = table_exists($conn, 'tbl_establishment_type_awards');
$hasChoiceTypeMap    = table_exists($conn, ET_TBL_CHOICE_TYPES);

$uiHasTypes     = $hasTypesTable;
$canPersistType = $hasTypesTable && ($hasChoiceTypeColumn || $hasChoiceTypeMap);

$typeStatusColumn = null;
$typeOrderColumn  = null;
if ($hasTypesTable) {
  if     (column_exists($conn, 'tbl_establishment_types', 'status'))    $typeStatusColumn = 'status';
  elseif (column_exists($conn, 'tbl_establishment_types', 'is_active')) $typeStatusColumn = 'is_active';
  elseif (column_exists($conn, 'tbl_establishment_types', 'active'))    $typeStatusColumn = 'active';

  if     (column_exists($conn, 'tbl_establishment_types', 'display_order')) $typeOrderColumn = 'display_order';
  elseif (column_exists($conn, 'tbl_establishment_types', 'sort_order'))    $typeOrderColumn = 'sort_order';
}

/* -------------------- row/links helpers -------------------- */
function fetch_choice(mysqli $conn, int $choice_id): ?array {
  global $hasChoiceTypeColumn;
  $fields = 'choice_id, choice_name, email, status, event_id';
  if ($hasChoiceTypeColumn) $fields .= ', establishment_type_id';
  $st = $conn->prepare("SELECT $fields FROM tbl_choices WHERE choice_id = ?");
  $st->bind_param("i", $choice_id);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();
  return $row ?: null;
}

function fetch_choice_links(mysqli $conn, int $choice_id): array {
  $st = $conn->prepare("
    SELECT qc.question_id
    FROM tbl_question_choices qc
    JOIN tbl_questions q ON qc.question_id = q.question_id
    WHERE qc.choice_id = ? AND q.choice_type = 1
    ORDER BY qc.question_id
  ");
  $st->bind_param("i", $choice_id);
  $st->execute();
  $res = $st->get_result();
  $ids = [];
  while ($row = $res->fetch_assoc()) $ids[] = (int)$row['question_id'];
  $st->close();
  return $ids;
}

/* -------------------- API: loadEstablishmentTypes -------------------- */
if (($data['action'] ?? '') === 'loadEstablishmentTypes') {
  if (!$uiHasTypes) {
    echo json_encode(['status' => 'success', 'data' => [], 'featureEnabled' => false, 'supportsFiltering' => false]);
    exit;
  }
  if ($event_id === null) {
    echo json_encode([
      'status'            => 'success',
      'data'              => [],
      'featureEnabled'    => true,
      'supportsFiltering' => false,
      'no_active_event'   => true,
    ]);
    exit;
  }
  $types = et_fetch_type_options_for_event($conn, $event_id, $typeStatusColumn);
  echo json_encode([
    'status'            => 'success',
    'data'              => $types,
    'featureEnabled'    => true,
    'supportsFiltering' => $hasTypeAwardMap,
    'no_active_event'   => false,
  ]);
  exit;
}

/* -------------------- API: loadAll (awards) -------------------- */
if (($_SERVER['REQUEST_METHOD'] === 'POST' && ($data['action'] ?? '') === 'loadAll') || $_SERVER['REQUEST_METHOD'] === 'GET') {
  $typeFilters = [];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($data['establishment_type_ids'])) {
      $typeFilters = et_parse_type_ids($data['establishment_type_ids']);
    } elseif (isset($data['establishment_type_id'])) {
      $typeFilters = et_parse_type_ids($data['establishment_type_id']);
    }
  } elseif (isset($_GET['establishment_type_ids'])) {
    $typeFilters = et_parse_type_ids($_GET['establishment_type_ids']);
  } elseif (isset($_GET['establishment_type_id'])) {
    $typeFilters = et_parse_type_ids($_GET['establishment_type_id']);
  }

  if ($event_id === null) {
    echo json_encode(['status' => 'success', 'data' => [], 'no_active_event' => true]);
    exit;
  }

  if ($typeFilters !== [] && !et_types_belong_to_event($conn, $typeFilters, $event_id)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
  }

  if ($typeFilters !== [] && $hasTypeAwardMap) {
    $awards = et_fetch_awards_for_types($conn, $typeFilters, $event_id);
    $rows = array_map(static function (array $a): array {
      return [
        'question_id'   => $a['question_id'],
        'question_name' => $a['question_name'],
        'category_id'   => $a['category_id'],
        'category_name' => $a['category_name'],
      ];
    }, $awards);
    echo json_encode(['status' => 'success', 'data' => $rows]);
    exit;
  }

  $sql = "SELECT DISTINCT q.question_id, q.question_name, c.category_id, c.category_name
          FROM tbl_questions q
          JOIN tbl_categories c ON q.category_id = c.category_id
          WHERE c.event_id = ? AND q.choice_type = 1
          ORDER BY c.category_name ASC, q.question_name ASC";
  $st = $conn->prepare($sql);
  $st->bind_param('i', $event_id);
  $st->execute();
  $r = $st->get_result();
  $rows = [];
  while ($row = $r->fetch_assoc()) $rows[] = $row;
  $st->close();

  echo json_encode(['status' => 'success', 'data' => $rows]);
  exit;
}

/* -------------------- API: loadAllChoices -------------------- */
if (($data['action'] ?? '') === 'loadAllChoices') {
  if ($event_id === null) {
    echo json_encode(['status' => 'success', 'data' => [], 'no_active_event' => true]);
    exit;
  }
  $sql = "SELECT c.choice_id, c.choice_name, c.status, c.email, c.qr_sent";
  if ($hasChoiceTypeColumn) $sql .= ', c.establishment_type_id';
  if ($hasOnBallotColumn) $sql .= ', c.on_ballot';
  $sql .= " FROM tbl_choices c WHERE c.event_id = ?";
  if ($hasOnBallotColumn) {
    $sql .= " AND c.on_ballot = 1";
  }
  $sql .= " ORDER BY c.choice_name ASC";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $event_id);
  $st->execute();
  $r = $st->get_result();
  $out = [];
  while ($row = $r->fetch_assoc()) $out[] = $row;
  $st->close();

  foreach ($out as &$row) {
    $cid = (int)($row['choice_id'] ?? 0);
    $typeIds = $cid > 0 ? et_get_choice_type_ids($conn, $cid) : [];
    $typeRows = et_type_rows_for_ids($conn, $typeIds);
    $row['establishment_type_ids'] = $typeIds;
    $row['establishment_types'] = $typeRows;
    $row['establishment_type_name'] = implode(', ', array_map(static fn($t) => $t['type_name'], $typeRows));
    if (!isset($row['establishment_type_id']) || !(int)$row['establishment_type_id']) {
      $row['establishment_type_id'] = $typeIds[0] ?? null;
    }
    if ($cid <= 0) {
      $row['vote_url'] = '';
      continue;
    }
    $row['on_ballot'] = $hasOnBallotColumn ? (int) ($row['on_ballot'] ?? 1) : 1;
    try {
      $row['vote_url'] = qr_vote_url_for_choice($cid, $conn);
    } catch (Throwable $e) {
      $row['vote_url'] = '';
    }
  }
  unset($row);

  echo json_encode(['status' => 'success', 'data' => $out]);
  exit;
}

/* -------------------- API: releaseToBallot -------------------- */
if (($data['action'] ?? '') === 'releaseToBallot') {
  $choice_id = (int) ($data['choice_id'] ?? 0);
  require_once __DIR__ . '/includes/twg_ballot.php';
  $result = ballot_status_release_choice($conn, $choice_id);
  if (!$result['ok']) {
    echo json_encode([
      'status' => 'error',
      'message' => $result['message'],
      'code' => $result['code'] ?? '',
      'eligibility' => $result['eligibility'] ?? null,
    ]);
    exit;
  }
  if (function_exists('audit_log')) {
    audit_log($conn, 'choices', 'release_to_ballot', 'choice', $choice_id, [
      'on_ballot' => 1,
      'top10_count' => $result['top10_count'] ?? 0,
    ]);
  }

  $elig = is_array($result['eligibility'] ?? null) ? $result['eligibility'] : null;
  $awardHtml = function_exists('twg_ballot_award_email_html')
    ? twg_ballot_award_email_html($elig)
    : '';

  require_once __DIR__ . '/includes/qr_email_send.php';
  $emailResult = qr_email_send_for_choice($conn, $choice_id, [
    'skip_if_already_sent' => true,
    'require_on_ballot' => true,
    'award_html' => $awardHtml,
    'subject' => trim((string) ($data['subject'] ?? '')),
    'message' => trim((string) ($data['message'] ?? '')),
  ]);

  $message = $result['message'];
  if (!empty($emailResult['sent'])) {
    $message .= ' The QR code and voting link were emailed, including the shortlisted award titles.';
  } elseif (empty($emailResult['skipped'])) {
    $message .= ' ' . ($emailResult['message'] ?? 'QR email was not sent.');
  }

  echo json_encode([
    'status' => 'success',
    'message' => $message,
    'on_ballot' => 1,
    'email_sent' => !empty($emailResult['sent']),
    'email_skipped' => !empty($emailResult['skipped']),
    'email_message' => $emailResult['message'] ?? '',
    'eligibility' => $elig,
  ]);
  exit;
}

/* -------------------- API: previewBallotEmail -------------------- */
if (($data['action'] ?? '') === 'previewBallotEmail') {
  $choice_id = (int) ($data['choice_id'] ?? 0);
  $kind = strtolower(trim((string) ($data['kind'] ?? 'qr')));
  require_once __DIR__ . '/includes/twg_ballot.php';

  if ($kind === 'notice') {
    $composed = twg_ballot_notice_compose($conn, $choice_id);
    if (empty($composed['ok'])) {
      echo json_encode(['status' => 'error', 'message' => $composed['message'] ?? 'Could not preview the evaluation notice.']);
      exit;
    }
    echo json_encode([
      'status' => 'success',
      'kind' => 'notice',
      'to' => $composed['to'] ?? '',
      'name' => $composed['name'] ?? 'Business',
      'subject' => $composed['subject'] ?? 'TWG evaluation update',
      'message' => '',
      'html' => $composed['html'] ?? '',
      'has_email' => !empty($composed['has_email']),
      'warning' => empty($composed['has_email']) ? ($composed['message'] ?? 'No valid email on file.') : '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $elig = twg_ballot_eligibility_for_choice($conn, $choice_id);
  require_once __DIR__ . '/includes/qr_email_send.php';
  $composed = qr_email_compose_for_choice($conn, $choice_id, [
    'require_on_ballot' => false,
    'allow_missing_email' => true,
    'award_html' => twg_ballot_award_email_html($elig),
    'subject' => trim((string) ($data['subject'] ?? '')),
    'message' => trim((string) ($data['message'] ?? '')),
  ]);
  if (empty($composed['ok'])) {
    echo json_encode(['status' => 'error', 'message' => $composed['message'] ?? 'Could not preview the voting email.']);
    exit;
  }
  echo json_encode([
    'status' => 'success',
    'kind' => 'qr',
    'to' => $composed['to'] ?? '',
    'name' => $composed['name'] ?? 'Business',
    'subject' => $composed['subject'] ?? 'Your QR Code for Tatak Ormoc Voting',
    'message' => $composed['message_body'] ?? '',
    'html' => $composed['preview_html'] ?? ($composed['html'] ?? ''),
    'has_email' => !empty($composed['has_email']),
    'warning' => empty($composed['has_email']) ? ($composed['message'] ?? 'No valid email on file.') : '',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* -------------------- API: notifyNotAdvanced -------------------- */
if (($data['action'] ?? '') === 'notifyNotAdvanced') {
  require_once __DIR__ . '/includes/twg_ballot.php';
  $choice_id = (int) ($data['choice_id'] ?? 0);
  $notice = twg_ballot_notice_email($conn, $choice_id);
  if (function_exists('audit_log') && !empty($notice['ok'])) {
    audit_log($conn, 'choices', 'twg_not_advanced_notice', 'choice', $choice_id, []);
  }
  echo json_encode([
    'status' => $notice['ok'] ? 'success' : 'error',
    'message' => $notice['message'],
    'on_ballot' => 0,
    'email_sent' => !empty($notice['sent']),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* -------------------- API: filterByQuestion -------------------- */
if (($data['action'] ?? '') === 'filterByQuestion') {
  if ($event_id === null) {
    echo json_encode(['status' => 'success', 'data' => [], 'no_active_event' => true]);
    exit;
  }
  $qid = (int)$data['question_id'];
  if (!et_awards_belong_to_event($conn, [$qid], $event_id)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
  }
  $st = $conn->prepare("SELECT c.choice_id, c.choice_name
                        FROM tbl_choices c
                        JOIN tbl_question_choices qc ON c.choice_id = qc.choice_id
                        WHERE qc.question_id = ? AND c.event_id = ?
                        ORDER BY c.choice_name ASC");
  $st->bind_param("ii", $qid, $event_id);
  $st->execute();
  $r = $st->get_result();
  $out = [];
  while ($row = $r->fetch_assoc()) $out[] = $row;
  $st->close();
  echo json_encode(['status' => 'success', 'data' => $out]);
  exit;
}

/* -------------------- API: create -------------------- */
if (($data['action'] ?? '') === 'create') {
  if ($event_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'No active event. Activate an event before managing establishments.']);
    exit;
  }
  $choice_name = trim($data['choice_name'] ?? '');
  $question_ids = $data['question_ids'] ?? [];
  $email = trim($data['email'] ?? '');

  // normalize + dedupe question IDs
  $question_ids = array_values(array_unique(array_map(fn($x)=> (int)$x, (array)$question_ids)));

  $establishment_type_ids = $canPersistType ? choice_parse_type_ids_from_request($data) : [];
  $establishment_type_id = $establishment_type_ids[0] ?? null;

  if (!$choice_name || !is_array($question_ids) || empty($question_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing choice name or question IDs']); exit;
  }

  $typeErr = choice_validate_establishment_types($conn, $establishment_type_ids, $event_id, $canPersistType, $typeStatusColumn);
  if ($typeErr !== null) {
    echo json_encode(['status' => 'error', 'message' => $typeErr]);
    exit;
  }
  $awardErr = choice_validate_award_links($conn, $question_ids, $event_id, $establishment_type_ids, $hasTypeAwardMap);
  if ($awardErr !== null) {
    echo json_encode(['status' => 'error', 'message' => $awardErr]);
    exit;
  }

  $st = $conn->prepare("SELECT choice_id FROM tbl_choices WHERE choice_name = ? AND event_id = ?");
  $st->bind_param("si", $choice_name, $event_id);
  $st->execute();
  if ($st->get_result()->num_rows > 0) {
    echo json_encode(['status' => 'duplicate', 'message' => 'Choice already exists.']); exit;
  }

  if ($canPersistType) {
    $st = $conn->prepare("INSERT INTO tbl_choices (choice_name, email, event_id, status, establishment_type_id) VALUES (?, ?, ?, 1, ?)");
    $st->bind_param("ssii", $choice_name, $email, $event_id, $establishment_type_id);
  } else {
    $st = $conn->prepare("INSERT INTO tbl_choices (choice_name, email, event_id, status) VALUES (?, ?, ?, 1)");
    $st->bind_param("ssi", $choice_name, $email, $event_id);
  }

  if ($st->execute()) {
    $choice_id = (int)$st->insert_id;
    if ($canPersistType) {
      et_set_choice_types($conn, $choice_id, $establishment_type_ids);
    }

    // link awards in a transaction
    $conn->begin_transaction();
    $link = $conn->prepare("INSERT INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)");
    $failures = [];

    foreach ($question_ids as $qid) {
      $link->bind_param("ii", $qid, $choice_id);
      if (!$link->execute()) {
        $failures[] = ['qid' => $qid, 'err' => $link->error, 'errno' => $link->errno];
      }
    }

    if ($failures) {
      $conn->rollback();
      error_log('QC insert failures (create): ' . json_encode($failures));
      echo json_encode(['status' => 'error', 'message' => 'Failed to link some awards.', 'details' => $failures]);
      exit;
    }
    $conn->commit();

    if (function_exists('public_slug_for_choice')) {
      public_slug_for_choice($conn, $choice_id);
    }

    $newRow   = fetch_choice($conn, $choice_id) ?? ['choice_id' => $choice_id, 'choice_name' => $choice_name, 'email' => $email, 'event_id' => $event_id, 'status' => 1];
    $newLinks = fetch_choice_links($conn, $choice_id);
    audit_log($conn, 'choices', 'create', 'choice', $choice_id, ['new' => $newRow, 'links' => ['new' => $newLinks]]);

    echo json_encode(['status' => 'success']);
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to insert choice.']);
  }
  exit;
}

/* -------------------- API: update -------------------- */
if (($data['action'] ?? '') === 'update') {
  if ($event_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'No active event. Activate an event before managing establishments.']);
    exit;
  }
  $choice_id   = (int)($data['choice_id'] ?? 0);
  $choice_name = trim($data['choice_name'] ?? '');
  $email       = trim($data['email'] ?? '');
  $question_ids = $data['question_ids'] ?? [];
  // normalize + dedupe
  $question_ids = array_values(array_unique(array_map(fn($x)=> (int)$x, (array)$question_ids)));

  $establishment_type_ids = $canPersistType ? choice_parse_type_ids_from_request($data) : [];
  $establishment_type_id = $establishment_type_ids[0] ?? null;

  if (!$choice_id || !$choice_name || !is_array($question_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data for update']); exit;
  }

  if (!et_choice_belongs_to_event($conn, $choice_id, $event_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Business not found for the active event.']);
    exit;
  }

  $typeErr = choice_validate_establishment_types($conn, $establishment_type_ids, $event_id, $canPersistType, $typeStatusColumn);
  if ($typeErr !== null) {
    echo json_encode(['status' => 'error', 'message' => $typeErr]);
    exit;
  }
  $awardErr = choice_validate_award_links($conn, $question_ids, $event_id, $establishment_type_ids, $hasTypeAwardMap);
  if ($awardErr !== null) {
    echo json_encode(['status' => 'error', 'message' => $awardErr]);
    exit;
  }

  $st = $conn->prepare("SELECT choice_id FROM tbl_choices WHERE choice_name = ? AND choice_id != ? AND event_id = ?");
  $st->bind_param("sii", $choice_name, $choice_id, $event_id);
  $st->execute();
  if ($st->get_result()->num_rows > 0) { echo json_encode(['status' => 'duplicate']); exit; }

  $oldRow   = fetch_choice($conn, $choice_id);
  $oldLinks = fetch_choice_links($conn, $choice_id);

  if ($canPersistType) {
    $st = $conn->prepare("UPDATE tbl_choices SET choice_name = ?, email = ?, event_id = ?, establishment_type_id = ? WHERE choice_id = ?");
    $st->bind_param("ssiii", $choice_name, $email, $event_id, $establishment_type_id, $choice_id);
  } else {
    $st = $conn->prepare("UPDATE tbl_choices SET choice_name = ?, email = ?, event_id = ? WHERE choice_id = ?");
    $st->bind_param("ssii", $choice_name, $email, $event_id, $choice_id);
  }
  $ok = $st->execute();

  if ($ok) {
    if ($canPersistType) {
      et_set_choice_types($conn, $choice_id, $establishment_type_ids);
    }
    // reset & relink inside a transaction
    $conn->begin_transaction();

    $del = $conn->prepare("DELETE FROM tbl_question_choices WHERE choice_id = ?");
    $del->bind_param("i", $choice_id);
    if (!$del->execute()) {
      $conn->rollback();
      echo json_encode(['status' => 'error', 'message' => 'Failed to reset award links.']); exit;
    }

    $link = $conn->prepare("INSERT INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)");
    $failures = [];
    foreach ($question_ids as $qid) {
      $link->bind_param("ii", $qid, $choice_id);
      if (!$link->execute()) {
        $failures[] = ['qid' => $qid, 'err' => $link->error, 'errno' => $link->errno];
      }
    }

    if ($failures) {
      $conn->rollback();
      error_log('QC insert failures (update): ' . json_encode($failures));
      echo json_encode(['status' => 'error', 'message' => 'Failed to link some awards.', 'details' => $failures]);
      exit;
    }

    $newIds = array_values(array_unique(array_map('intval', $question_ids)));
    $removedIds = array_values(array_diff($oldLinks, $newIds));
    if ($removedIds !== []) {
      $nomIds = award_nomination_ids_for_choice($conn, $choice_id);
      award_remove_questions_from_nominations($conn, $nomIds, $removedIds, 'does_not_qualify');
    }

    $conn->commit();

    $newRow   = fetch_choice($conn, $choice_id);
    $newLinks = fetch_choice_links($conn, $choice_id);
    $diff     = audit_diff_assoc($oldRow ?? [], $newRow ?? []);
    $linksDiff = ($oldLinks !== $newLinks) ? ['old' => $oldLinks, 'new' => $newLinks] : null;
    $details  = ['old' => $oldRow, 'new' => $newRow, 'diff' => $diff];
    if ($linksDiff) $details['links'] = $linksDiff;
    audit_log($conn, 'choices', 'update', 'choice', $choice_id, $details);

    echo json_encode(['status' => 'success']);
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update choice.']);
  }
  exit;
}

/* -------------------- API: delete -------------------- */
if (($data['action'] ?? '') === 'delete') {
  if ($event_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'No active event. Activate an event before managing establishments.']);
    exit;
  }
  $ids = $data['ids'] ?? [];
  if (!is_array($ids) || !count($ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid choice IDs']); exit;
  }

  $ph    = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $oldRows = [];
  $st = $conn->prepare("SELECT choice_id, choice_name, email, status, event_id FROM tbl_choices WHERE choice_id IN ($ph) AND event_id = ?");
  $st->bind_param($types . 'i', ...array_merge($ids, [$event_id]));
  $st->execute();
  $res = $st->get_result();
  while ($row = $res->fetch_assoc()) {
    $cid = (int)$row['choice_id'];
    $oldRows[$cid] = $row;
    $oldRows[$cid]['links'] = ['old' => fetch_choice_links($conn, $cid)];
  }
  $st->close();

  if (!$oldRows) {
    echo json_encode(['status' => 'error', 'message' => 'No matching establishments found for the active event.']);
    exit;
  }
  $ids = array_keys($oldRows);

  $ph    = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $stmt1 = $conn->prepare("DELETE FROM tbl_question_choices WHERE choice_id IN ($ph)");
  $stmt1->bind_param($types, ...$ids);
  $stmt1->execute();

  $stmt2 = $conn->prepare("DELETE FROM tbl_choices WHERE choice_id IN ($ph) AND event_id = ?");
  $stmt2->bind_param($types . 'i', ...array_merge($ids, [$event_id]));
  $stmt2->execute();

  foreach ($ids as $cid) {
    $cid = (int)$cid;
    $old = $oldRows[$cid] ?? ['choice_id' => $cid];
    audit_log($conn, 'choices', 'delete', 'choice', $cid, ['old' => $old]);
  }

  echo json_encode(['status' => 'success']); exit;
}

/* -------------------- API: getLinkedQuestions -------------------- */
if (($data['action'] ?? '') === 'getLinkedQuestions') {
  if ($event_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'No active event.']);
    exit;
  }
  $choice_id = (int)$data['choice_id'];
  if (!et_choice_belongs_to_event($conn, $choice_id, $event_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Business not found for the active event.']);
    exit;
  }
  $st = $conn->prepare("SELECT qc.question_id
                        FROM tbl_question_choices qc
                        JOIN tbl_questions q ON qc.question_id = q.question_id
                        JOIN tbl_categories c ON c.category_id = q.category_id AND c.event_id = ?
                        WHERE qc.choice_id = ? AND q.choice_type = 1");
  $st->bind_param("ii", $event_id, $choice_id);
  $st->execute();
  $res = $st->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = (int)$row['question_id'];
  echo json_encode(['status' => 'success', 'data' => $out]); exit;
}

/* -------------------- API: toggleStatus -------------------- */
if (($data['action'] ?? '') === 'toggleStatus') {
  if ($event_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'No active event.']);
    exit;
  }
  $choice_id = (int)$data['choice_id'];
  $status    = (int)$data['status'];
  if (!et_choice_belongs_to_event($conn, $choice_id, $event_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Business not found for the active event.']);
    exit;
  }
  $old = fetch_choice($conn, $choice_id);

  $st = $conn->prepare("UPDATE tbl_choices SET status = ? WHERE choice_id = ?");
  $st->bind_param("ii", $status, $choice_id);
  $ok = $st->execute();
  $st->close();

  if ($ok) {
    $new = fetch_choice($conn, $choice_id);
    $diff = audit_diff_assoc($old ?? [], $new ?? []);
    $action = ($status === 1) ? 'activate' : 'deactivate';
    audit_log($conn, 'choices', $action, 'choice', $choice_id, ['old' => $old, 'new' => $new, 'diff' => $diff]);
    echo json_encode(['status' => 'success']);
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update status.']);
  }
  exit;
}

/* fallback */
echo json_encode(['status' => 'error', 'message' => 'Invalid request']); exit;

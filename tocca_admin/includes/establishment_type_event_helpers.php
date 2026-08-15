<?php
declare(strict_types=1);

/**
 * Event-scoped establishment type helpers (shared by choice.php and establishment_type.php).
 */
const ET_TBL_TYPES          = 'tbl_establishment_types';
const ET_TBL_TYPES_X_AWARDS = 'tbl_establishment_type_awards';
const ET_TBL_EVENTS         = 'tbl_events';
const ET_TBL_CATEGORIES     = 'tbl_categories';
const ET_TBL_QUESTIONS      = 'tbl_questions';

function et_table_exists(mysqli $conn, string $table): bool {
  static $cache = [];
  if (isset($cache[$table])) {
    return $cache[$table];
  }
  $stmt = $conn->prepare(
    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
  );
  if (!$stmt) {
    return $cache[$table] = false;
  }
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $ok = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  return $cache[$table] = $ok;
}

function et_ints(array $a): array {
  $out = [];
  foreach ($a as $v) {
    $n = (int) $v;
    if ($n > 0) {
      $out[] = $n;
    }
  }
  return $out;
}

function et_detect_type_status_column(mysqli $conn): ?string
{
  static $cached = null;
  static $done = false;
  if ($done) {
    return $cached;
  }
  $done = true;
  if (!et_table_exists($conn, ET_TBL_TYPES)) {
    return $cached = null;
  }
  foreach (['status', 'is_active', 'active'] as $candidate) {
    $stmt = $conn->prepare(
      'SELECT 1 FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
       LIMIT 1'
    );
    if (!$stmt) {
      continue;
    }
    $table = ET_TBL_TYPES;
    $stmt->bind_param('ss', $table, $candidate);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($ok) {
      return $cached = $candidate;
    }
  }

  return $cached = null;
}

function et_resolve_event_id(mysqli $conn, int $requested = 0): int
{
  if ($requested > 0) {
    $stmt = $conn->prepare('SELECT event_id FROM ' . ET_TBL_EVENTS . ' WHERE event_id = ? LIMIT 1');
    $stmt->bind_param('i', $requested);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($ok) {
      return $requested;
    }
  }
  $active = et_get_active_event_id($conn);

  return $active ?? 0;
}

function et_get_active_event_id(mysqli $conn): ?int {
  $sql = 'SELECT event_id FROM ' . ET_TBL_EVENTS . '
          WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
          ORDER BY year DESC, event_id DESC LIMIT 1';
  if ($res = $conn->query($sql)) {
    if ($row = $res->fetch_assoc()) {
      return (int) $row['event_id'];
    }
  }
  return null;
}

function et_awards_belong_to_event(mysqli $conn, array $questionIds, int $eventId): bool {
  $questionIds = et_ints($questionIds);
  if ($questionIds === []) {
    return false;
  }
  $ph = implode(',', array_fill(0, count($questionIds), '?'));
  $sql = '
    SELECT COUNT(DISTINCT q.question_id) AS cnt
    FROM ' . ET_TBL_QUESTIONS . ' q
    INNER JOIN ' . ET_TBL_CATEGORIES . ' c ON c.category_id = q.category_id
    WHERE c.event_id = ? AND q.question_id IN (' . $ph . ')
  ';
  $stmt = $conn->prepare($sql);
  $types = 'i' . str_repeat('i', count($questionIds));
  $params = array_merge([$eventId], $questionIds);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
  $stmt->close();
  return $cnt === count($questionIds);
}

function et_choices_have_establishment_type(mysqli $conn): bool {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }
  $res = $conn->query("
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tbl_choices'
      AND column_name = 'establishment_type_id'
    LIMIT 1
  ");
  $cached = ($res && $res->num_rows > 0);
  return $cached;
}

function et_type_ids_for_event(mysqli $conn, int $eventId): array {
  $ids = [];
  if (!et_table_exists($conn, ET_TBL_TYPES_X_AWARDS)) {
    return [];
  }
  $stmt = $conn->prepare('
    SELECT DISTINCT x.type_id
    FROM ' . ET_TBL_TYPES_X_AWARDS . ' x
    INNER JOIN ' . ET_TBL_QUESTIONS . ' q ON q.question_id = x.question_id
    INNER JOIN ' . ET_TBL_CATEGORIES . ' c ON c.category_id = q.category_id AND c.event_id = ?
  ');
  $stmt->bind_param('i', $eventId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $ids[(int) $row['type_id']] = true;
  }
  $stmt->close();

  if (et_choices_have_establishment_type($conn)) {
    $stmt = $conn->prepare('
      SELECT DISTINCT establishment_type_id AS type_id
      FROM tbl_choices
      WHERE event_id = ? AND establishment_type_id IS NOT NULL AND establishment_type_id > 0
    ');
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $ids[(int) $row['type_id']] = true;
    }
    $stmt->close();
  }

  return array_map('intval', array_keys($ids));
}

function et_type_belongs_to_event(mysqli $conn, int $typeId, ?int $eventId): bool {
  if ($eventId === null || $eventId <= 0 || $typeId <= 0) {
    return false;
  }
  return in_array($typeId, et_type_ids_for_event($conn, $eventId), true);
}

function et_fetch_awards_for_type(mysqli $conn, int $typeId, ?int $eventId): array {
  if ($eventId === null || $eventId <= 0) {
    return [];
  }
  $stmt = $conn->prepare('
    SELECT q.question_id, q.question_name, c.category_id, c.category_name
    FROM ' . ET_TBL_TYPES_X_AWARDS . ' x
    JOIN ' . ET_TBL_QUESTIONS . ' q ON q.question_id = x.question_id
    INNER JOIN ' . ET_TBL_CATEGORIES . ' c ON c.category_id = q.category_id AND c.event_id = ?
    WHERE x.type_id = ?
    ORDER BY c.category_name ASC, q.question_name ASC
  ');
  $stmt->bind_param('ii', $eventId, $typeId);
  $stmt->execute();
  $res = $stmt->get_result();
  $awards = [];
  while ($row = $res->fetch_assoc()) {
    $awards[] = [
      'question_id'   => (int) $row['question_id'],
      'question_name' => $row['question_name'] ?? 'Award',
      'category_id'   => $row['category_id'] !== null ? (int) $row['category_id'] : null,
      'category_name' => $row['category_name'] ?? null,
    ];
  }
  $stmt->close();
  return $awards;
}

function et_fetch_types_for_event(mysqli $conn, ?int $eventId): array {
  if ($eventId === null || $eventId <= 0) {
    return [];
  }
  $typeIds = et_type_ids_for_event($conn, $eventId);
  if ($typeIds === []) {
    return [];
  }

  $statusCol = et_detect_type_status_column($conn);
  $statusExpr = $statusCol !== null
    ? 'COALESCE(`' . $statusCol . '`, 1)'
    : '1';

  $ph = implode(',', array_fill(0, count($typeIds), '?'));
  $sql = '
    SELECT type_id, type_name, ' . $statusExpr . ' AS status
    FROM ' . ET_TBL_TYPES . '
    WHERE type_id IN (' . $ph . ')
    ORDER BY type_name ASC
  ';
  $stmt = $conn->prepare($sql);
  $types = str_repeat('i', count($typeIds));
  $stmt->bind_param($types, ...$typeIds);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $tid = (int) $r['type_id'];
    $out[] = [
      'type_id'   => $tid,
      'type_name' => $r['type_name'],
      'status'    => (int) $r['status'],
      'awards'    => et_fetch_awards_for_type($conn, $tid, $eventId),
    ];
  }
  $stmt->close();
  return $out;
}

/**
 * Dropdown options for establishments UI (active types only, scoped to event).
 */
function et_fetch_type_options_for_event(
  mysqli $conn,
  ?int $eventId,
  ?string $statusCol = null
): array {
  if ($eventId === null || $eventId <= 0) {
    return [];
  }
  if ($statusCol !== null && !preg_match('/^[A-Za-z0-9_]+$/', $statusCol)) {
    $statusCol = null;
  }
  if ($statusCol === null) {
    $statusCol = et_detect_type_status_column($conn);
  }

  $typeIds = et_type_ids_for_event($conn, $eventId);
  if ($typeIds === []) {
    return [];
  }

  $statusExpr = $statusCol !== null
    ? 'COALESCE(`' . $statusCol . '`, 1)'
    : '1';

  $ph = implode(',', array_fill(0, count($typeIds), '?'));
  $sql = '
    SELECT type_id, type_name, ' . $statusExpr . ' AS status
    FROM ' . ET_TBL_TYPES . '
    WHERE type_id IN (' . $ph . ')
    ORDER BY type_name ASC
  ';
  $stmt = $conn->prepare($sql);
  $types = str_repeat('i', count($typeIds));
  $stmt->bind_param($types, ...$typeIds);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    if ((int) ($r['status'] ?? 1) !== 1) {
      continue;
    }
    $out[] = [
      'type_id'   => (int) $r['type_id'],
      'type_name' => (string) $r['type_name'],
    ];
  }
  $stmt->close();

  return $out;
}

function et_awards_match_establishment_type(mysqli $conn, array $questionIds, int $typeId, int $eventId): bool {
  $questionIds = et_ints($questionIds);
  if ($questionIds === [] || $typeId <= 0) {
    return false;
  }
  $ph = implode(',', array_fill(0, count($questionIds), '?'));
  $sql = '
    SELECT COUNT(DISTINCT x.question_id) AS cnt
    FROM ' . ET_TBL_TYPES_X_AWARDS . ' x
    INNER JOIN ' . ET_TBL_QUESTIONS . ' q ON q.question_id = x.question_id
    INNER JOIN ' . ET_TBL_CATEGORIES . ' c ON c.category_id = q.category_id AND c.event_id = ?
    WHERE x.type_id = ? AND x.question_id IN (' . $ph . ')
  ';
  $stmt = $conn->prepare($sql);
  $types = 'ii' . str_repeat('i', count($questionIds));
  $params = array_merge([$eventId, $typeId], $questionIds);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
  $stmt->close();
  return $cnt === count($questionIds);
}

function et_choice_belongs_to_event(mysqli $conn, int $choiceId, int $eventId): bool {
  if ($choiceId <= 0 || $eventId <= 0) {
    return false;
  }
  $st = $conn->prepare('SELECT 1 FROM tbl_choices WHERE choice_id = ? AND event_id = ? LIMIT 1');
  $st->bind_param('ii', $choiceId, $eventId);
  $st->execute();
  $ok = $st->get_result()->num_rows > 0;
  $st->close();
  return $ok;
}

/** Junction: registration (nominee) ↔ establishment types */
const ET_TBL_NOM_TYPES = 'tbl_nomination_establishment_types';
/** Junction: choice (establishment) ↔ establishment types */
const ET_TBL_CHOICE_TYPES = 'tbl_choice_establishment_types';

/**
 * Ensure M2M junction tables exist, migrate legacy single FKs, drop empty legacy maps.
 */
function et_ensure_m2m_schema(mysqli $conn): void
{
  static $done = false;
  if ($done) {
    return;
  }
  $done = true;

  $conn->query("
    CREATE TABLE IF NOT EXISTS `" . ET_TBL_NOM_TYPES . "` (
      `nomination_id` INT NOT NULL,
      `type_id` INT UNSIGNED NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`nomination_id`, `type_id`),
      KEY `idx_net_type` (`type_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $conn->query("
    CREATE TABLE IF NOT EXISTS `" . ET_TBL_CHOICE_TYPES . "` (
      `choice_id` INT NOT NULL,
      `type_id` INT UNSIGNED NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`choice_id`, `type_id`),
      KEY `idx_cet_type` (`type_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  // Migrate single FK → junction (idempotent).
  if (et_table_exists($conn, 'tbl_nominations')) {
    $hasCol = false;
    if ($r = $conn->query("SHOW COLUMNS FROM `tbl_nominations` LIKE 'establishment_type_id'")) {
      $hasCol = $r->num_rows > 0;
      $r->free();
    }
    if ($hasCol) {
      $conn->query("
        INSERT IGNORE INTO `" . ET_TBL_NOM_TYPES . "` (`nomination_id`, `type_id`)
        SELECT `nomination_id`, `establishment_type_id`
        FROM `tbl_nominations`
        WHERE `establishment_type_id` IS NOT NULL AND `establishment_type_id` > 0
      ");
    }
  }

  if (et_table_exists($conn, 'tbl_choices')) {
    $hasCol = false;
    if ($r = $conn->query("SHOW COLUMNS FROM `tbl_choices` LIKE 'establishment_type_id'")) {
      $hasCol = $r->num_rows > 0;
      $r->free();
    }
    if ($hasCol) {
      $conn->query("
        INSERT IGNORE INTO `" . ET_TBL_CHOICE_TYPES . "` (`choice_id`, `type_id`)
        SELECT `choice_id`, `establishment_type_id`
        FROM `tbl_choices`
        WHERE `establishment_type_id` IS NOT NULL AND `establishment_type_id` > 0
      ");
    }
  }

  // Drop empty legacy award↔type map (superseded by tbl_establishment_type_awards).
  if (et_table_exists($conn, 'tbl_question_establishment_types')) {
    $cnt = 0;
    if ($r = $conn->query('SELECT COUNT(*) AS c FROM `tbl_question_establishment_types`')) {
      $cnt = (int) ($r->fetch_assoc()['c'] ?? 0);
      $r->free();
    }
    if ($cnt === 0) {
      $conn->query('DROP TABLE IF EXISTS `tbl_question_establishment_types`');
    }
  }
}

function et_parse_type_ids($raw): array
{
  if ($raw === null || $raw === '') {
    return [];
  }
  if (is_string($raw)) {
    $trim = trim($raw);
    if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
      $decoded = json_decode($trim, true);
      if (is_array($decoded)) {
        $raw = $decoded;
      } else {
        $raw = preg_split('/[,\s]+/', $trim) ?: [];
      }
    } else {
      $raw = preg_split('/[,\s]+/', $trim) ?: [];
    }
  }
  if (!is_array($raw)) {
    $raw = [$raw];
  }
  $ids = [];
  foreach ($raw as $v) {
    if (is_array($v)) {
      $v = $v['type_id'] ?? $v['id'] ?? 0;
    }
    $n = (int) $v;
    if ($n > 0) {
      $ids[$n] = true;
    }
  }
  return array_map('intval', array_keys($ids));
}

function et_types_belong_to_event(mysqli $conn, array $typeIds, int $eventId): bool
{
  $typeIds = et_ints($typeIds);
  if ($typeIds === [] || $eventId <= 0) {
    return false;
  }
  $allowed = et_type_ids_for_event($conn, $eventId);
  foreach ($typeIds as $tid) {
    if (!in_array($tid, $allowed, true)) {
      return false;
    }
  }
  return true;
}

/** Awards allowed by ANY of the selected types (union). */
function et_awards_match_establishment_types(mysqli $conn, array $questionIds, array $typeIds, int $eventId): bool
{
  $questionIds = et_ints($questionIds);
  $typeIds = et_ints($typeIds);
  if ($questionIds === [] || $typeIds === [] || $eventId <= 0) {
    return false;
  }
  $phQ = implode(',', array_fill(0, count($questionIds), '?'));
  $phT = implode(',', array_fill(0, count($typeIds), '?'));
  $sql = '
    SELECT COUNT(DISTINCT x.question_id) AS cnt
    FROM ' . ET_TBL_TYPES_X_AWARDS . ' x
    INNER JOIN ' . ET_TBL_QUESTIONS . ' q ON q.question_id = x.question_id
    INNER JOIN ' . ET_TBL_CATEGORIES . ' c ON c.category_id = q.category_id AND c.event_id = ?
    WHERE x.type_id IN (' . $phT . ') AND x.question_id IN (' . $phQ . ')
  ';
  $stmt = $conn->prepare($sql);
  $types = 'i' . str_repeat('i', count($typeIds) + count($questionIds));
  $params = array_merge([$eventId], $typeIds, $questionIds);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
  $stmt->close();
  return $cnt === count($questionIds);
}

function et_fetch_awards_for_types(mysqli $conn, array $typeIds, int $eventId): array
{
  $typeIds = et_ints($typeIds);
  if ($typeIds === [] || $eventId <= 0) {
    return [];
  }
  $hasIsActive = false;
  if ($cols = $conn->query('SHOW COLUMNS FROM tbl_questions')) {
    while ($c = $cols->fetch_assoc()) {
      if (strtolower((string) ($c['Field'] ?? '')) === 'is_active') {
        $hasIsActive = true;
        break;
      }
    }
    $cols->free();
  }

  $ph = implode(',', array_fill(0, count($typeIds), '?'));
  $sql = '
    SELECT DISTINCT q.question_id, q.question_name, c.category_id, c.category_name
    FROM ' . ET_TBL_TYPES_X_AWARDS . ' x
    INNER JOIN ' . ET_TBL_QUESTIONS . ' q ON q.question_id = x.question_id
    INNER JOIN ' . ET_TBL_CATEGORIES . ' c ON c.category_id = q.category_id AND c.event_id = ?
    WHERE x.type_id IN (' . $ph . ')
  ';
  if ($hasIsActive) {
    $sql .= ' AND q.is_active = 1';
  }
  $sql .= ' ORDER BY c.category_name ASC, q.question_name ASC';

  $stmt = $conn->prepare($sql);
  $types = 'i' . str_repeat('i', count($typeIds));
  $params = array_merge([$eventId], $typeIds);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'question_id'   => (int) $row['question_id'],
      'question_name' => (string) ($row['question_name'] ?? 'Award'),
      'category_id'   => $row['category_id'] !== null ? (int) $row['category_id'] : null,
      'category_name' => $row['category_name'] ?? null,
    ];
  }
  $stmt->close();
  return $out;
}

function et_get_nomination_type_ids(mysqli $conn, int $nominationId): array
{
  et_ensure_m2m_schema($conn);
  $ids = [];
  $st = $conn->prepare('SELECT type_id FROM `' . ET_TBL_NOM_TYPES . '` WHERE nomination_id = ? ORDER BY type_id ASC');
  $st->bind_param('i', $nominationId);
  $st->execute();
  $res = $st->get_result();
  while ($row = $res->fetch_assoc()) {
    $ids[] = (int) $row['type_id'];
  }
  $st->close();
  if ($ids !== []) {
    return $ids;
  }
  // Fallback: legacy column
  if ($r = $conn->query("SHOW COLUMNS FROM `tbl_nominations` LIKE 'establishment_type_id'")) {
    if ($r->num_rows > 0) {
      $st = $conn->prepare('SELECT establishment_type_id FROM tbl_nominations WHERE nomination_id = ? LIMIT 1');
      $st->bind_param('i', $nominationId);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();
      $st->close();
      $tid = (int) ($row['establishment_type_id'] ?? 0);
      if ($tid > 0) {
        $ids[] = $tid;
      }
    }
    $r->free();
  }
  return $ids;
}

function et_get_choice_type_ids(mysqli $conn, int $choiceId): array
{
  et_ensure_m2m_schema($conn);
  $ids = [];
  $st = $conn->prepare('SELECT type_id FROM `' . ET_TBL_CHOICE_TYPES . '` WHERE choice_id = ? ORDER BY type_id ASC');
  $st->bind_param('i', $choiceId);
  $st->execute();
  $res = $st->get_result();
  while ($row = $res->fetch_assoc()) {
    $ids[] = (int) $row['type_id'];
  }
  $st->close();
  if ($ids !== []) {
    return $ids;
  }
  if ($r = $conn->query("SHOW COLUMNS FROM `tbl_choices` LIKE 'establishment_type_id'")) {
    if ($r->num_rows > 0) {
      $st = $conn->prepare('SELECT establishment_type_id FROM tbl_choices WHERE choice_id = ? LIMIT 1');
      $st->bind_param('i', $choiceId);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();
      $st->close();
      $tid = (int) ($row['establishment_type_id'] ?? 0);
      if ($tid > 0) {
        $ids[] = $tid;
      }
    }
    $r->free();
  }
  return $ids;
}

function et_set_nomination_types(mysqli $conn, int $nominationId, array $typeIds): void
{
  et_ensure_m2m_schema($conn);
  $typeIds = et_ints($typeIds);
  $del = $conn->prepare('DELETE FROM `' . ET_TBL_NOM_TYPES . '` WHERE nomination_id = ?');
  $del->bind_param('i', $nominationId);
  $del->execute();
  $del->close();

  if ($typeIds !== []) {
    $ins = $conn->prepare('INSERT INTO `' . ET_TBL_NOM_TYPES . '` (nomination_id, type_id) VALUES (?, ?)');
    foreach ($typeIds as $tid) {
      $ins->bind_param('ii', $nominationId, $tid);
      $ins->execute();
    }
    $ins->close();
  }

  // Keep legacy column as primary (first) type for older reports/exports.
  if ($r = $conn->query("SHOW COLUMNS FROM `tbl_nominations` LIKE 'establishment_type_id'")) {
    if ($r->num_rows > 0) {
      $primary = $typeIds[0] ?? null;
      if ($primary === null) {
        $conn->query('UPDATE tbl_nominations SET establishment_type_id = NULL WHERE nomination_id = ' . (int) $nominationId);
      } else {
        $st = $conn->prepare('UPDATE tbl_nominations SET establishment_type_id = ? WHERE nomination_id = ?');
        $st->bind_param('ii', $primary, $nominationId);
        $st->execute();
        $st->close();
      }
    }
    $r->free();
  }
}

function et_set_choice_types(mysqli $conn, int $choiceId, array $typeIds): void
{
  et_ensure_m2m_schema($conn);
  $typeIds = et_ints($typeIds);
  $del = $conn->prepare('DELETE FROM `' . ET_TBL_CHOICE_TYPES . '` WHERE choice_id = ?');
  $del->bind_param('i', $choiceId);
  $del->execute();
  $del->close();

  if ($typeIds !== []) {
    $ins = $conn->prepare('INSERT INTO `' . ET_TBL_CHOICE_TYPES . '` (choice_id, type_id) VALUES (?, ?)');
    foreach ($typeIds as $tid) {
      $ins->bind_param('ii', $choiceId, $tid);
      $ins->execute();
    }
    $ins->close();
  }

  if ($r = $conn->query("SHOW COLUMNS FROM `tbl_choices` LIKE 'establishment_type_id'")) {
    if ($r->num_rows > 0) {
      $primary = $typeIds[0] ?? null;
      if ($primary === null) {
        $conn->query('UPDATE tbl_choices SET establishment_type_id = NULL WHERE choice_id = ' . (int) $choiceId);
      } else {
        $st = $conn->prepare('UPDATE tbl_choices SET establishment_type_id = ? WHERE choice_id = ?');
        $st->bind_param('ii', $primary, $choiceId);
        $st->execute();
        $st->close();
      }
    }
    $r->free();
  }
}

/** @return list<array{type_id:int,type_name:string}> */
function et_type_rows_for_ids(mysqli $conn, array $typeIds): array
{
  $typeIds = et_ints($typeIds);
  if ($typeIds === []) {
    return [];
  }
  $ph = implode(',', array_fill(0, count($typeIds), '?'));
  $stmt = $conn->prepare('SELECT type_id, type_name FROM ' . ET_TBL_TYPES . ' WHERE type_id IN (' . $ph . ') ORDER BY type_name ASC');
  $types = str_repeat('i', count($typeIds));
  $stmt->bind_param($types, ...$typeIds);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'type_id'   => (int) $row['type_id'],
      'type_name' => (string) $row['type_name'],
    ];
  }
  $stmt->close();
  return $out;
}

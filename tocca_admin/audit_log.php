<?php
declare(strict_types=1);

function audit_get_client_ip(): string {
  foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = explode(',', (string)$_SERVER[$k])[0];
      return trim($ip);
    }
  }
  return '0.0.0.0';
}

function audit_diff_assoc(array $old, array $new): array {
  $changed = [];
  foreach ($new as $k => $v) {
    $ov = $old[$k] ?? null;
    if ($ov !== $v) {
      $changed[$k] = ['old' => $ov, 'new' => $v];
    }
  }
  return ['changed' => $changed];
}

/**
 * Older DBs stored action as a small ENUM, so values like export/generate_qr were saved as blank.
 */
function audit_log_schema_ensure(mysqli $conn): void {
  static $done = false;
  if ($done) {
    return;
  }
  $done = true;

  try {
    $res = $conn->query("SHOW COLUMNS FROM tbl_admin_audit_log LIKE 'action'");
    $col = $res ? $res->fetch_assoc() : null;
    if (!$col) {
      return;
    }
    $type = strtolower((string) ($col['Type'] ?? ''));
    if (str_starts_with($type, 'enum(')) {
      $conn->query("ALTER TABLE tbl_admin_audit_log MODIFY COLUMN action VARCHAR(64) NOT NULL DEFAULT ''");
    }
    audit_log_backfill_blank_actions($conn);
  } catch (Throwable $e) {
    error_log('audit_log_schema_ensure failed: ' . $e->getMessage());
  }
}

function audit_infer_action_from_row(string $module, ?array $details): string {
  $d = is_array($details) ? $details : [];
  if ($module === 'twg_evaluation') {
    if (!empty($d['filename']) && empty($d['format'])) {
      return 'import';
    }
    if (!empty($d['format']) || isset($d['rows'])) {
      return 'export';
    }
    if (array_key_exists('saved', $d)) {
      return 'save_sheet';
    }
    return 'export';
  }
  if ($module === 'establishment_qr') {
    if (!empty($d['format']) || ($d['scope'] ?? '') === 'establishment_qr_poster') {
      return 'export';
    }
    return !empty($d['forced']) ? 'regenerate_qr' : 'generate_qr';
  }
  if ($module === 'nomination_qr') {
    if (!empty($d['format']) || str_contains((string) ($d['scope'] ?? ''), 'qr')) {
      return 'export';
    }
    return 'generate_qr';
  }
  if ($module === 'choices') {
    if (array_key_exists('on_ballot', $d) || array_key_exists('top10_count', $d)) {
      return 'release_to_ballot';
    }
    if (isset($d['old']) && !isset($d['new'])) {
      return 'delete';
    }
    if (isset($d['new']) && !isset($d['old'])) {
      return 'create';
    }
    return 'update';
  }
  if ($module === 'communications') {
    return !empty($d['ok_count']) || array_key_exists('fail_count', $d) ? 'send_email_bulk' : 'send_email';
  }
  if ($module === 'auth') {
    return 'login';
  }
  if ($module === 'import') {
    return 'import';
  }
  if ($module === 'registrations') {
    return (string) ($d['to'] ?? $d['note'] ?? 'update');
  }
  return 'update';
}

function audit_log_backfill_blank_actions(mysqli $conn): void {
  $res = $conn->query("SELECT log_id, module, details_json FROM tbl_admin_audit_log WHERE action = '' OR action IS NULL");
  if (!$res) {
    return;
  }
  $upd = $conn->prepare('UPDATE tbl_admin_audit_log SET action = ? WHERE log_id = ?');
  if (!$upd) {
    return;
  }
  while ($row = $res->fetch_assoc()) {
    $details = json_decode((string) ($row['details_json'] ?? ''), true);
    $action = audit_infer_action_from_row((string) ($row['module'] ?? ''), is_array($details) ? $details : null);
    if ($action === '') {
      continue;
    }
    $logId = (int) $row['log_id'];
    $upd->bind_param('si', $action, $logId);
    $upd->execute();
  }
  $upd->close();
}

/**
 * @param mysqli $conn
 * @param string $module       
 * @param string $action       
 * @param string $entityType   
 * @param string|int|null $entityId
 * @param array $details       
 */
function audit_log(mysqli $conn, string $module, string $action, string $entityType, $entityId, array $details = []): void {
  try {
    audit_log_schema_ensure($conn);
    if (session_status() !== PHP_SESSION_ACTIVE) {
      require_once __DIR__ . '/session_bootstrap.php';
    }

    $adminId   = $_SESSION['admin_id']   ?? $_SESSION['user_id'] ?? null;
    $adminName = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? null;
    if ($adminId !== null && $adminId !== '') {
      $adminId = (int) $adminId;
    } else {
      $adminId = null;
    }
    $adminName = $adminName !== null && $adminName !== '' ? (string) $adminName : null;

    $ip   = audit_get_client_ip();
    $ua   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    // Always keep entity_id as string to avoid bind coercion issues
    $entityIdStr = isset($entityId) && $entityId !== '' ? (string)$entityId : null;
    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $sql = "INSERT INTO tbl_admin_audit_log
            (event_time, admin_id, admin_name, module, entity_type, entity_id, action, details_json, ip_address, user_agent)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      error_log('audit_log prepare failed: ' . $conn->error);
      return;
    }

    // admin_id = int, all others strings
    $stmt->bind_param(
      'issssssss',
      $adminId,          // i
      $adminName,        // s
      $module,           // s
      $entityType,       // s
      $entityIdStr,      // s
      $action,           // s
      $detailsJson,      // s
      $ip,               // s
      $ua                // s
    );
    if (!$stmt->execute()) {
      error_log('audit_log execute failed: ' . $stmt->error);
    }
    $stmt->close();
  } catch (Throwable $e) {
    error_log('audit_log failed: ' . $e->getMessage());
  }
}

/**
 * Copy a registration-table action into the admin history log.
 */
function audit_log_registration(mysqli $conn, int $nominationId, string $action, array $details = []): void {
  if ($nominationId > 0 && empty($details['business_name']) && empty($details['choice_name'])) {
    try {
      require_once __DIR__ . '/includes/admin_schema.php';
      if (admin_schema_column_exists($conn, 'tbl_nominations', 'business_name')) {
        $st = $conn->prepare('SELECT business_name FROM tbl_nominations WHERE nomination_id = ? LIMIT 1');
        if ($st) {
          $st->bind_param('i', $nominationId);
          $st->execute();
          $row = $st->get_result()->fetch_assoc();
          $st->close();
          if (!empty($row['business_name'])) {
            $details['business_name'] = (string) $row['business_name'];
          }
        }
      }
      if (empty($details['business_name'])) {
        $st = $conn->prepare(
          "SELECT a.answer
           FROM tbl_nomination_answers a
           INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
           WHERE a.nomination_id = ?
             AND f.name IN ('official_business_name','business_name','company_name','company','business')
             AND TRIM(COALESCE(a.answer, '')) <> ''
           ORDER BY FIELD(f.name, 'official_business_name','business_name','company_name','company','business')
           LIMIT 1"
        );
        if ($st) {
          $st->bind_param('i', $nominationId);
          $st->execute();
          $row = $st->get_result()->fetch_assoc();
          $st->close();
          if (!empty($row['answer'])) {
            $details['business_name'] = (string) $row['answer'];
          }
        }
      }
    } catch (Throwable $e) {
      error_log('audit_log_registration label lookup failed: ' . $e->getMessage());
    }
  }
  audit_log($conn, 'registrations', $action, 'nomination', $nominationId > 0 ? $nominationId : null, $details);
}

/**
 * Log establishment QR poster generate / regenerate (admin choices screen).
 */
function audit_log_choice_qr(
    mysqli $conn,
    int $choiceId,
    string $choiceName,
    string $action,
    ?string $qrPath = null,
    bool $forced = false
): void {
    $eventId = 0;
    $eventName = null;
    $stmt = $conn->prepare(
        'SELECT e.event_id, e.event_name
         FROM tbl_choices ch
         LEFT JOIN tbl_events e ON e.event_id = ch.event_id
         WHERE ch.choice_id = ?
         LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('i', $choiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $eventId = (int) ($row['event_id'] ?? 0);
            $eventName = (string) ($row['event_name'] ?? '');
        }
    }

    $details = [
        'choice_name' => $choiceName,
        'path'        => $qrPath,
        'forced'      => $forced,
        'event_id'    => $eventId,
        'event_name'  => $eventName,
    ];
    if ($action === 'export' || $action === 'download') {
        $details['format'] = 'png';
        $details['scope']  = 'establishment_qr_poster';
    }

    audit_log($conn, 'establishment_qr', $action, 'choice', $choiceId, $details);
}

/**
 * Log registration form QR generate / download (admin events screen).
 */
function audit_log_nomination_qr(
    mysqli $conn,
    int $eventId,
    string $action,
    ?string $eventName = null,
    string $kind = 'register'
): void {
    if ($eventName === null || $eventName === '') {
        $stmt = $conn->prepare('SELECT event_name FROM tbl_events WHERE event_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $eventId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $eventName = (string) ($row['event_name'] ?? '');
        }
    }

    $details = [
        'event_id'   => $eventId,
        'event_name' => $eventName,
        'kind'       => $kind,
    ];
    if ($action === 'export' || $action === 'download') {
        $details['format'] = 'png';
        $details['scope']  = match ($kind) {
            'track' => 'tracking_qr',
            'vote'  => 'voting_qr',
            default => 'nomination_form_qr',
        };
    }

    audit_log($conn, 'nomination_qr', $action, 'event', $eventId, $details);
}

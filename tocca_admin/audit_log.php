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
 * @param mysqli $conn
 * @param string $module       
 * @param string $action       
 * @param string $entityType   
 * @param string|int|null $entityId
 * @param array $details       
 */
function audit_log(mysqli $conn, string $module, string $action, string $entityType, $entityId, array $details = []): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/session_bootstrap.php';
  }

  $adminId   = $_SESSION['admin_id']   ?? $_SESSION['user_id'] ?? null;
  $adminName = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? null;

  $ip   = audit_get_client_ip();
  $ua   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

  // Always keep entity_id as string to avoid bind coercion issues
  $entityIdStr = isset($entityId) ? (string)$entityId : null;
  $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  $sql = "INSERT INTO tbl_admin_audit_log
          (event_time, admin_id, admin_name, module, entity_type, entity_id, action, details_json, ip_address, user_agent)
          VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)";
  $stmt = $conn->prepare($sql);

  // admin_id = int, all others strings
  $stmt->bind_param(
    'issssssss',
    $adminId,          // i
    $adminName,        // s
    $module,           // s
    $entityType,       // s
    $entityIdStr,      // s (was 'i' before — can blank out next fields on some stacks)
    $action,           // s
    $detailsJson,      // s
    $ip,               // s
    $ua                // s
  );
  $stmt->execute();
  $stmt->close();
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
 * Log nomination form QR generate / download (admin events screen).
 */
function audit_log_nomination_qr(
    mysqli $conn,
    int $eventId,
    string $action,
    ?string $eventName = null
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
    ];
    if ($action === 'export' || $action === 'download') {
        $details['format'] = 'png';
        $details['scope']  = 'nomination_form_qr';
    }

    audit_log($conn, 'nomination_qr', $action, 'event', $eventId, $details);
}

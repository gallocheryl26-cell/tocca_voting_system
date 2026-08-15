<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

require_once __DIR__ . '/includes/nomination_form_schema.php';
require_once __DIR__ . '/../nomination/nomination_field_helpers.php';
require_once __DIR__ . '/../nomination/rich_text_helpers.php';
require_once 'breadcrumb.php';
include 'get_logo.php';

nomination_form_schema_ensure($conn);

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- Audit helper (Registration Form module) ---------------- */
require_once __DIR__ . '/audit_log.php';

function nf_audit_log(mysqli $conn, array $row): void {
    $module      = (string) ($row['module'] ?? 'nomination_fields');
    $entity_type = (string) ($row['entity_type'] ?? 'nomination_field');
    $entity_id   = $row['entity_id'] ?? null;
    $action      = (string) ($row['action'] ?? '');
    $details     = is_array($row['details'] ?? null) ? $row['details'] : [];

    if ($action === '') {
        return;
    }

    try {
        audit_log($conn, $module, $action, $entity_type, $entity_id, $details);
    } catch (Throwable $e) {
        error_log('nf_audit_log failed: ' . $e->getMessage());
    }
}

/* ---------------- Helpers ---------------- */
function normalize_options_from_lines($raw) {
    if ($raw === null) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;
    // accept newline, comma, or pipe
    $parts = preg_split('/[\r\n,\|]+/u', $s);
    $parts = array_values(array_filter(array_map('trim', $parts), fn($v)=>$v!==''));
    return $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null;
}

function normalize_bullets_to_json(?string $raw): ?string {
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    $lines = array_values(array_filter(array_map('trim', $lines), fn($v)=>$v !== ''));
    return $lines ? json_encode($lines, JSON_UNESCAPED_UNICODE) : null;
}

function get_active_event_id(mysqli $conn): ?int {
    $id = null;
    if ($r = $conn->query("SELECT event_id FROM tbl_events WHERE is_active=1 ORDER BY year DESC, event_id DESC LIMIT 1")) {
        if ($r->num_rows) $id = (int)$r->fetch_assoc()['event_id'];
        $r->free();
    }
    if (!$id) {
        if ($r = $conn->query("SELECT event_id FROM tbl_events ORDER BY year DESC, event_id DESC LIMIT 1")) {
            if ($r->num_rows) $id = (int)$r->fetch_assoc()['event_id'];
            $r->free();
        }
    }
    return $id ?: null;
}

function fetch_texts_row(mysqli $conn, string $section, ?int $eventId): ?array {
    $stmt = $conn->prepare("
      SELECT * FROM tbl_nomination_texts
      WHERE section=? AND (event_id = ? OR event_id IS NULL)
      ORDER BY (event_id IS NULL) ASC
      LIMIT 1
    ");
    $eid = $eventId ?? 0;
    $stmt->bind_param("si", $section, $eid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* ====== NEW: machine-name helpers ====== */
function machine_name_from_label(string $label): string {
    $s = trim($label);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/i', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s, '_');
    return $s !== '' ? $s : 'field';
}

function ensure_unique_field_name(mysqli $conn, string $base, ?int $excludeId = null): string {
    $name = $base;
    $i = 1;
    while (true) {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT 1 FROM tbl_nomination_fields WHERE name = ? AND id <> ? LIMIT 1");
            $stmt->bind_param('si', $name, $excludeId);
        } else {
            $stmt = $conn->prepare("SELECT 1 FROM tbl_nomination_fields WHERE name = ? LIMIT 1");
            $stmt->bind_param('s', $name);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() ? true : false;
        $stmt->close();
        if (!$exists) return $name;
        $i++;
        $name = $base . '_' . $i;
    }
}

/* ---------------- Flash ---------------- */
$flash = null;

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $activeEventId = get_active_event_id($conn);
    $token = $_POST['csrf_token'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }
        $flash = ['type'=>'danger','msg'=>'Invalid CSRF token.'];
    } else {
        $action = $_POST['form_action'] ?? '';

        if ($action === 'reorder_fields') {
            header('Content-Type: application/json; charset=utf-8');
            $order = json_decode((string) ($_POST['order_json'] ?? '[]'), true);
            if (!is_array($order)) {
                echo json_encode(['success' => false, 'message' => 'Invalid order payload.']);
                exit;
            }
            $ids = array_values(array_filter(array_map('intval', $order), static fn($v) => $v > 0));
            $ok  = nf_reorder_fields($conn, $ids);
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Order saved.' : 'Failed to save order.']);
            exit;
        }

        if ($action === 'renumber_order') {
            $n = nf_renumber_sort_orders($conn, (int) ($activeEventId ?? 0));
            $flash = ['type' => 'success', 'msg' => "Renumbered {$n} field(s)."];
        } elseif ($action === 'save') {
            // Save/Update field
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $label = trim($_POST['label'] ?? '');
            $type = trim($_POST['type'] ?? 'text');
            $options_raw = $_POST['options'] ?? null;
            $is_required = !empty($_POST['is_required']) ? 1 : 0;
            $is_active = !empty($_POST['is_active']) ? 1 : 0;
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            if (($id === null || $id <= 0) && $sort_order <= 0) {
                $sort_order = nf_next_sort_order($conn);
            }
            $help_text   = trim((string) ($_POST['help_text'] ?? ''));
            $placeholder = trim((string) ($_POST['placeholder'] ?? ''));
            $field_width = (($_POST['field_width'] ?? 'half') === 'full') ? 'full' : 'half';
            $profile_role = trim((string) ($_POST['profile_role'] ?? 'custom'));
            $allowedRoles = array_keys(nomination_form_profile_roles());
            if (!in_array($profile_role, $allowedRoles, true)) {
                $profile_role = 'custom';
            }
            if ($profile_role === 'custom') {
                $suggestedRole = nomination_form_suggest_profile_role($label);
                if ($suggestedRole && in_array($suggestedRole, $allowedRoles, true)) {
                    $profile_role = $suggestedRole;
                }
            }
            // Mayor's permit and logo are always image file uploads (never free text).
            if ($profile_role === 'mayor_permit' || $profile_role === 'logo') {
                $type = 'file';
            }
            $validation_json = nf_normalize_validation_input($_POST, $type);
            if ($profile_role === 'mayor_permit') {
                $va = json_decode((string) ($validation_json ?? ''), true);
                if (!is_array($va)) {
                    $va = [];
                }
                $va['accept'] = '.png,.jpg,.jpeg,.webp';
                $validation_json = json_encode($va, JSON_UNESCAPED_UNICODE);
                if ($placeholder !== '' && preg_match('/MP-\d{4}|permit\s*no|permit\s*number/i', $placeholder)) {
                    $placeholder = '';
                }
                if ($help_text === '') {
                    $help_text = "Upload a clear photo of your Mayor's Permit (PNG, JPG, or WEBP).";
                }
            }
            $field_event_id = nf_field_event_id_from_post($_POST, $activeEventId);

            if ($label === '') {
                $flash = ['type'=>'warning','msg'=>'Label is required.'];
            } else {
                $options_json = null;
                if (in_array($type, ['select','checkbox','radio'], true)) {
                    $options_json = normalize_options_from_lines($options_raw);
                }

                if ($id !== null && $id > 0) {
                    // fetch old row for audit
                    $oldRow = null;
                    $g = $conn->prepare("SELECT * FROM tbl_nomination_fields WHERE id=? LIMIT 1");
                    $g->bind_param('i', $id);
                    $g->execute();
                    $oldRow = $g->get_result()->fetch_assoc();
                    $g->close();

                    // Determine/ensure unique name
                    $curName = '';
                    $check = $conn->prepare("SELECT name FROM tbl_nomination_fields WHERE id = ? LIMIT 1");
                    $check->bind_param('i', $id);
                    $check->execute();
                    if ($r = $check->get_result()->fetch_assoc()) $curName = (string)($r['name'] ?? '');
                    $check->close();

                    if ($curName === '') {
                        $base = machine_name_from_label($label);
                        $name = ensure_unique_field_name($conn, $base, $id);
                    } else {
                        $name = ensure_unique_field_name($conn, $curName, $id);
                    }

                    $setSql = 'name=?, label=?, `type`=?, options = NULLIF(?, \'\'), is_required=?, is_active=?, sort_order=?';
                    $bindTypes = 'ssssiii';
                    $bindVals  = [$name, $label, $type, $options_json, $is_required, $is_active, $sort_order];
                    if (nf_column_exists($conn, 'event_id')) {
                        $setSql .= ', event_id=?';
                        $bindTypes .= 'i';
                        $bindVals[] = $field_event_id;
                    }
                    if (nf_column_exists($conn, 'help_text')) {
                        $setSql .= ', help_text=?';
                        $bindTypes .= 's';
                        $bindVals[] = $help_text !== '' ? $help_text : null;
                    }
                    if (nf_column_exists($conn, 'placeholder')) {
                        $setSql .= ', placeholder=?';
                        $bindTypes .= 's';
                        $bindVals[] = $placeholder !== '' ? $placeholder : null;
                    }
                    if (nf_column_exists($conn, 'validation_json')) {
                        $setSql .= ', validation_json=?';
                        $bindTypes .= 's';
                        $bindVals[] = $validation_json;
                    }
                    if (nf_column_exists($conn, 'profile_role')) {
                        $setSql .= ', profile_role=?';
                        $bindTypes .= 's';
                        $bindVals[] = $profile_role;
                    }
                    if (nf_column_exists($conn, 'field_width')) {
                        $setSql .= ', field_width=?';
                        $bindTypes .= 's';
                        $bindVals[] = $field_width;
                    }
                    $setSql .= ', updated_at=CURRENT_TIMESTAMP WHERE id=?';
                    $bindTypes .= 'i';
                    $bindVals[] = $id;
                    $st = $conn->prepare('UPDATE tbl_nomination_fields SET ' . $setSql);
                    $st->bind_param($bindTypes, ...$bindVals);
                    $ok = $st->execute();
                    $st->close();

                    if ($ok) {
                        $newRow = [
                            'id'          => (int)$id,
                            'label'       => $label,
                            'name'        => $name,
                            'type'        => $type,
                            'options'     => $options_json ? json_decode($options_json, true) : null,
                            'is_required' => (int)$is_required,
                            'is_active'   => (int)$is_active,
                            'sort_order'  => (int)$sort_order,
                        ];

                        // diff
                        $changed = [];
                        if ($oldRow) {
                            $cmp = ['label','name','type','is_required','is_active','sort_order'];
                            foreach ($cmp as $k) {
                                $ov = isset($oldRow[$k]) ? (string)$oldRow[$k] : null;
                                $nv = isset($newRow[$k]) ? (string)$newRow[$k] : null;
                                if ($ov !== $nv) $changed[$k] = ['old' => $oldRow[$k] ?? null, 'new' => $newRow[$k] ?? null];
                            }
                            $oldOpts = $oldRow['options'] ? json_decode($oldRow['options'], true) : null;
                            $newOpts = $newRow['options'];
                            if (json_encode($oldOpts) !== json_encode($newOpts)) {
                                $changed['options'] = ['old' => $oldOpts, 'new' => $newOpts];
                            }
                        }

                        nf_audit_log($conn, [
                            'action'    => 'update',
                            'entity_id' => (int)$id,
                            'details'   => [
                              'event_id' => $activeEventId, 
                                'old'   => $oldRow ? [
                                    'id'          => (int)$oldRow['id'],
                                    'label'       => $oldRow['label'],
                                    'name'        => $oldRow['name'],
                                    'type'        => $oldRow['type'],
                                    'options'     => $oldRow['options'] ? json_decode($oldRow['options'], true) : null,
                                    'is_required' => (int)$oldRow['is_required'],
                                    'is_active'   => (int)$oldRow['is_active'],
                                    'sort_order'  => (int)$oldRow['sort_order'],
                                ] : null,
                                'new'   => $newRow,
                                'diff'  => ['changed' => $changed]
                            ]
                        ]);
                    }

                    $flash = $ok ? ['type'=>'success','msg'=>'Question updated.'] : ['type'=>'danger','msg'=>'Update failed: '.$conn->error];
                } else {
                    // insert
                    $base = machine_name_from_label($label);
                    $name = ensure_unique_field_name($conn, $base, null);

                    $cols = ['name', 'label', '`type`', 'options', 'is_required', 'is_active', 'sort_order'];
                    $vals = ['?', '?', '?', 'NULLIF(?, \'\')', '?', '?', '?'];
                    $bindTypes = 'ssssiii';
                    $bindVals  = [$name, $label, $type, $options_json, $is_required, $is_active, $sort_order];
                    if (nf_column_exists($conn, 'event_id')) {
                        $cols[] = 'event_id';
                        $vals[] = '?';
                        $bindTypes .= 'i';
                        $bindVals[] = $field_event_id;
                    }
                    if (nf_column_exists($conn, 'help_text')) {
                        $cols[] = 'help_text';
                        $vals[] = '?';
                        $bindTypes .= 's';
                        $bindVals[] = $help_text !== '' ? $help_text : null;
                    }
                    if (nf_column_exists($conn, 'placeholder')) {
                        $cols[] = 'placeholder';
                        $vals[] = '?';
                        $bindTypes .= 's';
                        $bindVals[] = $placeholder !== '' ? $placeholder : null;
                    }
                    if (nf_column_exists($conn, 'validation_json')) {
                        $cols[] = 'validation_json';
                        $vals[] = '?';
                        $bindTypes .= 's';
                        $bindVals[] = $validation_json;
                    }
                    if (nf_column_exists($conn, 'profile_role')) {
                        $cols[] = 'profile_role';
                        $vals[] = '?';
                        $bindTypes .= 's';
                        $bindVals[] = $profile_role;
                    }
                    if (nf_column_exists($conn, 'field_width')) {
                        $cols[] = 'field_width';
                        $vals[] = '?';
                        $bindTypes .= 's';
                        $bindVals[] = $field_width;
                    }
                    $cols[] = 'created_at';
                    $cols[] = 'updated_at';
                    $vals[] = 'CURRENT_TIMESTAMP';
                    $vals[] = 'CURRENT_TIMESTAMP';
                    $sqlIns = 'INSERT INTO tbl_nomination_fields (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
                    $st = $conn->prepare($sqlIns);
                    $st->bind_param($bindTypes, ...$bindVals);
                    $ok = $st->execute();
                    $newId = $ok ? (int)$conn->insert_id : 0;
                    $st->close();

                    if ($ok) {
                        nf_audit_log($conn, [
                            'action'    => 'create',
                            'entity_id' => $newId,
                            'details'   => [
                              'event_id' => $activeEventId,
                                'new' => [
                                    'id'          => $newId,
                                    'label'       => $label,
                                    'name'        => $name,
                                    'type'        => $type,
                                    'options'     => $options_json ? json_decode($options_json, true) : null,
                                    'is_required' => (int)$is_required,
                                    'is_active'   => (int)$is_active,
                                    'sort_order'  => (int)$sort_order,
                                ]
                            ]
                        ]);
                    }

                    $flash = $ok ? ['type'=>'success','msg'=>'Question added.'] : ['type'=>'danger','msg'=>'Insert failed: '.$conn->error];
                }
            }

        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                // fetch old
                $old = null;
                $s0 = $conn->prepare("SELECT * FROM tbl_nomination_fields WHERE id=? LIMIT 1");
                $s0->bind_param('i', $id);
                $s0->execute();
                $old = $s0->get_result()->fetch_assoc();
                $s0->close();

                $st = $conn->prepare("DELETE FROM tbl_nomination_fields WHERE id=?");
                $st->bind_param('i', $id);
                $ok = $st->execute();
                $st->close();

                if ($ok) {
                    nf_audit_log($conn, [
                        'action'    => 'delete',
                        'entity_id' => (int)$id,
                        'details'   => [
                          'event_id' => $activeEventId,
                            'old' => $old ? [
                                'id'          => (int)$old['id'],
                                'label'       => $old['label'],
                                'name'        => $old['name'],
                                'type'        => $old['type'],
                                'options'     => $old['options'] ? json_decode($old['options'], true) : null,
                                'is_required' => (int)$old['is_required'],
                                'is_active'   => (int)$old['is_active'],
                                'sort_order'  => (int)$old['sort_order'],
                            ] : null
                        ]
                    ]);
                }

                $flash = $ok ? ['type'=>'success','msg'=>'Question removed.'] : ['type'=>'danger','msg'=>'Delete failed: '.$conn->error];
            } else {
                $flash = ['type'=>'warning','msg'=>'Invalid id.'];
            }

        } elseif ($action === 'toggle_active') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT is_active, label FROM tbl_nomination_fields WHERE id = ? LIMIT 1");
                $stmt->bind_param("i",$id); $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $new = $row['is_active'] ? 0 : 1;
                    $s2 = $conn->prepare("UPDATE tbl_nomination_fields SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $s2->bind_param("ii",$new,$id);
                    $ok = $s2->execute();
                    $s2->close();

                    if ($ok) {
                        nf_audit_log($conn, [
                            'action'    => 'update',
                            'entity_id' => (int)$id,
                            'details'   => [
                              'event_id' => $activeEventId, 
                                'new' => ['label' => $row['label'] ?? null],
                                'diff' => ['changed' => [
                                    'is_active' => ['old' => (int)$row['is_active'], 'new' => (int)$new]
                                ]]
                            ]
                        ]);
                    }

                    $flash = $ok ? ['type'=>'success','msg'=>'Question updated.'] : ['type'=>'danger','msg'=>'Update failed: '.$conn->error];
                } else {
                    $flash = ['type'=>'warning','msg'=>'Not found.'];
                }
            } else {
                $flash = ['type'=>'warning','msg'=>'Invalid id.'];
            }

        } elseif ($action === 'save_copy') {
            // Save Intro/Instructions into tbl_nomination_texts
            $which      = ($_POST['which'] ?? '') === 'instructions' ? 'instructions' : 'intro';
            $event_id   = get_active_event_id($conn);
            $is_active  = !empty($_POST['copy_is_active']) ? 1 : 0;

            if ($which === 'intro') {
                $body_html  = trim($_POST['intro_body_html'] ?? '');

                // fetch old (if any) for audit
                $old = null;
                $q0 = $conn->prepare("SELECT body_html, is_active FROM tbl_nomination_texts WHERE event_id = ? AND section='intro' LIMIT 1");
                $q0->bind_param('i',$event_id); $q0->execute();
                $old = $q0->get_result()->fetch_assoc(); $q0->close();

                $sql = "
                  INSERT INTO tbl_nomination_texts (event_id, section, body_html, is_active)
                  VALUES (?, 'intro', ?, ?)
                  ON DUPLICATE KEY UPDATE
                    body_html=VALUES(body_html),
                    is_active=VALUES(is_active),
                    updated_at=CURRENT_TIMESTAMP
                ";
                $st = $conn->prepare($sql);
                $st->bind_param('isi', $event_id, $body_html, $is_active);
                $ok = $st->execute(); $st->close();

                if ($ok) {
                    $changed = [];
                    if ($old) {
                        if ((string)$old['body_html'] !== (string)$body_html) $changed['body_html'] = ['old'=>$old['body_html'],'new'=>$body_html];
                        if ((int)$old['is_active'] !== (int)$is_active) $changed['is_active'] = ['old'=>(int)$old['is_active'],'new'=>(int)$is_active];
                    } else {
                        $changed = ['body_html'=>['old'=>null,'new'=>$body_html],'is_active'=>['old'=>null,'new'=>(int)$is_active]];
                    }
                    nf_audit_log($conn, [
                        'module'      => 'nomination_fields',
                        'entity_type' => 'nomination_text',
                        'entity_id'   => null,
                        'action'      => 'update',
                        'details'     => [
                            'section'  => 'intro',
                            'event_id' => $event_id,
                            'diff'     => ['changed' => $changed]
                        ]
                    ]);
                }

                $flash = $ok ? ['type'=>'success','msg'=>'Introduction saved.'] : ['type'=>'danger','msg'=>'Failed to save introduction: '.$conn->error];

            } else {
                $title   = trim($_POST['inst_title'] ?? 'Instructions');
                $bulRaw  = $_POST['inst_bullets'] ?? '';
                $bulJSON = normalize_bullets_to_json($bulRaw);

                // fetch old (if any) for audit
                $old = null;
                $q0 = $conn->prepare("SELECT title, bullets_json, is_active FROM tbl_nomination_texts WHERE event_id = ? AND section='instructions' LIMIT 1");
                $q0->bind_param('i',$event_id); $q0->execute();
                $old = $q0->get_result()->fetch_assoc(); $q0->close();

                $sql = "
                  INSERT INTO tbl_nomination_texts (event_id, section, title, bullets_json, is_active)
                  VALUES (?, 'instructions', ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    title=VALUES(title),
                    bullets_json=VALUES(bullets_json),
                    is_active=VALUES(is_active),
                    updated_at=CURRENT_TIMESTAMP
                ";
                $st = $conn->prepare($sql);
                $st->bind_param('issi', $event_id, $title, $bulJSON, $is_active);
                $ok = $st->execute(); $st->close();

                if ($ok) {
                    $changed = [];
                    if ($old) {
                        if ((string)$old['title'] !== (string)$title) $changed['title'] = ['old'=>$old['title'],'new'=>$title];
                        if ((string)($old['bullets_json'] ?? '') !== (string)($bulJSON ?? '')) $changed['bullets_json'] = ['old'=>$old['bullets_json'],'new'=>$bulJSON];
                        if ((int)$old['is_active'] !== (int)$is_active) $changed['is_active'] = ['old'=>(int)$old['is_active'],'new'=>(int)$is_active];
                    } else {
                        $changed = [
                            'title'        => ['old'=>null,'new'=>$title],
                            'bullets_json' => ['old'=>null,'new'=>$bulJSON],
                            'is_active'    => ['old'=>null,'new'=>(int)$is_active],
                        ];
                    }
                    nf_audit_log($conn, [
                        'module'      => 'nomination_fields',
                        'entity_type' => 'nomination_text',
                        'entity_id'   => null,
                        'action'      => 'update',
                        'details'     => [
                            'section'  => 'instructions',
                            'event_id' => $event_id,
                            'diff'     => ['changed' => $changed]
                        ]
                    ]);
                }

                $flash = $ok ? ['type'=>'success','msg'=>'Instructions saved.'] : ['type'=>'danger','msg'=>'Failed to save instructions: '.$conn->error];
            }
        } else {
            $flash = ['type'=>'warning','msg'=>'Unknown action.'];
        }
    }

    if ($flash) $_SESSION['flash'] = $flash;
    header('Location: ' . strtok($_SERVER["REQUEST_URI"],'?'));
    exit;
}

/* ---------------- Read flash ---------------- */
$flash = null;
if (!empty($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

/* ---------------- Edit prefill ---------------- */
$editing = false;
$edit_row = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT * FROM tbl_nomination_fields WHERE id = ? LIMIT 1");
        $stmt->bind_param("i",$id); $stmt->execute();
        $edit_row = $stmt->get_result()->fetch_assoc();
        if ($edit_row) {
            $edit_row = nf_enrich_field($edit_row);
            $edit_row['options_text'] = !empty($edit_row['options_arr'])
                ? implode("\n", $edit_row['options_arr']) : '';
            $va = $edit_row['validation_arr'] ?? [];
            $edit_row['val_max_length'] = (string) ($va['max_length'] ?? '');
            $edit_row['val_pattern']    = (string) ($va['pattern'] ?? '');
            $edit_row['val_accept']     = (string) ($va['accept'] ?? '');
            $edit_row['val_min']        = isset($va['min']) ? (string) $va['min'] : '';
            $edit_row['val_max']        = isset($va['max']) ? (string) $va['max'] : '';
            $editing = true;
        }
        $stmt->close();
    }
}

/* ---------------- Load fields list ---------------- */
$activeEventId = get_active_event_id($conn) ?? 0;
$fields = nf_load_fields($conn, (int) $activeEventId, [
    'active_only'       => false,
    'maintenance_list'  => true,
]);
foreach ($fields as &$__nfRow) {
    $__nfRow['options_readable'] = !empty($__nfRow['options_arr'])
        ? implode(', ', $__nfRow['options_arr']) : '';
}
unset($__nfRow);

/* ---------------- Load copy for preview ---------------- */
$copyIntro = fetch_texts_row($conn, 'intro', $activeEventId);
$copyInst  = fetch_texts_row($conn, 'instructions', $activeEventId);

$instBulletsText = '';
if (!empty($copyInst['bullets_json'])) {
    $arr = json_decode($copyInst['bullets_json'], true);
    if (is_array($arr)) $instBulletsText = implode("\n", $arr);
}

$introSrc = (string)($copyIntro['body_html'] ?? '');
$introPreviewHtml = $introSrc ? md_to_html_basic($introSrc) : '<p class="text-muted mb-0">No introduction yet.</p>';

$instHealthBullets = [];
if (!empty($copyInst['bullets_json'])) {
    $arr = json_decode((string) $copyInst['bullets_json'], true);
    if (is_array($arr)) {
        $instHealthBullets = array_values(array_filter(array_map('trim', $arr)));
    }
}
$establishmentTypes = nf_establishment_types_for_event($conn, (int) $activeEventId);
$establishmentTypeCount = count($establishmentTypes);

$formHealth = nf_compute_health($fields, [
    'is_active'  => !empty($copyIntro['is_active']),
    'body_html'  => $introSrc,
], [
    'is_active' => !empty($copyInst['is_active']),
    'bullets'   => $instHealthBullets,
], $establishmentTypeCount);
$nomStats = nf_nomination_stats($conn, (int) $activeEventId);
$profileRoles = nomination_form_profile_roles();
$fieldTypeLabels = nf_field_type_labels();
$editEventScope = ($editing && !empty($edit_row['event_id'])) ? 'event' : 'all';
$nextSortOrder = nf_next_sort_order($conn);
$eventName = '';
if ($activeEventId > 0) {
    $evSt = $conn->prepare('SELECT event_name, year FROM tbl_events WHERE event_id = ? LIMIT 1');
    if ($evSt) {
        $evSt->bind_param('i', $activeEventId);
        $evSt->execute();
        $evSt->bind_result($evName, $evYear);
        if ($evSt->fetch()) {
            $eventName = trim((string) $evName) . ($evYear ? ' (' . $evYear . ')' : '');
        }
        $evSt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Registration Form Maintenance</title>
  <link rel="icon" type="image/png" href="<?= h($faviconPath ?? '') ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <?php if (file_exists(__DIR__ . '/inline_style.php')) include __DIR__ . '/inline_style.php'; ?>
  <style>
    .switch-wrapper { display: inline-flex; align-items: center; gap: 0.35rem; }
    .switch-wrapper .form-check-label { margin-bottom: 0; font-size: 0.8125rem; white-space: nowrap; }
    /* Custom questions table — column alignment & compact rows */
    .nom-fields-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    #fieldsTable {
      width: 100%;
      margin-bottom: 0;
    }
    #fieldsTable thead.table-light th {
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      vertical-align: middle;
      white-space: nowrap;
      padding: 0.55rem 0.65rem;
      border-bottom-width: 1px;
    }
    #fieldsTable tbody td {
      vertical-align: middle;
      padding: 0.5rem 0.65rem;
    }
    #fieldsTable .col-grip {
      width: 2.25rem;
      padding-left: 0.45rem;
      padding-right: 0.2rem;
      text-align: center;
    }
    #fieldsTable .col-question {
      width: auto;
      min-width: 8rem;
      word-break: break-word;
    }
    #fieldsTable .col-scope { width: 7.5rem; }
    #fieldsTable .col-review { width: 8.5rem; }
    #fieldsTable .col-type {
      width: 8.75rem;
      white-space: nowrap;
    }
    #fieldsTable .col-required,
    #fieldsTable .col-visible,
    #fieldsTable .col-order {
      text-align: center;
    }
    #fieldsTable .col-required { width: 4.5rem; }
    #fieldsTable .col-visible { width: 6.25rem; }
    #fieldsTable .col-order {
      width: 3.25rem;
      font-variant-numeric: tabular-nums;
      color: var(--bs-secondary-color);
      font-size: 0.875rem;
    }
    /* Shrink-to-fit last column so header sits over the button pair */
    #fieldsTable th.col-actions,
    #fieldsTable td.col-actions {
      width: 1%;
      white-space: nowrap;
      text-align: center;
      padding-left: 0.65rem;
      padding-right: 0.65rem;
    }
    #fieldsTable td.col-actions .admin-table-actions {
      display: inline-flex;
      flex-wrap: nowrap;
      justify-content: center;
      align-items: center;
      gap: 0.35rem;
      vertical-align: middle;
    }
    #fieldsTable .col-visible .switch-wrapper {
      justify-content: center;
    }
    #fieldsTable .col-visible .form-check-input {
      margin-left: 0;
    }
    html.dark-mode #fieldsTable thead.table-light th {
      background-color: rgba(255, 255, 255, 0.06) !important;
      color: #cbd5e1 !important;
      border-color: #334155 !important;
    }
    .toast-container-top-center { position: fixed; top: 16px; left: 50%; transform: translateX(-50%); z-index: 1100; width: auto; max-width: 90%; display: flex; justify-content: center; pointer-events: none; }
    .toast-container-top-center .toast { pointer-events: auto; min-width: 220px; }

    .preview-surface {
      background: #fff;
      color: #212529;
      border: 1px solid var(--bs-border-color, #dee2e6);
      border-radius: .5rem;
      padding: 1rem;
      transition: background-color .2s ease, color .2s ease, border-color .2s ease;
    }
    .preview-surface a { color: #0d6efd; }
    .preview-surface a:hover { color: #0a58ca; }
    html.dark-mode .preview-surface {
      background-color: #0f172a !important;
      color: #e2e8f0 !important;
      border-color: #334155 !important;
    }
    html.dark-mode .preview-surface a { color: #93c5fd !important; }
    html.dark-mode .preview-surface a:hover { color: #bfdbfe !important; }
    html.dark-mode .preview-surface .text-muted { color: #94a3b8 !important; }
    .section-actions { gap: .5rem; }
    .d-none { display: none !important; }
    .drag-handle { cursor: grab; color: #6c757d; padding: 0 .35rem; }
    .drag-handle:active { cursor: grabbing; }
    #nomPreviewFrame {
      width: 100%;
      min-height: 520px;
      border: 1px solid var(--bs-border-color, #dee2e6);
      border-radius: .5rem;
      background: #f8f9fa;
    }
    html.dark-mode #nomPreviewFrame {
      border-color: #334155 !important;
      background: #0b1220 !important;
    }
    html.dark-mode .nom-preview-card .card-body {
      background-color: rgba(15, 23, 42, 0.35);
    }
    .health-metric { font-size: .875rem; }
    tr.duplicate-order { background-color: rgba(255, 193, 7, 0.12); }
    .admin-guide { border-left: 4px solid #2563eb; background: rgba(37, 99, 235, 0.06); }
    html.dark-mode .admin-guide {
      background: rgba(37, 99, 235, 0.14) !important;
      border-left-color: #3b82f6 !important;
      color: #e2e8f0 !important;
    }
    html.dark-mode .fields-empty { color: #94a3b8 !important; }
    html.dark-mode .drag-handle { color: #94a3b8; }
    .step-badge { display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; border-radius: 50%; background: #2563eb; color: #fff; font-size: .8rem; font-weight: 600; margin-right: .5rem; flex-shrink: 0; }
    .nom-section-title { display: flex; align-items: center; font-weight: 600; }
    #fieldLabelPreview { font-size: .95rem; }
    .fields-empty { text-align: center; padding: 2.5rem 1rem; color: #6c757d; }
    /* Readable scope/status badges (Bootstrap 5.2 has no *-subtle utilities) */
    .nom-event-scope-badge {
      display: inline-block;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.35em 0.65em;
      border-radius: 0.375rem;
      color: #055160 !important;
      background-color: #cff4fc !important;
      border: 1px solid #9eeaf9 !important;
    }
    .nom-status-ok-badge {
      display: inline-block;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.35em 0.65em;
      border-radius: 0.375rem;
      color: #0a3622 !important;
      background-color: #d1e7dd !important;
      border: 1px solid #a3cfbb !important;
    }
    .nom-status-warn-badge {
      display: inline-block;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.35em 0.65em;
      border-radius: 0.375rem;
      color: #58151c !important;
      background-color: #f8d7da !important;
      border: 1px solid #f1aeb5 !important;
    }
    html.dark-mode .nom-event-scope-badge {
      color: #cff4fc !important;
      background-color: #0c4a6e !important;
      border-color: #0369a1 !important;
    }
    .nom-global-scope-badge {
      display: inline-block;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.35em 0.65em;
      border-radius: 0.375rem;
      color: #41464b !important;
      background-color: #e9ecef !important;
      border: 1px solid #ced4da !important;
    }
    html.dark-mode .nom-global-scope-badge {
      color: #e2e8f0 !important;
      background-color: #334155 !important;
      border-color: #475569 !important;
    }
    html.dark-mode .nom-status-ok-badge {
      color: #d1e7dd !important;
      background-color: #14532d !important;
      border-color: #166534 !important;
    }
    html.dark-mode .nom-status-warn-badge {
      color: #f8d7da !important;
      background-color: #7f1d1d !important;
      border-color: #991b1b !important;
    }
    @media (min-width: 1200px) {
      .nom-builder-row { align-items: flex-start; }
      .nom-preview-card { position: sticky; top: 5.5rem; z-index: 10; }
      #nomPreviewFrame {
        min-height: min(72vh, 720px);
        max-height: min(78vh, 780px);
      }
    }
    @media (max-width: 1199.98px) {
      #nomPreviewFrame { min-height: 420px; }
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
</head>
    <!-- Logout Confirmation Modal (same as before) -->
  <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="logoutModalLabel">Are you sure you want to logout?</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">You are currently logged in. If you log out, you will need to log in again.</div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmLogout">Logout</button>
      </div>
    </div></div>
  </div>
  <body class="sb-nav-fixed">
  <!-- top navbar -->
  <?php include __DIR__ . '/partials/admin_topnav.php'; ?>

<div id="layoutSidenav">
      <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

<div id="layoutSidenav_content">
      <main>
        <div class="container-fluid px-4">
                    <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">Registration Form</h1>
              <?php echo render_file_maintenance_breadcrumb([['label' => 'Registration Form']]); ?>
            </div>
          </div>
          <p class="text-muted mb-3">Set up what businesses see when they apply<?= $eventName !== '' ? ' for <strong>' . h($eventName) . '</strong>' : '' ?>. Changes apply to new registrations.</p>
          <?php echo render_admin_event_context(); ?>

          <div class="alert admin-guide mb-4" role="status">
            <div class="fw-semibold mb-1"><i class="bi bi-lightbulb me-1"></i> Quick guide</div>
            <ol class="mb-2 small ps-3">
              <li>Confirm <strong>business categories</strong> and linked awards (built-in field on the form).</li>
              <li>Write a <strong>welcome message</strong> and <strong>instructions</strong> (optional but recommended).</li>
              <li>Add or edit <strong>custom questions</strong> below — drag rows to change order.</li>
              <li>New questions apply to the <strong>active event</strong> shown above.</li>
              <li>Set <strong>Review group</strong> when a question collects email, mobile, permit, etc. (affects admin review only, not the public form).</li>
              <li>Check the <strong>preview</strong> before sharing the registration link with businesses.</li>
            </ol>
            <p class="mb-0 small text-muted"><i class="bi bi-info-circle me-1"></i> Submitted registrations are always saved per event. Questions you add here appear on the active event&rsquo;s registration form.</p>
          </div>

          <div class="row mb-4 g-3">
            <div class="col-md-8">
              <div class="card h-100 border-0 shadow-sm">
                <div class="card-body py-3">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <span class="fw-semibold"><i class="bi bi-clipboard-check me-1 text-primary"></i> Ready for applicants?</span>
                    <?php if ($formHealth['ok'] && empty($formHealth['warnings'])): ?>
                      <span class="badge bg-success">Looks good</span>
                    <?php elseif (!empty($formHealth['issues'])): ?>
                      <span class="badge bg-danger">Needs attention</span>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark">Review suggested</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($formHealth['issues'])): ?>
                    <ul class="list-unstyled mb-2">
                      <?php foreach ($formHealth['issues'] as $issue): ?>
                        <li class="text-danger small mb-1"><i class="bi bi-x-circle me-1"></i><?= h($issue) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <?php if (!empty($formHealth['warnings'])): ?>
                    <ul class="list-unstyled mb-2">
                      <?php foreach ($formHealth['warnings'] as $warn): ?>
                        <li class="small mb-1 text-secondary"><i class="bi bi-info-circle me-1"></i><?= h($warn) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <?php if (empty($formHealth['issues']) && empty($formHealth['warnings'])): ?>
                    <p class="text-success small mb-0"><i class="bi bi-check-circle me-1"></i> Your form is set up. <?= (int) ($formHealth['stats']['active'] ?? 0) ?> question(s) will show to applicants.</p>
                  <?php else: ?>
                    <p class="health-metric text-muted mb-0 small"><?= (int) ($formHealth['stats']['active'] ?? 0) ?> visible question(s) of <?= (int) ($formHealth['stats']['total'] ?? 0) ?> total</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card h-100 border-0 shadow-sm">
                <div class="card-body py-3">
                  <div class="fw-semibold small mb-2"><i class="bi bi-inbox me-1"></i> Applications received</div>
                  <div class="d-flex flex-wrap gap-2 health-metric mb-2">
                    <span class="badge bg-light text-dark border"><?= (int) $nomStats['total'] ?> total</span>
                    <span class="badge bg-light text-dark border"><?= (int) $nomStats['pending'] ?> waiting</span>
                    <?php if ((int) $nomStats['needs_info'] > 0): ?>
                      <span class="badge bg-warning text-dark"><?= (int) $nomStats['needs_info'] ?> need info</span>
                    <?php endif; ?>
                  </div>
                  <a class="btn btn-sm btn-outline-primary w-100" href="nominations.php"><i class="bi bi-box-arrow-up-right me-1"></i> Open registrations list</a>
                </div>
              </div>
            </div>
          </div>

          <p class="nom-section-title mb-2"><span class="step-badge">1</span> Message to applicants</p>

          <!-- ===== Introduction ===== -->
          <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="bi bi-chat-left-text me-1"></i>Welcome message</span>
              <div class="d-flex section-actions">
                <button type="button" id="btnIntroAdd" class="btn btn-sm btn-primary">Write message</button>
                <button type="button" id="btnIntroEdit" class="btn btn-sm btn-edit">Edit</button>
              </div>
            </div>
            <div class="card-body">
              <div id="introPreview" class="preview-surface mb-3">
                <?= $introPreviewHtml ?>
              </div>

              <form id="introForm" method="post" action="nomination_fields.php" class="row g-3 d-none">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="form_action" value="save_copy">
                <input type="hidden" name="which" value="intro">
                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="copy_is_active" id="intro_active"
                           <?= !empty($copyIntro['is_active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="intro_active">Show this welcome message on the form</label>
                  </div>
                </div>
                <div class="col-12">
                  <label class="form-label">
                    Introduction Text supports
                    <small class="text-muted">
                      (<strong>**bold**</strong>, <em>*italic*</em>, and links like
                      <code>(https://example.com)</code> or <code>https://example.com</code>)
                    </small>
                  </label>
                  <textarea class="form-control" name="intro_body_html" id="intro_body" rows="8"><?= h($introSrc) ?></textarea>
                  <div class="form-text">Blank line creates a new paragraph.</div>
                </div>
                <div class="col-12 d-flex gap-2">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save</button>
                  <button class="btn btn-outline-secondary" type="button" id="btnIntroCancel">Cancel</button>
                </div>
              </form>
            </div>
          </div>

          <!-- ===== Instructions ===== -->
          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="bi bi-list-ol me-1"></i>Step-by-step instructions</span>
              <div class="d-flex section-actions">
                <button type="button" id="btnInstAdd" class="btn btn-sm btn-primary">Add steps</button>
                <button type="button" id="btnInstEdit" class="btn btn-sm btn-edit">Edit</button>
              </div>
            </div>
            <div class="card-body">
              <div id="instPreview" class="preview-surface mb-3">
                <?php
                  $titleRaw   = (string)($copyInst['title'] ?? 'Instructions');
                  $titleTrim  = trim($titleRaw);
                  $normTitle  = rtrim($titleTrim, " \t\n\r\0\x0B:");
                  $showHeader = ($normTitle !== '' && strcasecmp($normTitle, 'Instructions') !== 0);
                ?>
                <?php if (!empty($copyInst['is_active']) && ($instBulletsText !== '' || $showHeader)): ?>
                  <?php if ($showHeader): ?>
                    <h2 class="h6 mb-2"><?= md_inline_basic($titleTrim) ?></h2>
                  <?php endif; ?>
                  <?php if ($instBulletsText !== ''): ?>
                    <ol class="mb-0">
                      <?php foreach (explode("\n", $instBulletsText) as $li): $li = trim($li); if($li==='') continue; ?>
                        <li><?= md_inline_basic($li) ?></li>
                      <?php endforeach; ?>
                    </ol>
                  <?php else: ?>
                    <p class="text-muted mb-0">No instructions yet.</p>
                  <?php endif; ?>
                <?php else: ?>
                  <p class="text-muted mb-0">No instructions yet.</p>
                <?php endif; ?>
              </div>

              <form id="instForm" method="post" action="nomination_fields.php" class="row g-3 d-none">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="form_action" value="save_copy">
                <input type="hidden" name="which" value="instructions">

                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="copy_is_active" id="inst_active"
                           <?= !empty($copyInst['is_active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="inst_active">Show these steps on the form</label>
                  </div>
                </div>

                <div class="col-12 col-md-6">
                  <label class="form-label">Section Header (optional)</label>
                  <input class="form-control" type="text" name="inst_title" id="inst_title" value="<?= h($copyInst['title'] ?? 'Instructions') ?>">
                </div>

                <div class="col-12">
                  <label class="form-label">What should they do? <span class="text-muted fw-normal">(one step per line)</span></label>
                  <textarea class="form-control" name="inst_bullets" id="inst_bullets" rows="8" placeholder="Prepare your Mayor's permit&#10;Upload a clear logo&#10;Choose your awards"><?= h($instBulletsText) ?></textarea>
                  <div class="form-text">Each line appears as a numbered step. You can use <strong>**bold**</strong> and paste links.</div>
                </div>

                <div class="col-12 d-flex gap-2">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save</button>
                  <button class="btn btn-outline-secondary" type="button" id="btnInstCancel">Cancel</button>
                </div>
              </form>
            </div>
          </div>

          <p class="nom-section-title mb-2 mt-2"><span class="step-badge">2</span> Questions &amp; preview</p>

          <?php if ($activeEventId > 0 && $eventName !== ''): ?>
          <div class="alert alert-info nom-event-context-banner py-2 mb-3 small" role="status">
            <i class="bi bi-calendar-event me-1"></i>
            You are editing the registration form for <strong><?= h($eventName) ?></strong>.
            New custom questions are added for <strong><?= h($eventName) ?></strong> only.
          </div>
          <?php endif; ?>

          <div class="row nom-builder-row g-4 mb-4">
            <div class="col-xl-7">
          <div class="card mb-3 border-primary border-opacity-25">
            <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
              <span><i class="bi bi-pin-angle me-1"></i> Built-in fields <span class="text-muted fw-normal small">(always on the form)</span></span>
              <a class="btn btn-sm btn-outline-primary" href="establishment_types.php"><i class="bi bi-diagram-3 me-1"></i> Manage business categories</a>
            </div>
            <div class="card-body py-3">
              <p class="small text-muted mb-3">
                These fields are not listed in the table below because they control <strong>award eligibility</strong>.
                Applicants can select <strong>one or more</strong> types; awards in Step 2 are the combined list for those types.
                Configure types and which awards each type can register for under Business Categories.
              </p>
              <div class="table-responsive nom-fields-table-wrap">
                <table class="table table-sm mb-0 align-middle nom-fields-table">
                  <thead class="table-light">
                    <tr>
                      <th scope="col">Field</th>
                      <th scope="col" class="text-nowrap">Answer type</th>
                      <th scope="col" class="text-center">Required</th>
                      <th scope="col">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td>
                        <div class="fw-medium">Business Category</div>
                        <div class="text-muted small">Select all that apply — filters which awards appear in Step 2</div>
                        <span class="badge nom-event-scope-badge mt-1">This event only</span>
                      </td>
                      <td class="text-nowrap">Checkboxes (multi-select)</td>
                      <td class="text-center"><span class="text-danger">Yes</span> <span class="text-muted small">(≥1)</span></td>
                      <td>
                        <?php if ($establishmentTypeCount > 0): ?>
                          <span class="badge nom-status-ok-badge">
                            <?= (int) $establishmentTypeCount ?> type<?= $establishmentTypeCount === 1 ? '' : 's' ?> for this event
                          </span>
                        <?php else: ?>
                          <span class="badge nom-status-warn-badge">None configured</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <?php if ($establishmentTypeCount > 0): ?>
                <details class="mt-3 small">
                  <summary class="text-primary" style="cursor:pointer">Show types applicants can choose</summary>
                  <ul class="mb-0 mt-2 ps-3">
                    <?php foreach ($establishmentTypes as $et): ?>
                      <li><?= h((string) ($et['type_name'] ?? '')) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php else: ?>
                <div class="alert alert-warning small mb-0 mt-3">
                  <i class="bi bi-exclamation-triangle me-1"></i>
                  Add at least one business category for this event before opening registrations.
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-primary js-reset-field-modal" id="addFieldBtn" data-bs-toggle="modal" data-bs-target="#fieldModal"><i class="bi bi-plus-lg"></i> Add question</button>
            <span class="text-muted small ms-1"><i class="bi bi-grip-vertical"></i> Drag rows to reorder custom questions</span>
          </div>

          <div class="card mb-4">
            <div class="card-header py-2">
              <span><i class="bi bi-ui-checks me-1"></i> Custom questions <span class="text-muted fw-normal small">(you add and edit these)</span></span>
            </div>

            <div class="card-body p-0">
              <?php if ($fields === []): ?>
                <div class="fields-empty">
                  <i class="bi bi-ui-checks-grid display-6 d-block mb-2 opacity-50"></i>
                  <p class="mb-2">No questions yet.</p>
                  <button type="button" class="btn btn-primary btn-sm js-reset-field-modal" data-bs-toggle="modal" data-bs-target="#fieldModal"><i class="bi bi-plus-lg"></i> Add your first question</button>
                </div>
              <?php else: ?>
              <div class="table-responsive nom-fields-table-wrap w-100">
                <table id="fieldsTable" class="table table-sm table-hover mb-0 align-middle nom-fields-table">
                  <thead class="table-light">
                    <tr>
                      <th class="col-grip" scope="col" aria-label="Reorder"></th>
                      <th class="col-question" scope="col">Question</th>
                      <th class="col-scope d-none d-lg-table-cell" scope="col">Scope</th>
                      <th class="col-review d-none d-xl-table-cell" scope="col">Review group</th>
                      <th class="col-type" scope="col">Answer type</th>
                      <th class="col-required" scope="col">Required</th>
                      <th class="col-visible" scope="col">Visible</th>
                      <th class="col-order d-none d-md-table-cell" scope="col" title="Order on the registration form">Order</th>
                      <th class="col-actions" scope="col">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $orderCounts = [];
                    foreach ($fields as $f) {
                        if (!empty($f['is_active'])) {
                            $o = (int) ($f['sort_order'] ?? 0);
                            $orderCounts[$o] = ($orderCounts[$o] ?? 0) + 1;
                        }
                    }
                    foreach ($fields as $f):
                      $dupOrder = !empty($f['is_active']) && (($orderCounts[(int)($f['sort_order'] ?? 0)] ?? 0) > 1);
                      $scopeHint = !empty($f['event_id']) ? 'Only this event' : 'All events';
                      $roleKey = (string) ($f['profile_role'] ?? 'custom');
                      $reviewGroupLabel = $profileRoles[$roleKey] ?? $profileRoles['custom'];
                    ?>
                      <tr data-field-id="<?= (int)$f['id'] ?>"<?= $dupOrder ? ' class="duplicate-order"' : '' ?> title="<?= h($scopeHint) ?>">
                        <td class="col-grip"><span class="drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical" aria-hidden="true"></i></span></td>
                        <td class="col-question">
                          <div class="fw-medium"><?= h($f['label']) ?></div>
                          <?php if (!empty($f['help_text'])): ?>
                            <div class="text-muted small"><?= h($f['help_text']) ?></div>
                          <?php endif; ?>
                          <span class="badge <?= !empty($f['event_id']) ? 'nom-event-scope-badge' : 'nom-global-scope-badge' ?> mt-1 d-lg-none">
                            <?= !empty($f['event_id']) ? 'This event only' : 'All events' ?>
                          </span>
                        </td>
                        <td class="col-scope d-none d-lg-table-cell">
                          <?php if (!empty($f['event_id'])): ?>
                            <span class="badge nom-event-scope-badge">This event only</span>
                          <?php else: ?>
                            <span class="badge nom-global-scope-badge">All events</span>
                          <?php endif; ?>
                        </td>
                        <td class="col-review d-none d-xl-table-cell small text-muted"><?= h($reviewGroupLabel) ?></td>
                        <td class="col-type"><?= h(nf_type_label((string) $f['type'])) ?></td>
                        <td class="col-required"><?= $f['is_required'] ? '<span class="text-danger">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                        <td class="col-visible">
                          <form method="post" action="nomination_fields.php" class="d-inline-block mb-0" data-field-id="<?= (int)$f['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="form_action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                            <div class="form-check form-switch switch-wrapper">
                              <input class="form-check-input active-switch" type="checkbox" role="switch"
                                     data-id="<?= (int)$f['id'] ?>" id="switch<?= (int)$f['id'] ?>"
                                     <?= $f['is_active'] ? 'checked' : '' ?>>
                              <label class="form-check-label" for="switch<?= (int)$f['id'] ?>"><?= $f['is_active'] ? 'On' : 'Off' ?></label>
                            </div>
                          </form>
                        </td>
                        <td class="col-order d-none d-md-table-cell"><?= (int)$f['sort_order'] ?></td>
                        <td class="col-actions">
                          <div class="admin-table-actions" role="group" aria-label="Actions for <?= h($f['label']) ?>">
                            <button type="button" class="btn btn-edit btn-sm js-edit-field" data-field-id="<?= (int)$f['id'] ?>">Edit</button>
                            <button type="button" class="btn btn-sm btn-danger js-delete-field"
                                    data-field-id="<?= (int)$f['id'] ?>"
                                    data-field-label="<?= h($f['label']) ?>">Delete</button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            </div>
          </div>
            </div>

            <div class="col-xl-5">
              <p class="nom-section-title mb-2 d-xl-none"><span class="step-badge">3</span> Preview</p>
              <div class="card mb-4 nom-preview-card">
                <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <span><i class="bi bi-eye me-1"></i> What applicants will see</span>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshPreview" title="Refresh preview"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="card-body p-2">
                  <iframe id="nomPreviewFrame" title="Registration form preview" src="about:blank"></iframe>
                  <a class="btn btn-sm btn-outline-primary w-100 mt-2" href="nomination_form_preview.php?event_id=<?= (int) $activeEventId ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i> Open preview in new tab</a>
                </div>
              </div>
            </div>
          </div>

          <!-- Modal: Add / Edit Field -->
          <div class="modal fade" id="fieldModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
              <form id="fieldForm" class="modal-content" method="post" action="nomination_fields.php">
                <div class="modal-header">
                  <h5 class="modal-title" id="fieldModalTitle"><?= $editing ? 'Edit question' : 'Add question' ?></h5>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="form_action" value="save">
                  <input type="hidden" name="id" id="field_id" value="<?= $editing ? (int)$edit_row['id'] : '' ?>">
                  <input type="hidden" name="sort_order" id="sort_order" value="<?= $editing ? (int)$edit_row['sort_order'] : (int)$nextSortOrder ?>">

                  <div class="mb-3">
                    <label class="form-label" for="label">Question text <span class="text-danger">*</span></label>
                    <input class="form-control" type="text" name="label" id="label" required
                           placeholder="e.g. Business name, Mobile number, Mayor's permit photo"
                           value="<?= $editing ? h($edit_row['label']) : '' ?>">
                    <div id="fieldLabelPreview" class="form-text mt-1 text-primary"></div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label" for="type">How should they answer?</label>
                    <select class="form-select" name="type" id="type">
                      <?php foreach ($fieldTypeLabels as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= ($editing && ($edit_row['type'] ?? '') === $k) ? 'selected' : '' ?>><?= h($v) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-3" id="optionsWrap" style="<?= ($editing && in_array($edit_row['type'] ?? '', ['select','checkbox','radio'])) ? '' : 'display:none;' ?>">
                    <label class="form-label">Answer choices <span class="text-muted fw-normal">(one per line)</span></label>
                    <textarea class="form-control" name="options" id="options" rows="5" placeholder="Sole Proprietorship&#10;Partnership&#10;Corporation"><?= $editing ? h($edit_row['options_text'] ?? '') : '' ?></textarea>
                  </div>

                  <div class="d-flex flex-wrap gap-3 mb-2">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_required" id="is_required" <?= ($editing && $edit_row['is_required']) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="is_required">Business must answer</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= ($editing ? ($edit_row['is_active'] ? 'checked' : '') : 'checked') ?>>
                      <label class="form-check-label" for="is_active">Show on public form</label>
                    </div>
                  </div>

                  <?php if (nf_column_exists($conn, 'event_id')): ?>
                  <input type="hidden" name="event_scope" id="event_scope" value="<?= h($editing ? $editEventScope : 'event') ?>">
                  <?php endif; ?>
                  <input type="hidden" name="placeholder" id="placeholder" value="<?= $editing ? h($edit_row['placeholder'] ?? '') : '' ?>">
                  <input type="hidden" name="help_text" id="help_text" value="<?= $editing ? h($edit_row['help_text'] ?? '') : '' ?>">
                  <input type="hidden" name="field_width" id="field_width" value="<?= $editing ? h($edit_row['field_width'] ?? 'half') : 'half' ?>">
                  <input type="hidden" name="profile_role" id="profile_role" value="<?= $editing ? h($edit_row['profile_role'] ?? 'custom') : 'custom' ?>">
                  <input type="hidden" name="val_max_length" id="val_max_length" value="<?= $editing ? h($edit_row['val_max_length'] ?? '') : '' ?>">
                  <input type="hidden" name="val_accept" id="val_accept" value="<?= $editing ? h($edit_row['val_accept'] ?? '') : '' ?>">
                  <input type="hidden" name="val_min" id="val_min" value="<?= $editing ? h($edit_row['val_min'] ?? '') : '' ?>">
                  <input type="hidden" name="val_max" id="val_max" value="<?= $editing ? h($edit_row['val_max'] ?? '') : '' ?>">

                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save question</button>
                </div>
              </form>
            </div>
          </div>

        </div>
      </main>
    </div>

  <!-- Logout modal (duplicate placeholder kept for layout parity) -->
  <div class="modal fade" id="logoutModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Logout</h5></div>
    <div class="modal-body">You are currently logged in. If you log out, you will need to log in again.</div>
    <div class="modal-footer"><a class="btn btn-danger" href="logout.php">Logout</a><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button></div>
  </div></div></div>

  <?php include __DIR__ . '/partials/admin_confirm_modal.php'; ?>

  <!-- Toast container -->
  <div class="toast-container-top-center">
    <div id="flashToast" class="toast" role="status" aria-live="polite" aria-atomic="true">
      <div class="toast-body" id="flashToastBody"></div>
    </div>
  </div>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>

<script>
  // -------- Tiny Markdown renderer for live preview --------
  function mdToHtmlBasic(src) {
    if (!src) return '<p class="text-muted mb-0">No introduction yet.</p>';
    const esc = s => String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    let x = esc(src);
    x = x.replace(/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/gi,(m,t,u)=>`<a href="${esc(u)}" target="_blank" rel="noopener noreferrer">${t}</a>`);
    x = x.replace(/(^|[\s(])((?:https?:\/\/|www\.)[^\s<)]+)(?=$|[\s<)])/gi,(m,lead,urlText)=>{const href=urlText.toLowerCase().startsWith('www.')?`https://${urlText}`:urlText;return `${lead}<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">${urlText}</a>`;});
    x = x.replace(/\*\*(.+?)\*\*/gs,'<strong>$1</strong>').replace(/\*(.+?)\*/gs,'<em>$1</em>');
    return x.split(/\r?\n\r?\n/).map(p=>`<p>${p.replace(/\r?\n/g,'<br>')}</p>`).join('\n');
  }
  function mdInlineBasic(src) {
    const esc = s => String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    let x = esc(src);
    x = x.replace(/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/gi,(m,t,u)=>`<a href="${esc(u)}" target="_blank" rel="noopener noreferrer">${t}</a>`);
    x = x.replace(/(^|[\s(])((?:https?:\/\/|www\.)[^\s<)]+)(?=$|[\s<)])/gi,(m,lead,urlText)=>{const href=urlText.toLowerCase().startsWith('www.')?`https://${urlText}`:urlText;return `${lead}<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">${urlText}</a>`;});
    x = x.replace(/\*\*(.+?)\*\*/gs,'<strong>$1</strong>').replace(/\*(.+?)\*/gs,'<em>$1</em>');
    return x;
  }

  (function(){
    // Intro
    const introPreview = document.getElementById('introPreview');
    const introForm    = document.getElementById('introForm');
    const btnIntroEdit = document.getElementById('btnIntroEdit');
    const btnIntroAdd  = document.getElementById('btnIntroAdd');
    const btnIntroCancel = document.getElementById('btnIntroCancel');
    const introBody    = document.getElementById('intro_body');
    const introActive  = document.getElementById('intro_active');
    let introStash = null;
    function renderIntro(){ introPreview.innerHTML = mdToHtmlBasic(introBody.value || ''); }
    btnIntroEdit?.addEventListener('click', () => { introStash = { body: introBody.value, active: introActive.checked }; introForm.classList.remove('d-none'); btnIntroEdit.classList.add('d-none'); btnIntroAdd.classList.add('d-none'); introBody.focus(); });
    btnIntroAdd?.addEventListener('click', () => { introStash = { body: introBody.value, active: introActive.checked }; introForm.classList.remove('d-none'); btnIntroEdit.classList.add('d-none'); btnIntroAdd.classList.add('d-none'); introBody.value = ''; introActive.checked = true; renderIntro(); introBody.focus(); });
    btnIntroCancel?.addEventListener('click', () => { introForm.classList.add('d-none'); btnIntroEdit.classList.remove('d-none'); btnIntroAdd.classList.remove('d-none'); if (introStash){ introBody.value = introStash.body; introActive.checked = introStash.active; } renderIntro(); });
    ['input','change','keyup'].forEach(evt => introBody?.addEventListener(evt, renderIntro));

    // Instructions
    const instPreview = document.getElementById('instPreview');
    const instForm    = document.getElementById('instForm');
    const btnInstEdit = document.getElementById('btnInstEdit');
    const btnInstAdd  = document.getElementById('btnInstAdd');
    const btnInstCancel = document.getElementById('btnInstCancel');
    const instTitle   = document.getElementById('inst_title');
    const instBullets = document.getElementById('inst_bullets');
    const instActive  = document.getElementById('inst_active');
    let instStash = null;
    function renderInst() {
      const rawTitle = (instTitle?.value || '').trim();
      const normTitle = rawTitle.replace(/[:\s]+$/,'');
      const showHeader = (normTitle.length && normTitle.toLowerCase() !== 'instructions');
      const lines = (instBullets?.value || '').split(/\r?\n/).map(s=>s.trim()).filter(Boolean);
      let html = '';
      if (showHeader) html += `<h2 class="h6 mb-2">${mdInlineBasic(rawTitle)}</h2>`;
      if (lines.length) { html += '<ol class="mb-0">'; for (const li of lines) html += `<li>${mdInlineBasic(li)}</li>`; html += '</ol>'; }
      else { html += '<p class="text-muted mb-0">No instructions yet.</p>'; }
      instPreview.innerHTML = html;
    }
    btnInstEdit?.addEventListener('click', () => { instStash = { title: instTitle.value, bullets: instBullets.value, active: instActive.checked }; instForm.classList.remove('d-none'); btnInstEdit.classList.add('d-none'); btnInstAdd.classList.add('d-none'); instTitle.focus(); });
    btnInstAdd?.addEventListener('click', () => { instStash = { title: instTitle.value, bullets: instBullets.value, active: instActive.checked }; instForm.classList.remove('d-none'); btnInstEdit.classList.add('d-none'); btnInstAdd.classList.add('d-none'); instTitle.value = 'Instructions'; instBullets.value = ''; instActive.checked = true; renderInst(); instTitle.focus(); });
    btnInstCancel?.addEventListener('click', () => { instForm.classList.add('d-none'); btnInstEdit.classList.remove('d-none'); btnInstAdd.classList.remove('d-none'); if (instStash){ instTitle.value = instStash.title; instBullets.value = instStash.bullets; instActive.checked = instStash.active; } renderInst(); });
    ['input','change','keyup'].forEach(evt => { instTitle?.addEventListener(evt, renderInst); instBullets?.addEventListener(evt, renderInst); });

    // initial renders
    renderIntro(); renderInst();

    // Active toggle confirm (shared dark adminConfirm modal)
    document.querySelectorAll('.active-switch').forEach(function (chk) {
      chk.addEventListener('change', function () {
        var switchEl = this;
        var form = this.closest('form');
        var goingActive = switchEl.checked;
        var message = goingActive
          ? 'Show this question on the registration form?'
          : 'Hide this question? Businesses will not see it (existing answers are kept).';
        var confirmFn = typeof window.adminConfirm === 'function'
          ? window.adminConfirm({
              title: '',
              message: message,
              confirmLabel: 'Yes, continue',
              confirmClass: 'btn-primary',
            })
          : Promise.resolve(window.confirm(message));
        confirmFn.then(function (confirmed) {
          if (confirmed && form) {
            form.submit();
          } else {
            switchEl.checked = !switchEl.checked;
          }
        });
      });
    });

    // Flash toast
    <?php if ($flash):
      $t = $flash['type'] ?? 'info';
      $m = $flash['msg'] ?? '';
      $bgClass = 'bg-success text-white';
      if ($t === 'danger') $bgClass = 'bg-danger text-white';
      elseif ($t === 'warning') $bgClass = 'bg-warning text-dark';
    ?>
      (function(){
        var toastEl = document.getElementById('flashToast');
        var body = document.getElementById('flashToastBody');
        body.innerHTML = <?= json_encode($m) ?>;
        toastEl.className = 'toast <?= $bgClass ?>';
        var toast = new bootstrap.Toast(toastEl);
        toast.show();
      })();
    <?php endif; ?>
  })();
</script>
<script>
  window.NOM_FIELDS_MAINT = {
    editingFieldId: <?= $editing ? (int)$edit_row['id'] : 0 ?>,
    csrf: <?= json_encode($csrf) ?>,
    eventId: <?= (int) $activeEventId ?>,
    nextSortOrder: <?= (int) $nextSortOrder ?>,
    postUrl: <?= json_encode('nomination_fields.php') ?>,
    previewUrl: <?= json_encode('nomination_form_preview.php') ?>,
    eventName: <?= json_encode($eventName, JSON_UNESCAPED_UNICODE) ?>
  };
</script>
<script src="js/admin_confirm.js"></script>
<script src="js/nomination_fields_maintenance.js"></script>
</body>
</html>

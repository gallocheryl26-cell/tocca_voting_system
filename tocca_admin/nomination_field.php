<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/../nomination/nomination_field_helpers.php';

/* ---------------- Helpers ---------------- */
function make_slug(string $label): string {
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    if ($slug === '') $slug = 'field_' . substr(md5($label . microtime(true)), 0, 6);
    return $slug;
}

/** Read JSON body if sent (fetch, axios, etc.) */
$JSON_BODY = null;
if (empty($_POST)) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $try = json_decode($raw, true);
        if (is_array($try)) $JSON_BODY = $try;
    }
}

function inb($k, $def = null) {
    global $JSON_BODY;
    if (isset($_POST[$k])) return $_POST[$k];
    if (isset($_REQUEST[$k])) return $_REQUEST[$k];
    if (is_array($JSON_BODY) && array_key_exists($k, $JSON_BODY)) return $JSON_BODY[$k];
    return $def;
}

/** Normalize options from many shapes to JSON array string or null */
function normalize_options_any($raw) {
    // Accept array directly
    if (is_array($raw)) {
        $vals = array_values(array_filter(array_map('trim', $raw), fn($v)=>$v!==''));
        return empty($vals) ? null : json_encode($vals, JSON_UNESCAPED_UNICODE);
    }
    if ($raw === null) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;

    // Try JSON first
    $try = json_decode($s, true);
    if (is_array($try)) {
        $vals = array_values(array_filter(array_map('trim', $try), fn($v)=>$v!==''));
        return empty($vals) ? null : json_encode($vals, JSON_UNESCAPED_UNICODE);
    }

    // Fallback: split by comma/newline/pipe
    $parts = preg_split('/[\r\n,\|]+/u', $s);
    $vals = array_values(array_filter(array_map('trim', $parts), fn($v)=>$v!==''));
    return empty($vals) ? null : json_encode($vals, JSON_UNESCAPED_UNICODE);
}

/** Get options from multiple possible keys */
function collect_options_input() {
    // Common names used by different admin UIs
    $candidates = [
        'options_array',   // array from multiple inputs
        'options',         // either array or string or JSON
        'options_csv',     // comma/pipe/newline string
        'options_text',    // textarea string
    ];
    foreach ($candidates as $k) {
        $val = inb($k, null);
        if ($val !== null && $val !== '') return $val;
    }
    return null;
}

/* ---------------- Action ---------------- */
$action = $_REQUEST['action'] ?? ($JSON_BODY['action'] ?? 'list');

try {
    if ($action === 'list') {
        $sql = "SELECT id, label, name, `type`, options, is_required, is_active, sort_order, created_at, updated_at
                FROM tbl_nomination_fields
                ORDER BY sort_order ASC, id ASC";
        $res = $conn->query($sql);
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $arr = $r['options'] ? (json_decode($r['options'], true) ?: []) : [];
            $r['options_array']    = $arr;
            $r['options_readable'] = $arr ? implode(', ', $arr) : '';
            $rows[] = $r;
        }
        echo json_encode(['data' => $rows]);
        exit;
    }

    if ($action === 'get') {
        $id = (int)(inb('id', 0));
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }
        $stmt = $conn->prepare("SELECT * FROM tbl_nomination_fields WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $row = nf_enrich_field($row);
            $row['options_array'] = $row['options_arr'] ?? [];
            $row['options_text'] = !empty($row['options_arr'])
                ? implode("\n", $row['options_arr']) : '';
            $va = $row['validation_arr'] ?? [];
            $row['val_max_length'] = (string) ($va['max_length'] ?? '');
            $row['val_pattern']    = (string) ($va['pattern'] ?? '');
            $row['val_accept']     = (string) ($va['accept'] ?? '');
            $row['val_min']        = isset($va['min']) ? (string) $va['min'] : '';
            $row['val_max']        = isset($va['max']) ? (string) $va['max'] : '';
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
        exit;
    }

    if ($action === 'save') {
        $ALLOWED_TYPES = ['text','textarea','select','checkbox','radio','file','email','tel','url','number','date'];

        $id          = inb('id', null);
        $id          = ($id === '' || $id === null) ? null : (int)$id;
        $label       = trim((string)inb('label', ''));
        $type        = (string)inb('type', 'text');
        $is_required = !empty(inb('is_required')) ? 1 : 0;
        $is_active   = !empty(inb('is_active')) ? 1 : 0;
        $sort_order  = (int)inb('sort_order', 0);

        if ($label === '') { echo json_encode(['success' => false, 'message' => 'Label is required']); exit; }
        if (!in_array($type, $ALLOWED_TYPES, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid field type']); exit;
        }

        // Gather options regardless of naming
        $options_raw  = collect_options_input();
        $options_json = normalize_options_any($options_raw);

        // For select/checkbox/radio, non-empty options required
        if (in_array($type, ['select','checkbox','radio'], true)) {
            if ($options_json === null) {
                echo json_encode(['success' => false, 'message' => 'Options are required for select/checkbox/radio']); exit;
            }
        }

        // Preserve name on update
        if ($id !== null) {
            $stmt0 = $conn->prepare("SELECT id, name FROM tbl_nomination_fields WHERE id = ? LIMIT 1");
            $stmt0->bind_param("i", $id);
            $stmt0->execute();
            $cur = $stmt0->get_result()->fetch_assoc();
            if (!$cur) { echo json_encode(['success' => false, 'message' => 'Record to update not found']); exit; }
            $name = $cur['name'];

            $sql = "UPDATE tbl_nomination_fields
                       SET label = ?, `type` = ?, options = NULLIF(?, ''),
                           is_required = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $opt = $options_json ?? '';
            $stmt->bind_param("sssiiii", $label, $type, $opt, $is_required, $is_active, $sort_order, $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Field updated', 'id' => $id, 'name' => $name]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Update failed: ' . $conn->error]);
            }
            exit;
        }

        // Insert → generate unique name
        $name = make_slug($label);
        $chk  = $conn->prepare("SELECT id FROM tbl_nomination_fields WHERE name = ? LIMIT 1");
        $chk->bind_param("s", $name);
        $chk->execute();
        $found = $chk->get_result()->fetch_assoc();
        if ($found) {
            $base = $name; $i = 1;
            do {
                $name = $base . '_' . $i++;
                $chk->bind_param("s", $name);
                $chk->execute();
                $found = $chk->get_result()->fetch_assoc();
            } while ($found);
        }

        $sql = "INSERT INTO tbl_nomination_fields
                    (label, name, `type`, options, is_required, is_active, sort_order, created_at, updated_at)
                VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $stmt = $conn->prepare($sql);
        $opt = $options_json ?? '';
        $stmt->bind_param("ssssiii", $label, $name, $type, $opt, $is_required, $is_active, $sort_order);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Field added', 'id' => $conn->insert_id, 'name' => $name]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $conn->error]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)inb('id', 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }
        $stmt = $conn->prepare("DELETE FROM tbl_nomination_fields WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Field deleted']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $conn->error]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

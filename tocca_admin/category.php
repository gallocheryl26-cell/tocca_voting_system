<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/includes/category_voting_profile.php';

category_voting_profile_ensure_schema($conn); 

function get_active_event_id(mysqli $conn): ?int {
    $res = $conn->query("SELECT event_id FROM tbl_events WHERE is_active = 1 ORDER BY event_id DESC LIMIT 1");
    if ($res && $res->num_rows) {
        $r = $res->fetch_assoc();
        return (int)$r['event_id'];
    }
    return null;
}

function get_category_row(mysqli $conn, int $category_id): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_categories WHERE category_id=?");
    $stmt->bind_param('i', $category_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function load_categories(mysqli $conn, ?int $eventId = null): array {
    if ($eventId === null) {
        $eventId = get_active_event_id($conn);
    }
    if ($eventId === null) return [];

    $stmt = $conn->prepare("
        SELECT * FROM tbl_categories
        WHERE event_id = ?
        ORDER BY category_id DESC
    ");
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function count_related_for_category(mysqli $conn, int $category_id, string $sql): int {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $category_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0);
}

function get_category_delete_impact(mysqli $conn, int $category_id): array {
    $awards = [];
    $stmt = $conn->prepare("
        SELECT question_id, question_name
        FROM tbl_questions
        WHERE category_id = ?
        ORDER BY question_name ASC
    ");
    $stmt->bind_param('i', $category_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $awards[] = [
            'question_id'   => (int)$row['question_id'],
            'question_name' => (string)$row['question_name'],
        ];
    }
    $stmt->close();

    $related = [
        'nominations'              => 0,
        'votes'                    => 0,
        'establishment_links'      => 0,
        'establishment_type_links' => 0,
    ];

    if ($awards) {
        $related['nominations'] = count_related_for_category($conn, $category_id, "
            SELECT COUNT(*) AS cnt
            FROM tbl_nomination_questions nq
            INNER JOIN tbl_questions q ON q.question_id = nq.question_id
            WHERE q.category_id = ?
        ");
        $related['establishment_links'] = count_related_for_category($conn, $category_id, "
            SELECT COUNT(*) AS cnt
            FROM tbl_question_choices qc
            INNER JOIN tbl_questions q ON q.question_id = qc.question_id
            WHERE q.category_id = ?
        ");
        $related['establishment_type_links'] = count_related_for_category($conn, $category_id, "
            SELECT COUNT(*) AS cnt
            FROM tbl_establishment_type_awards eta
            INNER JOIN tbl_questions q ON q.question_id = eta.question_id
            WHERE q.category_id = ?
        ");
        $pollChoice = count_related_for_category($conn, $category_id, "
            SELECT COUNT(*) AS cnt
            FROM tbl_poll_choice pc
            INNER JOIN tbl_questions q ON q.question_id = pc.question_id
            WHERE q.category_id = ?
        ");
        $pollFree = count_related_for_category($conn, $category_id, "
            SELECT COUNT(*) AS cnt
            FROM tbl_poll_freetext pf
            INNER JOIN tbl_questions q ON q.question_id = pf.question_id
            WHERE q.category_id = ?
        ");
        $related['votes'] = $pollChoice + $pollFree;
    }

    $relatedTotal = array_sum($related);

    return [
        'category_id'          => $category_id,
        'award_count'          => count($awards),
        'awards'             => $awards,
        'related'            => $related,
        'has_related_data'   => $relatedTotal > 0,
        'has_blocking_data'  => ($related['nominations'] + $related['votes']) > 0,
    ];
}

$raw  = file_get_contents("php://input");
$data = json_decode($raw ?: "{}", true) ?? [];
$event_id = isset($data['event_id']) && $data['event_id'] !== '' ? (int)$data['event_id'] : null;

if (!empty($data['loadOnly'])) {
    $rows = load_categories($conn, $event_id);
    echo json_encode(['status' => 'success', 'data' => $rows]);
    exit;
}

if (($data['action'] ?? '') === 'getDeleteImpact' && isset($data['category_id'])) {
    $category_id = (int)$data['category_id'];
    if ($category_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid category ID']);
        exit;
    }

    $cat = get_category_row($conn, $category_id);
    if (!$cat) {
        echo json_encode(['status' => 'error', 'message' => 'Category not found']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'data'   => get_category_delete_impact($conn, $category_id),
    ]);
    exit;
}

if (($data['action'] ?? '') === 'delete' && isset($data['ids']) && is_array($data['ids'])) {
    $ids = array_values(array_filter(array_map('intval', $data['ids'])));
    if (!$ids) {
        echo json_encode(['status' => 'error', 'message' => 'No IDs provided']); exit;
    }

    $snapshots = [];
    $impacts = [];
    foreach ($ids as $cid) {
        $snap = get_category_row($conn, $cid);
        if ($snap) {
            $snapshots[$cid] = $snap;
            $impacts[$cid] = get_category_delete_impact($conn, $cid);
        }
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $conn->prepare("DELETE FROM tbl_categories WHERE category_id IN ($ph)");
    $stmt->bind_param($types, ...$ids);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $deleted = [];
        foreach ($ids as $cid) {
            $old = $snapshots[$cid] ?? [];
            $impact = $impacts[$cid] ?? ['award_count' => 0, 'awards' => []];
            audit_log($conn, 'categories', 'delete', 'category', $cid, [
                'old' => $old,
                'deleted_awards' => $impact['awards'] ?? [],
            ]);
            $deleted[] = [
                'category_id' => $cid,
                'award_count' => (int)($impact['award_count'] ?? 0),
                'award_names' => array_column($impact['awards'] ?? [], 'question_name'),
            ];
        }
        echo json_encode(['status' => 'success', 'deleted' => $deleted]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
    }
    exit;
}

if (isset($data['action']) && $data['action'] === 'toggleStatus') {
    require_once 'audit_log.php';

    $category_id = (int)$data['category_id'];
    $status      = (int)$data['status'];
    $st = $conn->prepare("SELECT category_id, category_name, status, event_id FROM tbl_categories WHERE category_id=?");
    $st->bind_param("i", $category_id);
    $st->execute();
    $old = $st->get_result()->fetch_assoc();
    $st->close();
    $stmt = $conn->prepare("UPDATE tbl_categories SET status = ? WHERE category_id = ?");
    $stmt->bind_param("ii", $status, $category_id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $st = $conn->prepare("SELECT category_id, category_name, status, event_id FROM tbl_categories WHERE category_id=?");
        $st->bind_param("i", $category_id);
        $st->execute();
        $neu = $st->get_result()->fetch_assoc();
        $st->close();
        $diff = audit_diff_assoc($old ?? [], $neu ?? []);
        audit_log(
          $conn,
          'categories',
          $status === 1 ? 'activate' : 'deactivate',
          'category',
          $category_id,
          ['old' => $old, 'new' => $neu, 'diff' => $diff]
        );
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update status.']);
    }
    exit;
}

if (($data['action'] ?? '') === 'getSingle' && isset($data['category_id'])) {
    $category_id = (int)$data['category_id'];
    $row = get_category_row($conn, $category_id);

    if ($row) echo json_encode(['status' => 'success', 'data' => $row]);
    else      echo json_encode(['status' => 'error', 'message' => 'Category not found']);
    exit;
}

$category_name = trim((string)($data['category_name'] ?? ''));
$category_id   = isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null;
$voting_profile = category_voting_profile_normalize($data['voting_profile'] ?? 'business');
if ($event_id === null) $event_id = get_active_event_id($conn);

if ($category_name === '') {
    echo json_encode(['status' => 'error', 'message' => 'Category name is empty']); exit;
}
if ($event_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'No active event selected/found']); exit;
}
if ($category_id) {
    $stmt = $conn->prepare("SELECT category_id FROM tbl_categories WHERE category_name = ? AND event_id = ? AND category_id <> ?");
    $stmt->bind_param("sii", $category_name, $event_id, $category_id);
} else {
    $stmt = $conn->prepare("SELECT category_id FROM tbl_categories WHERE category_name = ? AND event_id = ?");
    $stmt->bind_param("si", $category_name, $event_id);
}
$stmt->execute();
$dupe = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($dupe) {
    echo json_encode(['status' => 'duplicate']); exit;
}

if ($category_id) {
    $old = get_category_row($conn, $category_id);
    $stmt = $conn->prepare("UPDATE tbl_categories SET category_name = ?, event_id = ?, voting_profile = ? WHERE category_id = ?");
    $stmt->bind_param("sisi", $category_name, $event_id, $voting_profile, $category_id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $new  = [
            'category_name'  => $category_name,
            'event_id'       => $event_id,
            'voting_profile' => $voting_profile,
        ];
        $diff = audit_diff_assoc($old ?: [], $new);
        audit_log($conn, 'categories', 'update', 'category', $category_id, [
            'old' => $old, 'new' => $new, 'diff' => $diff
        ]);

        $rows = load_categories($conn, $event_id);
        echo json_encode(['status' => 'success', 'data' => $rows]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    }
    exit;
} else {
    $stmt = $conn->prepare("INSERT INTO tbl_categories (category_name, event_id, status, voting_profile) VALUES (?, ?, 1, ?)");
    $stmt->bind_param("sis", $category_name, $event_id, $voting_profile);
    $ok = $stmt->execute();
    $newId = $ok ? $stmt->insert_id : null;
    $stmt->close();

    if ($ok) {
        $newRow = get_category_row($conn, (int)$newId);
        audit_log($conn, 'categories', 'create', 'category', (int)$newId, [
            'new' => $newRow ?: ['category_name' => $category_name, 'event_id' => $event_id, 'status' => 1]
        ]);

        $rows = load_categories($conn, $event_id);
        echo json_encode(['status' => 'success', 'data' => $rows]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed']);
    }
    exit;
}

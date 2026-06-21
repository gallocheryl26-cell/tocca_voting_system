<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connection.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/establishment_type_event_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$type_id = null;
if (isset($_GET['establishment_type_id'])) {
    $type_id = (int) $_GET['establishment_type_id'];
} elseif (isset($_GET['type_id'])) {
    $type_id = (int) $_GET['type_id'];
} elseif (isset($_GET['category_id'])) {
    $type_id = (int) $_GET['category_id'];
}

$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

if ($type_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing establishment_type_id']);
    exit;
}
if ($event_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing event reference.']);
    exit;
}

try {
    if (!et_type_belongs_to_event($conn, $type_id, $event_id)) {
        echo json_encode(['status' => 'success', 'questions' => []]);
        exit;
    }

    $hasIsActive = false;
    if ($cols = $conn->query('SHOW COLUMNS FROM tbl_questions')) {
        while ($c = $cols->fetch_assoc()) {
            if (strtolower((string) ($c['Field'] ?? '')) === 'is_active') {
                $hasIsActive = true;
                break;
            }
        }
    }

    $sql = '
        SELECT DISTINCT q.question_id, q.question_name
        FROM tbl_establishment_type_awards AS eta
        INNER JOIN tbl_questions AS q ON q.question_id = eta.question_id
        INNER JOIN tbl_categories AS c ON c.category_id = q.category_id AND c.event_id = ?
        WHERE eta.type_id = ?
    ';
    if ($hasIsActive) {
        $sql .= ' AND q.is_active = 1';
    }
    $sql .= ' ORDER BY q.question_name ASC';

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database query failed']);
        exit;
    }
    $stmt->bind_param('ii', $event_id, $type_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $questions = [];
    while ($r = $result->fetch_assoc()) {
        $questions[] = [
            'question_id'   => (int) $r['question_id'],
            'question_name' => $r['question_name'],
        ];
    }
    $stmt->close();

    echo json_encode(['status' => 'success', 'questions' => $questions]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unexpected server error']);
}

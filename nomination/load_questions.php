<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connection.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/establishment_type_event_helpers.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/award_answer_fields.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/award_entry_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

et_ensure_m2m_schema($conn);
award_answer_fields_ensure_schema($conn);

$typeIds = [];
if (isset($_GET['establishment_type_ids'])) {
    $typeIds = et_parse_type_ids($_GET['establishment_type_ids']);
} elseif (isset($_GET['establishment_type_id'])) {
    $typeIds = et_parse_type_ids($_GET['establishment_type_id']);
} elseif (isset($_GET['type_id'])) {
    $typeIds = et_parse_type_ids($_GET['type_id']);
} elseif (isset($_GET['category_id'])) {
    $typeIds = et_parse_type_ids($_GET['category_id']);
}

$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

if ($typeIds === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing establishment_type_id(s)']);
    exit;
}
if ($event_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing event reference.']);
    exit;
}

try {
    if (!et_types_belong_to_event($conn, $typeIds, $event_id)) {
        echo json_encode(['status' => 'success', 'questions' => [], 'type_ids' => $typeIds]);
        exit;
    }

    $awards = et_fetch_awards_for_types($conn, $typeIds, $event_id);
    $questions = [];
    foreach ($awards as $a) {
        $qid = (int) $a['question_id'];
        $qname = (string) $a['question_name'];
        $fields = '';
        $st = $conn->prepare('SELECT answer_fields FROM tbl_questions WHERE question_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $qid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            $fields = (string) ($row['answer_fields'] ?? '');
        }
        $kind = award_entry_kind_for_question($qname, $fields);
        $questions[] = [
            'question_id'   => $qid,
            'question_name' => $qname,
            'category_id'   => $a['category_id'] !== null ? (int) $a['category_id'] : null,
            'category_name' => $a['category_name'] !== null ? (string) $a['category_name'] : '',
            'type_id'       => $a['type_id'] !== null ? (int) $a['type_id'] : null,
            'type_name'     => (string) ($a['type_name'] ?? ''),
            'type_ids'      => array_values(array_map('intval', $a['type_ids'] ?? [])),
            'answer_fields' => $fields,
            'entry_kind'    => $kind,
            'entry_label'   => $kind ? award_entry_kind_label($kind) : null,
            'entry_multiple'=> $kind ? award_entry_allows_multiple($kind) : false,
            'entry_max'     => $kind ? award_entry_max_count($kind) : 0,
        ];
    }

    echo json_encode([
        'status'    => 'success',
        'questions' => $questions,
        'type_ids'  => $typeIds,
    ]);
} catch (Throwable $e) {
    error_log('load_questions failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unexpected server error']);
}

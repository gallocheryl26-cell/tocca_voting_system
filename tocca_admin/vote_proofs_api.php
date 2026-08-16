<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once dirname(__DIR__) . '/e-vote-final-enhanced/lib/vote_proof_helpers.php';

vote_proof_ensure_schema($conn);

$action = trim((string) ($_GET['action'] ?? 'list'));
$eventId = 0;
$evRes = $conn->query(
    'SELECT event_id FROM tbl_events
     WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
     ORDER BY year DESC, event_id DESC
     LIMIT 1'
);
if ($evRes && ($evRow = $evRes->fetch_assoc())) {
    $eventId = (int) $evRow['event_id'];
}
if ($eventId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'No active event.']);
    exit;
}

function vote_proofs_admin_file_url(int $proofId, string $scope): string
{
    $scope = $scope === 'draft' ? 'draft' : 'final';
    return 'vote_proof_file.php?proof_id=' . $proofId . '&scope=' . $scope;
}

function vote_proofs_format_when(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '' || strtotime($raw) === false) {
        return '';
    }
    return date('M j, Y g:i A', strtotime($raw));
}

if ($action === 'files') {
    $voterId = (int) ($_GET['voters_id'] ?? 0);
    $questionId = (int) ($_GET['question_id'] ?? 0);
    if ($voterId <= 0 || $questionId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing voter or award.']);
        exit;
    }

    $meta = $conn->prepare(
        'SELECT v.mobile_number, q.question_name, c.category_name, ch.choice_name, pc.vote_at
         FROM tbl_poll_choice pc
         INNER JOIN tbl_voters v ON v.voters_id = pc.voters_id
         INNER JOIN tbl_questions q ON q.question_id = pc.question_id
         INNER JOIN tbl_categories c ON c.category_id = q.category_id
         INNER JOIN tbl_choices ch ON ch.choice_id = pc.choice_id
         WHERE pc.voters_id = ? AND pc.question_id = ? AND c.event_id = ?
         LIMIT 1'
    );
    $meta->bind_param('iii', $voterId, $questionId, $eventId);
    $meta->execute();
    $info = $meta->get_result()->fetch_assoc() ?: [];
    $meta->close();

    $mobile = (string) ($info['mobile_number'] ?? '');
    $award = (string) ($info['question_name'] ?? '');
    $business = (string) ($info['choice_name'] ?? '');
    $votedAt = vote_proofs_format_when($info['vote_at'] ?? '');
    $caption = vote_proof_staff_caption($mobile, $award, $business, $votedAt);

    $files = [];
    foreach (vote_proof_files_for_vote($conn, $voterId, $questionId) as $file) {
        $when = vote_proofs_format_when($file['uploaded_at'] ?? '');
        $files[] = [
            'proof_id' => $file['proof_id'],
            'scope' => $file['scope'],
            'url' => vote_proofs_admin_file_url($file['proof_id'], $file['scope']),
            'uploaded_at' => $when,
            'caption' => vote_proof_staff_caption($mobile, $award, $business, $when !== '' ? $when : $votedAt),
        ];
    }

    echo json_encode([
        'status' => 'success',
        'caption' => $caption,
        'mobile_number' => $mobile,
        'category_name' => (string) ($info['category_name'] ?? ''),
        'question_name' => $award,
        'choice_name' => $business,
        'vote_at' => $votedAt,
        'files' => $files,
    ]);
    exit;
}

$categoryId = (int) ($_GET['category_id'] ?? 0);
$questionId = (int) ($_GET['question_id'] ?? 0);
$choiceId = (int) ($_GET['choice_id'] ?? 0);
$voterId = (int) ($_GET['voters_id'] ?? 0);
$mobile = trim((string) ($_GET['mobile'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$proofFilter = strtolower(trim((string) ($_GET['proof'] ?? 'all')));
if (!in_array($proofFilter, ['all', 'with', 'without'], true)) {
    $proofFilter = 'all';
}

$where = ['c.event_id = ?'];
$types = 'i';
$params = [$eventId];

if ($categoryId > 0) {
    $where[] = 'c.category_id = ?';
    $types .= 'i';
    $params[] = $categoryId;
}
if ($questionId > 0) {
    $where[] = 'q.question_id = ?';
    $types .= 'i';
    $params[] = $questionId;
}
if ($choiceId > 0) {
    $where[] = 'ch.choice_id = ?';
    $types .= 'i';
    $params[] = $choiceId;
}
if ($voterId > 0) {
    $where[] = 'v.voters_id = ?';
    $types .= 'i';
    $params[] = $voterId;
}
if ($mobile !== '') {
    $where[] = 'v.mobile_number LIKE ?';
    $types .= 's';
    $params[] = '%' . $mobile . '%';
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'DATE(pc.vote_at) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'DATE(pc.vote_at) <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

$proofExpr = '(
    (SELECT COUNT(*) FROM tbl_vote_proof vp WHERE vp.voters_id = pc.voters_id AND vp.question_id = pc.question_id)
  + (SELECT COUNT(*) FROM tbl_draft_vote_proof dp WHERE dp.voters_id = pc.voters_id AND dp.question_id = pc.question_id)
)';
if ($proofFilter === 'with') {
    $where[] = $proofExpr . ' > 0';
} elseif ($proofFilter === 'without') {
    $where[] = $proofExpr . ' = 0';
}

$sql = "SELECT
            pc.voters_id,
            v.mobile_number,
            q.question_id,
            q.question_name,
            c.category_id,
            c.category_name,
            ch.choice_id,
            ch.choice_name,
            pc.vote_at,
            {$proofExpr} AS proof_count,
            (SELECT vp.proof_id FROM tbl_vote_proof vp
             WHERE vp.voters_id = pc.voters_id AND vp.question_id = pc.question_id
             ORDER BY vp.proof_id ASC LIMIT 1) AS first_final_id,
            (SELECT dp.proof_id FROM tbl_draft_vote_proof dp
             WHERE dp.voters_id = pc.voters_id AND dp.question_id = pc.question_id
             ORDER BY dp.proof_id ASC LIMIT 1) AS first_draft_id
        FROM tbl_poll_choice pc
        INNER JOIN tbl_voters v ON v.voters_id = pc.voters_id
        INNER JOIN tbl_questions q ON q.question_id = pc.question_id
        INNER JOIN tbl_categories c ON c.category_id = q.category_id
        INNER JOIN tbl_choices ch ON ch.choice_id = pc.choice_id
        WHERE " . implode(' AND ', $where) . '
        ORDER BY pc.vote_at DESC, pc.voters_id DESC
        LIMIT 500';

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['status' => 'error', 'message' => 'Could not load proofs.']);
    exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$withProof = 0;
$withoutProof = 0;
while ($row = $result->fetch_assoc()) {
    $count = (int) ($row['proof_count'] ?? 0);
    if ($count > 0) {
        $withProof++;
    } else {
        $withoutProof++;
    }
    $thumbUrl = '';
    $firstFinal = (int) ($row['first_final_id'] ?? 0);
    $firstDraft = (int) ($row['first_draft_id'] ?? 0);
    if ($firstFinal > 0) {
        $thumbUrl = vote_proofs_admin_file_url($firstFinal, 'final');
    } elseif ($firstDraft > 0) {
        $thumbUrl = vote_proofs_admin_file_url($firstDraft, 'draft');
    }
    $votedAt = vote_proofs_format_when($row['vote_at'] ?? '');
    $rows[] = [
        'voters_id' => (int) $row['voters_id'],
        'mobile_number' => (string) $row['mobile_number'],
        'category_id' => (int) $row['category_id'],
        'category_name' => (string) $row['category_name'],
        'question_id' => (int) $row['question_id'],
        'question_name' => (string) $row['question_name'],
        'choice_id' => (int) $row['choice_id'],
        'choice_name' => (string) $row['choice_name'],
        'vote_at' => $votedAt,
        'proof_count' => $count,
        'thumb_url' => $thumbUrl,
        'caption' => vote_proof_staff_caption(
            (string) $row['mobile_number'],
            (string) $row['question_name'],
            (string) $row['choice_name'],
            $votedAt
        ),
    ];
}
$stmt->close();

$categories = [];
$catRes = $conn->prepare(
    'SELECT category_id, category_name FROM tbl_categories WHERE event_id = ? ORDER BY category_name'
);
$catRes->bind_param('i', $eventId);
$catRes->execute();
$catRows = $catRes->get_result();
while ($row = $catRows->fetch_assoc()) {
    $categories[] = [
        'category_id' => (int) $row['category_id'],
        'category_name' => (string) $row['category_name'],
    ];
}
$catRes->close();

$awards = [];
$awardSql = 'SELECT q.question_id, q.question_name, q.category_id
             FROM tbl_questions q
             INNER JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE c.event_id = ?';
$awardTypes = 'i';
$awardParams = [$eventId];
if ($categoryId > 0) {
    $awardSql .= ' AND q.category_id = ?';
    $awardTypes .= 'i';
    $awardParams[] = $categoryId;
}
$awardSql .= ' ORDER BY q.question_name';
$awardStmt = $conn->prepare($awardSql);
$awardStmt->bind_param($awardTypes, ...$awardParams);
$awardStmt->execute();
$awardRows = $awardStmt->get_result();
while ($row = $awardRows->fetch_assoc()) {
    $awards[] = [
        'question_id' => (int) $row['question_id'],
        'question_name' => (string) $row['question_name'],
        'category_id' => (int) $row['category_id'],
    ];
}
$awardStmt->close();

$businesses = [];
$bizSql = 'SELECT DISTINCT ch.choice_id, ch.choice_name
           FROM tbl_poll_choice pc
           INNER JOIN tbl_questions q ON q.question_id = pc.question_id
           INNER JOIN tbl_categories c ON c.category_id = q.category_id
           INNER JOIN tbl_choices ch ON ch.choice_id = pc.choice_id
           WHERE c.event_id = ?';
$bizTypes = 'i';
$bizParams = [$eventId];
if ($questionId > 0) {
    $bizSql .= ' AND q.question_id = ?';
    $bizTypes .= 'i';
    $bizParams[] = $questionId;
} elseif ($categoryId > 0) {
    $bizSql .= ' AND c.category_id = ?';
    $bizTypes .= 'i';
    $bizParams[] = $categoryId;
}
$bizSql .= ' ORDER BY ch.choice_name';
$bizStmt = $conn->prepare($bizSql);
$bizStmt->bind_param($bizTypes, ...$bizParams);
$bizStmt->execute();
$bizRows = $bizStmt->get_result();
while ($row = $bizRows->fetch_assoc()) {
    $businesses[] = [
        'choice_id' => (int) $row['choice_id'],
        'choice_name' => (string) $row['choice_name'],
    ];
}
$bizStmt->close();

echo json_encode([
    'status' => 'success',
    'event_id' => $eventId,
    'stats' => [
        'shown' => count($rows),
        'with_proof' => $withProof,
        'without_proof' => $withoutProof,
    ],
    'filters' => [
        'categories' => $categories,
        'awards' => $awards,
        'businesses' => $businesses,
    ],
    'rows' => $rows,
]);

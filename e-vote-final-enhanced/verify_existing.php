<?php

declare(strict_types=1);



header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', '0');



require_once __DIR__ . '/../tocca_admin/db_connection.php';

require_once 'voter_session.php';

require_once __DIR__ . '/lib/voter_flow.php';

require_once __DIR__ . '/lib/voter_access_login.php';



voter_session_start();



$data = json_decode(file_get_contents('php://input'), true);

$mobile = trim((string)($data['mobile_number'] ?? ''));

$draftCode = trim((string)($data['draft_code'] ?? ''));



$normalizedMobile = voter_flow_normalize_mobile($mobile);

if ($normalizedMobile === null || !preg_match('/^\d{4}$/', $draftCode)) {

    echo json_encode([

        'status' => 'error',

        'code' => 'invalid_input',

        'message' => 'Enter a valid mobile number and 4-digit access code.',

    ]);

    exit;

}



if (!voter_flow_is_voting_open($conn)) {

    echo json_encode(['status' => 'error', 'message' => 'Voting is not open at this time.']);

    exit;

}



$mobile = $normalizedMobile;



$lockStatus = voter_access_login_status($conn, $mobile);

if (!empty($lockStatus['locked'])) {

    echo json_encode(voter_access_login_error_payload($lockStatus));

    exit;

}



$stmt = $conn->prepare('SELECT * FROM tbl_voters WHERE mobile_number = ? AND draft_code = ? LIMIT 1');

if (!$stmt) {

    echo json_encode(['status' => 'error', 'message' => 'Unable to verify credentials. Please try again.']);

    exit;

}

$stmt->bind_param('ss', $mobile, $draftCode);

$stmt->execute();

$result = $stmt->get_result();



if ($result->num_rows === 0) {

    $stmt->close();

    $afterFail = voter_access_login_record_failure($conn, $mobile);

    echo json_encode(voter_access_login_error_payload(

        $afterFail,

        'Incorrect access code.'

    ));

    exit;

}



$voter = $result->fetch_assoc();

$stmt->close();



voter_access_login_clear($conn, $mobile);



$voter_id = (int)$voter['voters_id'];

$has_voted = voter_flow_sync_has_voted_if_complete($conn, $voter_id);

$voter['has_voted'] = $has_voted;



if ($has_voted === 1) {
    // The completion page loads progress from the authenticated session. Keep
    // this legacy voter signed in until they explicitly sign out there.
    session_regenerate_id(true);
    $_SESSION['voter_id'] = $voter_id;
    $_SESSION['verified_mobile'] = $mobile;
    $_SESSION['voter_auth_provider'] = 'legacy_mobile';
    unset($_SESSION['firebase_uid']);

    echo json_encode([

        'status' => 'success',

        'voter_info' => $voter,

        'unanswered_questions' => [],

        'completion_status' => 'completed',

    ]);

    exit;

}



$_SESSION['voter_id'] = $voter_id;

$_SESSION['verified_mobile'] = $mobile;

$_SESSION['voter_auth_provider'] = 'legacy_mobile';

unset($_SESSION['firebase_uid']);

session_regenerate_id(true);



$event_id = voter_get_active_event_id($conn);

if ($event_id === null) {

    echo json_encode(['status' => 'error', 'message' => 'No active event configured.']);

    exit;

}



$votableSql = voter_flow_votable_question_sql($conn, 'q');

$questions = $conn->prepare(

    "SELECT q.question_id, q.category_id

     FROM tbl_questions q

     JOIN tbl_categories c ON q.category_id = c.category_id

     WHERE c.status = 1 AND c.event_id = ? AND {$votableSql}"

);

$questions->bind_param('i', $event_id);

$questions->execute();

$qRes = $questions->get_result();

$allQuestions = [];

while ($q = $qRes->fetch_assoc()) {

    $allQuestions[(int)$q['question_id']] = (int)$q['category_id'];

}

$questions->close();



$answered = [];



$res1 = $conn->prepare(

    'SELECT pc.question_id

     FROM tbl_poll_choice pc

     JOIN tbl_questions q ON pc.question_id = q.question_id

     JOIN tbl_categories c ON q.category_id = c.category_id

     WHERE pc.voters_id = ? AND c.status = 1 AND c.event_id = ?'

);

$res1->bind_param('ii', $voter_id, $event_id);

$res1->execute();

$r1 = $res1->get_result();

while ($row = $r1->fetch_assoc()) {

    $answered[] = (int)$row['question_id'];

}

$res1->close();



$res2 = $conn->prepare(

    'SELECT pf.question_id

     FROM tbl_poll_freetext pf

     JOIN tbl_questions q ON pf.question_id = q.question_id

     JOIN tbl_categories c ON q.category_id = c.category_id

     WHERE pf.voters_id = ? AND c.status = 1 AND c.event_id = ?'

);

$res2->bind_param('ii', $voter_id, $event_id);

$res2->execute();

$r2 = $res2->get_result();

while ($row = $r2->fetch_assoc()) {

    $answered[] = (int)$row['question_id'];

}

$res2->close();



$answered = array_unique($answered);



$unanswered = [];

foreach ($allQuestions as $qid => $catid) {

    if (!in_array($qid, $answered, true)) {

        $unanswered[] = [

            'question_id' => $qid,

            'category_id' => $catid,

        ];

    }

}



echo json_encode([

    'status' => 'success',

    'voter_info' => $voter,

    'unanswered_questions' => $unanswered,

    'completion_status' => empty($unanswered) ? 'completed' : 'incomplete',

], JSON_THROW_ON_ERROR);


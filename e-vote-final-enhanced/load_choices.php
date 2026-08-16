<?php
header('Content-Type: application/json');
require_once '../tocca_admin/db_connection.php';
require_once '../tocca_admin/includes/ballot_status.php';

if (isset($_GET['question_id'])) {
    $question_id = $_GET['question_id'];
    $onBallotSql = ballot_status_sql_and($conn, 'c');
    $awardBallotSql = ballot_award_sql_and($conn, 'qc');

    $stmt = $conn->prepare("
        SELECT c.choice_id, c.choice_name
        FROM tbl_choices c
        INNER JOIN tbl_question_choices qc ON c.choice_id = qc.choice_id
        WHERE qc.question_id = ? AND c.status = 1{$onBallotSql}{$awardBallotSql}
        ORDER BY c.choice_name ASC
    ");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $choices = [];
    while ($row = $result->fetch_assoc()) {
        $choices[] = $row;
    }

    echo json_encode(['status' => 'success', 'choices' => $choices]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Missing question_id']);
exit;
?>

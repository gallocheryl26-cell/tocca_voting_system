<?php
header('Content-Type: application/json');
require_once 'connection.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/../tocca_admin/includes/category_voting_profile.php';

category_voting_profile_ensure_schema($conn); 

$response = ['status' => 'error', 'message' => 'An unknown error occurred.'];
$categories = [];

if (!isset($conn) || $conn->connect_error) {
    $errorMessage = isset($conn) ? $conn->connect_error : 'Database connection object not found.';
    error_log('Database connection failed in get_all_categories.php: ' . $errorMessage);
    $response['message'] = 'Database connection failed.';
    http_response_code(500);
    echo json_encode($response);
    exit;
}

try {
    $votableSql = voter_flow_votable_question_sql($conn, 'q');
    $sql = "SELECT c.category_id, c.category_name, c.voting_profile
            FROM tbl_categories AS c
            INNER JOIN tbl_events AS e ON c.event_id = e.event_id
            WHERE c.status = 1 AND e.is_active = 1 AND COALESCE(e.is_archived, 0) = 0
              AND EXISTS (
                    SELECT 1
                    FROM tbl_questions q
                    WHERE q.category_id = c.category_id
                      AND {$votableSql}
              )
            ORDER BY c.category_name ASC";

    $result = $conn->query($sql);

    if ($result === false) {
        error_log('SQL error in get_all_categories.php: ' . $conn->error);
        $response['message'] = 'Failed to fetch categories from the database.';
        http_response_code(500);
    } else {
        while ($row = $result->fetch_assoc()) {
            $profile = category_voting_profile_from_row($row);
            $categories[] = [
                'id' => (int) $row['category_id'],
                'name' => $row['category_name'],
                'voting_profile' => $profile,
                'field_labels' => category_voting_profile_labels($profile),
            ];
        }
        $result->free();

        // Get the active event_id
        $sql_event = "SELECT event_id FROM tbl_events WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0 ORDER BY year DESC, event_id DESC LIMIT 1";
        $result_event = $conn->query($sql_event);
        $event_id = null;
        if ($result_event && $row_event = $result_event->fetch_assoc()) {
            $event_id = $row_event['event_id'];
        }
        if ($result_event) $result_event->free();

        $response = ['status' => 'success', 'categories' => $categories, 'event_id' => $event_id];
    }

} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log('General error in get_all_categories.php: ' . $e->getMessage());
    http_response_code(500); // Indicate server error
}

if (isset($conn) && !$conn->connect_error) {
    $conn->close();
}


echo json_encode($response);
?>

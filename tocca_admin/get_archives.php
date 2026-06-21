<?php
require_once __DIR__ . '/require_admin_api.php';

$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? '';
$type = $input['type'] ?? '';
$event_id = isset($input['event_id']) ? intval($input['event_id']) : null;

if ($action !== 'load_archive' || !$type || !$event_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid parameters.']);
    exit;
}

$html = '';
$status = 'success';

function wrapWithCard($title, $contentHtml) {
    return "
    <div class='card mb-4'>
      <div class='card-header d-flex justify-content-between align-items-center'>
        <div><i class='fas fa-table me-1'></i>{$title}</div>
        <div id='archiveDataTableButtons'></div>
      </div>
      <div class='card-body px-0'>
        <div class='table-responsive w-100'>
          {$contentHtml}
        </div>
      </div>
    </div>";
}

switch ($type) {
    case 'categories':
        $result = $conn->query("SELECT category_name FROM tbl_categories WHERE event_id = $event_id");
        if ($result->num_rows > 0) {
            $list = "<ul class='list-group'>";
            while ($row = $result->fetch_assoc()) {
                $list .= "<li class='list-group-item'>{$row['category_name']}</li>";
            }
            $list .= "</ul>";
            $html = wrapWithCard("Archived Categories", $list);
        } else {
            $html = "<p>No archived categories found.</p>";
        }
        break;

case 'questions':
    $result = $conn->query("
        SELECT q.question_name
        FROM tbl_questions q
        JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE c.event_id = $event_id
    ");
    if ($result->num_rows > 0) {
        $list = "<ul class='list-group'>";
        while ($row = $result->fetch_assoc()) {
            $list .= "<li class='list-group-item'>{$row['question_name']}</li>";
        }
        $list .= "</ul>";
        $html = wrapWithCard("Archived Questions", $list);
    } else {
        $html = "<p>No archived questions found.</p>";
    }
    break;



    case 'choices':
        $result = $conn->query("SELECT choice_name FROM tbl_choices WHERE event_id = $event_id");
        if ($result->num_rows > 0) {
            $list = "<ul class='list-group'>";
            while ($row = $result->fetch_assoc()) {
                $list .= "<li class='list-group-item'>{$row['choice_name']}</li>";
            }
            $list .= "</ul>";
            $html = wrapWithCard("Archived Choices", $list);
        } else {
            $html = "<p>No archived choices found.</p>";
        }
        break;

    case 'results':
        $sql = "
            SELECT c.choice_name, COUNT(p.choice_id) as vote_count
            FROM tbl_poll_choice p
            JOIN tbl_choices c ON p.choice_id = c.choice_id
            WHERE p.event_id = $event_id
            GROUP BY p.choice_id
            ORDER BY vote_count DESC
        ";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $table = "
            <table id='archiveDataTable' class='table table-striped table-bordered w-100'>
              <thead class='table-dark'>
                <tr>
                  <th>Choice</th>
                  <th>Votes</th>
                  <th>Standing</th>
                </tr>
              </thead>
              <tbody>";
            $rank = 1;
            while ($row = $result->fetch_assoc()) {
                $standing = $rank === 1 ? "Leading" : "Runner-Up";
                $badge = $rank === 1 ? "success" : "secondary";
                $table .= "<tr>
                  <td>{$row['choice_name']}</td>
                  <td>{$row['vote_count']}</td>
                  <td><span class='badge bg-{$badge}'>{$standing}</span></td>
                </tr>";
                $rank++;
            }
            $table .= "</tbody></table>";
            $html = wrapWithCard("Archived Results", $table);
        } else {
            $html = "<p>No archived results found.</p>";
        }
        break;

    case 'voters':
    $result = $conn->query("SELECT mobile_number, date_verified, has_voted FROM tbl_voters WHERE is_archived = 1");
    if ($result->num_rows > 0) {
        $table = "
        <table id='archiveDataTable' class='table table-striped table-bordered w-100'>
          <thead class='table-dark'>
            <tr>
              <th>Mobile Number</th>
              <th>Date Verified</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = $result->fetch_assoc()) {
            $statusLabel = ((int)$row['has_voted'] === 1) ? 'completed' : 'drafted';
            $formattedDate = $row['date_verified'] ? date("m/d/Y", strtotime($row['date_verified'])) : '-';
            $table .= "<tr>
              <td>{$row['mobile_number']}</td>
              <td>{$formattedDate}</td>
              <td>{$statusLabel}</td>
            </tr>";
        }
        $table .= "</tbody></table>";
        $html = wrapWithCard("Archived Voters", $table);
    } else {
        $html = "<p>No archived voters found.</p>";
    }
    break;

    default:
        $status = 'error';
        $html = '<p>Invalid archive type.</p>';
        break;
}

echo json_encode([
    'status' => $status,
    'html' => $html
]);
exit;

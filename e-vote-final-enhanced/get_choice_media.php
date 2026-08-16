<?php
/**
 * Public (voter-facing) endpoint that returns media items uploaded for a given
 * establishment (choice). Used by the voting UI to preview a business's photos
 * and videos before the voter selects them.
 *
 * Only media for ACTIVE choices is exposed. Paths are returned as URLs that
 * resolve correctly from the e-vote-final-enhanced directory.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/voter_session.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/ballot_status.php';

$choiceId = isset($_GET['choice_id']) ? (int)$_GET['choice_id'] : 0;
if ($choiceId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid choice_id.']);
    exit;
}

// Soft-create the table so legacy installs don't blow up if the admin hasn't
// uploaded any media yet. SELECT against a missing table would otherwise raise.
$conn->query(
    "CREATE TABLE IF NOT EXISTS tbl_choice_media (
        id INT NOT NULL AUTO_INCREMENT,
        choice_id INT NOT NULL,
        media_type ENUM('image','video') NOT NULL DEFAULT 'image',
        file_path VARCHAR(500) NOT NULL,
        caption VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_choice_media_choice (choice_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

try {
    $onBallotSql = ballot_status_sql_and($conn, 'c');
    $stmt = $conn->prepare(
        "SELECT m.id, m.media_type, m.file_path, m.caption, m.sort_order
         FROM tbl_choice_media m
         JOIN tbl_choices c ON c.choice_id = m.choice_id
         WHERE m.choice_id = ? AND c.status = 1{$onBallotSql}
         ORDER BY m.sort_order ASC, m.id ASC"
    );
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    $res = $stmt->get_result();

    // Voter pages live at e-vote-final-enhanced/, admin media lives at
    // tocca_admin/uploads/choice_media/...  We expose a relative URL that
    // works for both an <img src="..."> and a <video src="..."> tag.
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $rel = ltrim((string)$row['file_path'], '/');
        $items[] = [
            'id'         => (int)$row['id'],
            'media_type' => (string)$row['media_type'],
            'url'        => '../tocca_admin/' . $rel,
            'caption'    => $row['caption'] !== null ? (string)$row['caption'] : '',
            'sort_order' => (int)$row['sort_order'],
        ];
    }
    $stmt->close();

    // Fetch the business name so the modal can render a clear header.
    $name = '';
    $stmt = $conn->prepare('SELECT choice_name FROM tbl_choices WHERE choice_id = ? LIMIT 1');
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $name = (string)$row['choice_name'];
    }
    $stmt->close();

    echo json_encode([
        'status'      => 'success',
        'choice_id'   => $choiceId,
        'choice_name' => $name,
        'data'        => $items,
    ]);
} catch (Throwable $e) {
    error_log('get_choice_media.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

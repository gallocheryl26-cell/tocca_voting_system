<?php
require_once '../tocca_admin/db_connection.php';
$query = "
    SELECT 
        f.feedback_id,
        f.feedback_type,
        f.voters_id,
        v.mobile_number AS voter_mobile,
        f.nomination_id,
        n.business_name AS nominee_business,
        f.event_id,
        f.feedback,
        f.submitted_at
    FROM tbl_feedback f
    LEFT JOIN tbl_voters v ON f.voters_id = v.voters_id AND f.feedback_type = 'voter'
    LEFT JOIN tbl_nominations n ON f.nomination_id = n.nomination_id AND f.feedback_type = 'nominee'
    ORDER BY f.submitted_at DESC
";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Voter Feedbacks</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<div class="container mt-4">
  <h2>All Feedbacks (Voters & Businesses)</h2>
  <table class="table table-bordered">
    <thead>
      <tr>
        <th>Feedback ID</th>
        <th>Type</th>
        <th>Voter Mobile</th>
        <th>Business</th>
        <th>Event ID</th>
        <th>Feedback</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($row['feedback_id']) ?></td>
        <td><?= htmlspecialchars($row['feedback_type']) ?></td>
        <td><?= $row['feedback_type'] === 'voter' ? htmlspecialchars($row['voter_mobile'] ?? 'N/A') : '-' ?></td>
        <td><?= $row['feedback_type'] === 'nominee' ? htmlspecialchars($row['nominee_business'] ?? 'N/A') : '-' ?></td>
        <td><?= htmlspecialchars($row['event_id']) ?></td>
        <td><?= nl2br(htmlspecialchars($row['feedback'])) ?></td>
        <td><?= htmlspecialchars($row['submitted_at']) ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>
</body>
</html>
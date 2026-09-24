<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/e-vote-final-enhanced/connection.php';

echo "PHP " . PHP_VERSION . " mysqli report=" . mysqli_report(MYSQLI_REPORT_OFF) . "\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

echo "==== tbl_choices columns ====\n";
$r = $conn->query('SHOW COLUMNS FROM tbl_choices');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . "\n";
}

echo "==== SHOW COLUMNS LIKE on_ballot (wildcard) ====\n";
$r = $conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'on_ballot'");
echo "rows=" . $r->num_rows . "\n";
while ($row = $r->fetch_assoc()) {
    echo "matched:" . $row['Field'] . "\n";
}

echo "==== SHOW COLUMNS LIKE escaped ====\n";
$r = $conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'on\\_ballot'");
echo "rows=" . $r->num_rows . "\n";
while ($row = $r->fetch_assoc()) {
    echo "matched:" . $row['Field'] . "\n";
}

echo "==== tbl_voters columns ====\n";
$r = $conn->query('SHOW COLUMNS FROM tbl_voters');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . "\n";
}

echo "==== POST-like gate ====\n";
require_once dirname(__DIR__) . '/e-vote-final-enhanced/lib/voter_flow.php';
try {
    $out = voter_flow_mobile_gate_status($conn, '09123456789');
    echo json_encode($out) . "\n";
} catch (Throwable $e) {
    echo "GATE EX: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}

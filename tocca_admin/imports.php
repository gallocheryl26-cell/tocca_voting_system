<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// --- Load the Excel file ---
$spreadsheet = IOFactory::load(__DIR__ . '/../uploads/unified_import_template.xlsx');

$questionKeyToIdMap = []; // Store Q1 => question_id

foreach ($spreadsheet->getSheetNames() as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    $rows = $sheet->toArray();

    // --- If it's a Q1/Q2/etc sheet => choices ---
    if (preg_match('/^Q\d+$/', $sheetName)) {
        $questionKey = $sheetName;
        if (!isset($questionKeyToIdMap[$questionKey])) continue; // No linked question

        $question_id = $questionKeyToIdMap[$questionKey];

        foreach ($rows as $index => $row) {
            if ($index === 0) continue; // skip header

            $choice_name = $row[0];
            $email = $row[1] ?? null;
            $status = isset($row[2]) ? (int)$row[2] : 1;

            // Check if choice already exists (optional)
            $check = $conn->prepare("SELECT choice_id FROM tbl_choices WHERE choice_name = ?");
            $check->bind_param("s", $choice_name);
            $check->execute();
            $check->store_result();

            if ($check->num_rows === 0) {
                $insert = $conn->prepare("INSERT INTO tbl_choices (choice_name, email, status) VALUES (?, ?, ?)");
                $insert->bind_param("ssi", $choice_name, $email, $status);
                $insert->execute();
                $choice_id = $insert->insert_id;
            } else {
                $check->bind_result($choice_id);
                $check->fetch();
            }

            // Link to question
            $link = $conn->prepare("INSERT INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)");
            $link->bind_param("ii", $question_id, $choice_id);
            $link->execute();
        }

    } else {
        // --- It's a category sheet with questions ---
        $category_name = $sheetName;

        // Insert or get category_id
        $stmt = $conn->prepare("SELECT category_id FROM tbl_categories WHERE category_name = ?");
        $stmt->bind_param("s", $category_name);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $insertCat = $conn->prepare("INSERT INTO tbl_categories (category_name) VALUES (?)");
            $insertCat->bind_param("s", $category_name);
            $insertCat->execute();
            $category_id = $insertCat->insert_id;
        } else {
            $stmt->bind_result($category_id);
            $stmt->fetch();
        }

        foreach ($rows as $index => $row) {
            if ($index === 0) continue; // skip header

            $question_key = $row[0];
            $question_name = $row[1];
            // Voting only supports establishment Options — ignore Freeform from legacy templates.
            $choice_type = 1;

            // Insert question
            $insertQ = $conn->prepare("INSERT INTO tbl_questions (question_name, category_id, choice_type) VALUES (?, ?, ?)");
            $insertQ->bind_param("sii", $question_name, $category_id, $choice_type);
            $insertQ->execute();

            $question_id = $insertQ->insert_id;
            $questionKeyToIdMap[$question_key] = $question_id; // Store Q1 => id
        }
    }
}

echo "✅ Unified import completed: Categories, Questions, and Choices all inserted!";
?>

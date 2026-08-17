<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/import_excel_helpers.php';
require_once __DIR__ . '/includes/public_slugs.php';
require_once __DIR__ . '/audit_log.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function import_redirect_error(string $message, array $warnings = []): void
{
    $_SESSION['import_flash'] = [
        'type'          => 'danger',
        'title'         => 'Import failed',
        'event_label'   => '',
        'rows'          => [],
        'skipped_links' => 0,
        'warnings'      => array_merge([$message], $warnings),
    ];
    header('Location: system_utilities.php');
    exit;
}

function import_redirect_success(array $flash): void
{
    $_SESSION['import_flash'] = $flash;
    header('Location: system_utilities.php');
    exit;
}

/**
 * @return array<string, mixed>
 */
function import_run_legacy(Spreadsheet $spreadsheet, mysqli $conn, int $activeEventId, string $eventLabel): array
{
    $questionKeyToIdMap = [];
    $insertedCategories = 0;
    $insertedAwards = 0;
    $insertedEstablishments = 0;
    $linkedEstablishments = 0;
    $skippedLinks = 0;
    $reassignedEstablishments = 0;
    $warnings = [];

    foreach ($spreadsheet->getSheetNames() as $sheetName) {
        if (preg_match('/^[A-Z]{1,3}\d+$/', $sheetName) || import_is_reserved_sheet($sheetName)) {
            continue;
        }

        $sheet = $spreadsheet->getSheetByName($sheetName);
        if ($sheet === null) {
            continue;
        }

        $rows = $sheet->toArray();
        $category_name = trim($sheetName);
        if ($category_name === '') {
            continue;
        }

        $stmt = $conn->prepare('SELECT category_id FROM tbl_categories WHERE category_name = ? AND event_id = ?');
        $stmt->bind_param('si', $category_name, $activeEventId);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $insertCat = $conn->prepare('INSERT INTO tbl_categories (category_name, event_id) VALUES (?, ?)');
            $insertCat->bind_param('si', $category_name, $activeEventId);
            $insertCat->execute();
            $category_id = (int) $insertCat->insert_id;
            $insertCat->close();
            $insertedCategories++;
        } else {
            $stmt->bind_result($category_id);
            $stmt->fetch();
            $category_id = (int) $category_id;
        }
        $stmt->close();

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $question_key = trim((string) ($row[0] ?? ''));
            $question_name = trim((string) ($row[1] ?? ''));
            // Voting only supports establishment Options — ignore Freeform from legacy templates.
            $choice_type = 1;

            if ($question_key === '' || $question_name === '') {
                continue;
            }

            $checkQ = $conn->prepare('SELECT question_id FROM tbl_questions WHERE question_name = ? AND category_id = ?');
            $checkQ->bind_param('si', $question_name, $category_id);
            $checkQ->execute();
            $checkQ->store_result();

            if ($checkQ->num_rows === 0) {
                $insertQ = $conn->prepare('INSERT INTO tbl_questions (question_name, category_id, choice_type) VALUES (?, ?, ?)');
                $insertQ->bind_param('sii', $question_name, $category_id, $choice_type);
                $insertQ->execute();
                $question_id = (int) $insertQ->insert_id;
                $insertQ->close();
                $insertedAwards++;
            } else {
                $checkQ->bind_result($question_id);
                $checkQ->fetch();
                $question_id = (int) $question_id;
            }
            $checkQ->close();

            $questionKeyToIdMap[strtoupper($question_key)] = $question_id;
        }
    }

    foreach ($spreadsheet->getSheetNames() as $sheetName) {
        if (!preg_match('/^[A-Z]{1,3}\d+$/', $sheetName)) {
            continue;
        }

        $sheet = $spreadsheet->getSheetByName($sheetName);
        if ($sheet === null) {
            continue;
        }

        $questionKey = strtoupper(trim($sheetName));
        if (!isset($questionKeyToIdMap[$questionKey])) {
            $warnings[] = "Sheet \"{$questionKey}\" was skipped because no matching award key was found in a category sheet.";
            continue;
        }

        $question_id = $questionKeyToIdMap[$questionKey];
        $rows = $sheet->toArray();

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $choice_name = trim((string) ($row[0] ?? ''));
            $email = isset($row[1]) ? trim((string) $row[1]) : '';
            $email = $email !== '' ? $email : null;
            $status = isset($row[2]) && $row[2] !== '' ? (int) $row[2] : 1;

            if ($choice_name === '') {
                continue;
            }

            $check = $conn->prepare('SELECT choice_id, event_id FROM tbl_choices WHERE choice_name = ? LIMIT 1');
            $check->bind_param('s', $choice_name);
            $check->execute();
            $check->store_result();

            if ($check->num_rows === 0) {
                $stmt = $conn->prepare('INSERT INTO tbl_choices (choice_name, email, status, event_id) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('ssii', $choice_name, $email, $status, $activeEventId);
                $stmt->execute();
                $choice_id = (int) $stmt->insert_id;
                $stmt->close();
                $insertedEstablishments++;
                if (function_exists('public_slug_for_choice')) {
                    public_slug_for_choice($conn, $choice_id);
                }
            } else {
                $check->bind_result($choice_id, $existingEventId);
                $check->fetch();
                $choice_id = (int) $choice_id;
                $existingEventId = (int) $existingEventId;

                if ($existingEventId !== $activeEventId) {
                    $upd = $conn->prepare('UPDATE tbl_choices SET event_id = ?, email = COALESCE(?, email), status = ? WHERE choice_id = ?');
                    $upd->bind_param('isii', $activeEventId, $email, $status, $choice_id);
                    $upd->execute();
                    $upd->close();
                    $reassignedEstablishments++;
                } elseif ($email !== null) {
                    $upd = $conn->prepare('UPDATE tbl_choices SET email = ?, status = ? WHERE choice_id = ?');
                    $upd->bind_param('sii', $email, $status, $choice_id);
                    $upd->execute();
                    $upd->close();
                }
            }
            $check->close();

            $checkLink = $conn->prepare('SELECT id FROM tbl_question_choices WHERE question_id = ? AND choice_id = ?');
            $checkLink->bind_param('ii', $question_id, $choice_id);
            $checkLink->execute();
            $checkLink->store_result();

            if ($checkLink->num_rows === 0) {
                $link = $conn->prepare('INSERT INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)');
                $link->bind_param('ii', $question_id, $choice_id);
                $link->execute();
                $link->close();
                $linkedEstablishments++;
            } else {
                $skippedLinks++;
            }
            $checkLink->close();
        }
    }

    if ($reassignedEstablishments > 0) {
        $warnings[] = "{$reassignedEstablishments} existing business(es) were moved to this active event (same name found in another event).";
    }

    $totalNew = $insertedCategories + $insertedAwards + $insertedEstablishments + $linkedEstablishments;

    return [
        'type'          => $totalNew > 0 ? 'success' : 'warning',
        'title'         => $totalNew > 0 ? 'Import complete (legacy format)' : 'Nothing new to import',
        'event_label'   => $eventLabel,
        'rows'          => [
            ['label' => 'Categories', 'count' => $insertedCategories],
            ['label' => 'Name of awards', 'count' => $insertedAwards],
            ['label' => 'Businesses (new)', 'count' => $insertedEstablishments],
            ['label' => 'Award links', 'count' => $linkedEstablishments],
        ],
        'skipped_links' => $skippedLinks,
        'warnings'      => $warnings,
    ];
}

/**
 * @return array{errors: list<string>, categories: list<array<string, string>>, awards: list<array<string, string>>, types: list<array<string, string>>, establishments: list<array<string, string>>}
 */
function import_validate_unified(Spreadsheet $spreadsheet, mysqli $conn): array
{
    $errors = [];

    $categoriesSheet = import_find_sheet($spreadsheet, ['Categories']);
    $awardsSheet = import_find_sheet($spreadsheet, ['Awards']);
    $typesSheet = import_find_sheet($spreadsheet, ['Establishment Types', 'Establishment_Types', 'Business Categories', 'Business Category', 'Nature of Business', 'Nature of Businesses']);
    $establishmentsSheet = import_find_sheet($spreadsheet, ['Establishments', 'Businesses']);

    if ($categoriesSheet === null) {
        $errors[] = 'Missing required sheet "Categories".';
    }
    if ($awardsSheet === null) {
        $errors[] = 'Missing required sheet "Awards".';
    }
    if ($establishmentsSheet === null) {
        $errors[] = 'Missing required sheet "Businesses" (also accepts "Establishments").';
    }
    if ($errors !== []) {
        return ['errors' => $errors, 'categories' => [], 'awards' => [], 'types' => [], 'establishments' => []];
    }

    $parsedCategories = import_parse_sheet_by_header($categoriesSheet->toArray(), ['category_name']);
    $parsedAwards = import_parse_sheet_by_header($awardsSheet->toArray(), ['award_key', 'category_name', 'award_name']);
    $parsedTypes = $typesSheet !== null
        ? import_parse_sheet_by_header($typesSheet->toArray(), ['type_name', 'award_keys'])
        : ['headers' => [], 'data' => []];
    $parsedEstablishments = import_parse_sheet_by_header(
        $establishmentsSheet->toArray(),
        ['establishment_name', 'award_key']
    );

    $errors = array_merge(
        $errors,
        import_require_columns($parsedCategories['headers'], ['category_name'], 'Categories'),
        import_require_columns($parsedAwards['headers'], ['award_key', 'category_name', 'award_name'], 'Awards'),
        import_require_columns($parsedEstablishments['headers'], ['establishment_name', 'award_key'], 'Businesses')
    );

    $hasTypesTable = import_table_exists($conn, 'tbl_establishment_types');
    $hasTypeAwardMap = import_table_exists($conn, 'tbl_establishment_type_awards');
    if ($typesSheet !== null && $hasTypesTable && $hasTypeAwardMap) {
        $errors = array_merge(
            $errors,
            import_require_columns($parsedTypes['headers'], ['type_name', 'award_keys'], 'Nature of Business')
        );
    }

    $categoryNames = [];
    foreach ($parsedCategories['data'] as $row) {
        $name = trim($row['category_name'] ?? '');
        $line = $row['_line'] ?? '?';
        if ($name === '') {
            $errors[] = "Categories row {$line}: category_name is required.";
            continue;
        }
        $key = strtolower($name);
        if (isset($categoryNames[$key])) {
            $errors[] = "Categories row {$line}: duplicate category \"{$name}\".";
        }
        $categoryNames[$key] = $name;
    }

    $awardKeyMap = [];
    $awardCategoryMap = [];
    foreach ($parsedAwards['data'] as $row) {
        $line = $row['_line'] ?? '?';
        $awardKey = strtoupper(trim($row['award_key'] ?? ''));
        $categoryName = trim($row['category_name'] ?? '');
        $awardName = trim($row['award_name'] ?? '');

        if ($awardKey === '' || $categoryName === '' || $awardName === '') {
            $errors[] = "Awards row {$line}: award_key, category_name, and award_name are required.";
            continue;
        }
        if (!isset($categoryNames[strtolower($categoryName)])) {
            $errors[] = "Awards row {$line}: unknown category \"{$categoryName}\" (define it on the Categories sheet).";
        }
        if (isset($awardKeyMap[$awardKey])) {
            $errors[] = "Awards row {$line}: duplicate award_key \"{$awardKey}\".";
            continue;
        }
        $awardKeyMap[$awardKey] = true;
        $awardCategoryMap[$awardKey] = $categoryName;
    }

    $typeAwardMap = [];
    $typeNameMap = [];
    if ($typesSheet !== null && $parsedTypes['data'] !== []) {
        if (!$hasTypesTable || !$hasTypeAwardMap) {
            $errors[] = 'Nature of Business sheet was found but tbl_establishment_types is not available in this database.';
        } else {
            foreach ($parsedTypes['data'] as $row) {
                $line = $row['_line'] ?? '?';
                $typeName = trim($row['type_name'] ?? '');
                if ($typeName === '') {
                    $errors[] = "Nature of Business row {$line}: type_name is required.";
                    continue;
                }
                $keys = import_split_keys($row['award_keys'] ?? '');
                if ($keys === []) {
                    $errors[] = "Nature of Business row {$line}: at least one award_key is required for \"{$typeName}\".";
                    continue;
                }
                if (isset($typeNameMap[strtolower($typeName)])) {
                    $errors[] = "Nature of Business row {$line}: duplicate type \"{$typeName}\".";
                }
                $typeNameMap[strtolower($typeName)] = $typeName;
                foreach ($keys as $key) {
                    if (!isset($awardKeyMap[$key])) {
                        $errors[] = "Nature of Business row {$line}: unknown award_key \"{$key}\" for type \"{$typeName}\".";
                    }
                }
                $typeAwardMap[strtolower($typeName)] = $keys;
            }
        }
    }

    foreach ($parsedEstablishments['data'] as $row) {
        $line = $row['_line'] ?? '?';
        $establishmentName = trim($row['establishment_name'] ?? '');
        $awardKey = strtoupper(trim($row['award_key'] ?? ''));
        $typeName = trim($row['establishment_type'] ?? '');

        if ($establishmentName === '' || $awardKey === '') {
            $errors[] = "Businesses row {$line}: establishment_name and award_key are required.";
            continue;
        }
        if (!isset($awardKeyMap[$awardKey])) {
            $errors[] = "Businesses row {$line}: unknown award_key \"{$awardKey}\".";
            continue;
        }
        if ($typeName !== '') {
            if ($typeNameMap === []) {
                $errors[] = "Businesses row {$line}: establishment_type \"{$typeName}\" provided but Nature of Business sheet is empty or missing.";
            } elseif (!isset($typeNameMap[strtolower($typeName)])) {
                $errors[] = "Businesses row {$line}: unknown establishment_type \"{$typeName}\".";
            } elseif (!in_array($awardKey, $typeAwardMap[strtolower($typeName)] ?? [], true)) {
                $errors[] = "Businesses row {$line}: award_key \"{$awardKey}\" is not allowed for establishment_type \"{$typeName}\".";
            }
        }
    }

    $dataRowCount = count($parsedCategories['data'])
        + count($parsedAwards['data'])
        + count($parsedEstablishments['data']);
    $skippedExamples = (int) ($parsedCategories['skipped_examples'] ?? 0)
        + (int) ($parsedAwards['skipped_examples'] ?? 0)
        + (int) ($parsedTypes['skipped_examples'] ?? 0)
        + (int) ($parsedEstablishments['skipped_examples'] ?? 0);

    if ($dataRowCount === 0) {
        if ($skippedExamples > 0) {
            $errors[] = 'No data to import: every filled row still contains “(EXAMPLE)” in a cell. Those rows are sample-only and are skipped. Remove “(EXAMPLE)” from the names or replace the sample rows with your real data.';
        } else {
            $errors[] = 'No data to import: add at least one row below the header on Categories, Awards, and Businesses (row 2 is the column header; data starts on row 3).';
        }
    }

    return [
        'errors'          => $errors,
        'categories'      => $parsedCategories['data'],
        'awards'          => $parsedAwards['data'],
        'types'           => $parsedTypes['data'],
        'establishments'  => $parsedEstablishments['data'],
        'category_names'  => $categoryNames,
        'award_keys'      => $awardKeyMap,
        'type_award_map'  => $typeAwardMap,
    ];
}

/**
 * @param array<string, mixed> $validated
 * @return array<string, mixed>
 */
function import_run_unified(array $validated, Spreadsheet $spreadsheet, mysqli $conn, int $activeEventId, string $eventLabel): array
{
    $insertedCategories = 0;
    $insertedAwards = 0;
    $insertedTypes = 0;
    $linkedTypeAwards = 0;
    $insertedEstablishments = 0;
    $linkedEstablishments = 0;
    $skippedLinks = 0;
    $reassignedEstablishments = 0;
    $warnings = [];

    $categoryIdByName = [];
    $awardIdByKey = [];
    $typeIdByName = [];

    $hasTypesTable = import_table_exists($conn, 'tbl_establishment_types');
    $hasTypeAwardMap = import_table_exists($conn, 'tbl_establishment_type_awards');
    $hasChoiceTypeColumn = import_column_exists($conn, 'tbl_choices', 'establishment_type_id');
    $typesHasCreatedAt = import_column_exists($conn, 'tbl_establishment_types', 'created_at');

    foreach ($validated['categories'] as $row) {
        $category_name = trim($row['category_name'] ?? '');
        $status = isset($row['status']) && $row['status'] !== '' ? (int) $row['status'] : 1;

        $stmt = $conn->prepare('SELECT category_id FROM tbl_categories WHERE category_name = ? AND event_id = ?');
        $stmt->bind_param('si', $category_name, $activeEventId);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $insertCat = $conn->prepare('INSERT INTO tbl_categories (category_name, event_id, status) VALUES (?, ?, ?)');
            $insertCat->bind_param('sii', $category_name, $activeEventId, $status);
            $insertCat->execute();
            $category_id = (int) $insertCat->insert_id;
            $insertCat->close();
            $insertedCategories++;
        } else {
            $stmt->bind_result($category_id);
            $stmt->fetch();
            $category_id = (int) $category_id;
            $upd = $conn->prepare('UPDATE tbl_categories SET status = ? WHERE category_id = ?');
            $upd->bind_param('ii', $status, $category_id);
            $upd->execute();
            $upd->close();
        }
        $stmt->close();
        $categoryIdByName[strtolower($category_name)] = $category_id;
    }

    foreach ($validated['awards'] as $row) {
        $awardKey = strtoupper(trim($row['award_key'] ?? ''));
        $categoryName = trim($row['category_name'] ?? '');
        $awardName = trim($row['award_name'] ?? '');
        // Voting only supports establishment Options — ignore Freeform from legacy templates.
        $choice_type = 1;

        $category_id = $categoryIdByName[strtolower($categoryName)] ?? null;
        if ($category_id === null) {
            continue;
        }

        $checkQ = $conn->prepare('SELECT question_id FROM tbl_questions WHERE question_name = ? AND category_id = ?');
        $checkQ->bind_param('si', $awardName, $category_id);
        $checkQ->execute();
        $checkQ->store_result();

        if ($checkQ->num_rows === 0) {
            $insertQ = $conn->prepare('INSERT INTO tbl_questions (question_name, category_id, choice_type) VALUES (?, ?, ?)');
            $insertQ->bind_param('sii', $awardName, $category_id, $choice_type);
            $insertQ->execute();
            $question_id = (int) $insertQ->insert_id;
            $insertQ->close();
            $insertedAwards++;
        } else {
            $checkQ->bind_result($question_id);
            $checkQ->fetch();
            $question_id = (int) $question_id;
            $upd = $conn->prepare('UPDATE tbl_questions SET choice_type = ? WHERE question_id = ?');
            $upd->bind_param('ii', $choice_type, $question_id);
            $upd->execute();
            $upd->close();
        }
        $checkQ->close();
        $awardIdByKey[$awardKey] = $question_id;
    }

    if ($hasTypesTable && $hasTypeAwardMap && $validated['types'] !== []) {
        foreach ($validated['types'] as $row) {
            $typeName = trim($row['type_name'] ?? '');
            $status = isset($row['status']) && $row['status'] !== '' ? (int) $row['status'] : 1;
            $awardKeys = import_split_keys($row['award_keys'] ?? '');

            $stmt = $conn->prepare('SELECT type_id FROM tbl_establishment_types WHERE LOWER(type_name) = LOWER(?) LIMIT 1');
            $stmt->bind_param('s', $typeName);
            $stmt->execute();
            $res = $stmt->get_result();
            $existing = $res->fetch_assoc();
            $stmt->close();

            if (!$existing) {
                if ($typesHasCreatedAt) {
                    $ins = $conn->prepare('INSERT INTO tbl_establishment_types (type_name, status, created_at) VALUES (?, ?, NOW())');
                } else {
                    $ins = $conn->prepare('INSERT INTO tbl_establishment_types (type_name, status) VALUES (?, ?)');
                }
                $ins->bind_param('si', $typeName, $status);
                $ins->execute();
                $typeId = (int) $ins->insert_id;
                $ins->close();
                $insertedTypes++;
            } else {
                $typeId = (int) $existing['type_id'];
                $upd = $conn->prepare('UPDATE tbl_establishment_types SET status = ? WHERE type_id = ?');
                $upd->bind_param('ii', $status, $typeId);
                $upd->execute();
                $upd->close();
            }
            $typeIdByName[strtolower($typeName)] = $typeId;

            foreach ($awardKeys as $awardKey) {
                $questionId = $awardIdByKey[$awardKey] ?? null;
                if ($questionId === null) {
                    continue;
                }
                $check = $conn->prepare(
                    'SELECT 1 FROM tbl_establishment_type_awards WHERE type_id = ? AND question_id = ? LIMIT 1'
                );
                $check->bind_param('ii', $typeId, $questionId);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();
                if (!$exists) {
                    $link = $conn->prepare('INSERT INTO tbl_establishment_type_awards (type_id, question_id) VALUES (?, ?)');
                    $link->bind_param('ii', $typeId, $questionId);
                    $link->execute();
                    $link->close();
                    $linkedTypeAwards++;
                }
            }
        }
    } elseif ($validated['types'] !== [] && (!$hasTypesTable || !$hasTypeAwardMap)) {
        $warnings[] = 'Nature of Business sheet was skipped because the database tables are not available.';
    }

    $choiceCache = [];
    foreach ($validated['establishments'] as $row) {
        $choice_name = trim($row['establishment_name'] ?? '');
        $email = trim($row['email'] ?? '');
        $email = $email !== '' ? $email : null;
        $status = isset($row['status']) && $row['status'] !== '' ? (int) $row['status'] : 1;
        $typeName = trim($row['establishment_type'] ?? '');
        $awardKey = strtoupper(trim($row['award_key'] ?? ''));
        $typeId = $typeName !== '' ? ($typeIdByName[strtolower($typeName)] ?? null) : null;
        $question_id = $awardIdByKey[$awardKey] ?? null;

        if ($question_id === null) {
            continue;
        }

        $cacheKey = strtolower($choice_name);
        if (!isset($choiceCache[$cacheKey])) {
            $check = $conn->prepare('SELECT choice_id, event_id FROM tbl_choices WHERE choice_name = ? LIMIT 1');
            $check->bind_param('s', $choice_name);
            $check->execute();
            $check->store_result();

            if ($check->num_rows === 0) {
                if ($hasChoiceTypeColumn && $typeId !== null) {
                    $stmt = $conn->prepare(
                        'INSERT INTO tbl_choices (choice_name, email, status, event_id, establishment_type_id) VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('ssiii', $choice_name, $email, $status, $activeEventId, $typeId);
                } else {
                    $stmt = $conn->prepare('INSERT INTO tbl_choices (choice_name, email, status, event_id) VALUES (?, ?, ?, ?)');
                    $stmt->bind_param('ssii', $choice_name, $email, $status, $activeEventId);
                }
                $stmt->execute();
                $choice_id = (int) $stmt->insert_id;
                $stmt->close();
                $insertedEstablishments++;
                if (function_exists('public_slug_for_choice')) {
                    public_slug_for_choice($conn, $choice_id);
                }
            } else {
                $check->bind_result($choice_id, $existingEventId);
                $check->fetch();
                $choice_id = (int) $choice_id;
                $existingEventId = (int) $existingEventId;

                if ($existingEventId !== $activeEventId) {
                    if ($hasChoiceTypeColumn && $typeId !== null) {
                        $upd = $conn->prepare(
                            'UPDATE tbl_choices SET event_id = ?, email = COALESCE(?, email), status = ?, establishment_type_id = ? WHERE choice_id = ?'
                        );
                        $upd->bind_param('isiii', $activeEventId, $email, $status, $typeId, $choice_id);
                    } else {
                        $upd = $conn->prepare('UPDATE tbl_choices SET event_id = ?, email = COALESCE(?, email), status = ? WHERE choice_id = ?');
                        $upd->bind_param('isii', $activeEventId, $email, $status, $choice_id);
                    }
                    $upd->execute();
                    $upd->close();
                    $reassignedEstablishments++;
                } else {
                    if ($hasChoiceTypeColumn && $typeId !== null) {
                        $upd = $conn->prepare('UPDATE tbl_choices SET email = COALESCE(?, email), status = ?, establishment_type_id = ? WHERE choice_id = ?');
                        $upd->bind_param('siii', $email, $status, $typeId, $choice_id);
                    } elseif ($email !== null) {
                        $upd = $conn->prepare('UPDATE tbl_choices SET email = ?, status = ? WHERE choice_id = ?');
                        $upd->bind_param('sii', $email, $status, $choice_id);
                    } else {
                        $upd = $conn->prepare('UPDATE tbl_choices SET status = ? WHERE choice_id = ?');
                        $upd->bind_param('ii', $status, $choice_id);
                    }
                    $upd->execute();
                    $upd->close();
                }
            }
            $check->close();
            $choiceCache[$cacheKey] = $choice_id;
        }

        $choice_id = $choiceCache[$cacheKey];
        $checkLink = $conn->prepare('SELECT id FROM tbl_question_choices WHERE question_id = ? AND choice_id = ?');
        $checkLink->bind_param('ii', $question_id, $choice_id);
        $checkLink->execute();
        $checkLink->store_result();

        if ($checkLink->num_rows === 0) {
            $link = $conn->prepare('INSERT INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)');
            $link->bind_param('ii', $question_id, $choice_id);
            $link->execute();
            $link->close();
            $linkedEstablishments++;
        } else {
            $skippedLinks++;
        }
        $checkLink->close();
    }

    if ($reassignedEstablishments > 0) {
        $warnings[] = "{$reassignedEstablishments} existing business(es) were moved to this active event (same name found in another event).";
    }

    $totalNew = $insertedCategories + $insertedAwards + $insertedTypes + $linkedTypeAwards
        + $insertedEstablishments + $linkedEstablishments;

    $inputRowCount = count($validated['categories'])
        + count($validated['awards'])
        + count($validated['establishments']);

    if ($totalNew === 0 && $inputRowCount > 0) {
        $warnings[] = 'Every row in your file already exists for this event — no new records or links were added. Edit names or add new rows to import more data.';
    }

    return [
        'type'          => $totalNew > 0 ? 'success' : 'warning',
        'title'         => $totalNew > 0 ? 'Import complete' : 'Nothing new to import',
        'event_label'   => $eventLabel,
        'rows'          => [
            ['label' => 'Categories', 'count' => $insertedCategories],
            ['label' => 'Name of awards', 'count' => $insertedAwards],
            ['label' => 'Business categories (new)', 'count' => $insertedTypes],
            ['label' => 'Type–award links', 'count' => $linkedTypeAwards],
            ['label' => 'Businesses (new)', 'count' => $insertedEstablishments],
            ['label' => 'Award links', 'count' => $linkedEstablishments],
        ],
        'skipped_links' => $skippedLinks,
        'warnings'      => $warnings,
    ];
}

if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
    import_redirect_error('No file uploaded or the upload failed. Please choose a valid .xlsx file.');
}

$activeEventId = admin_get_active_event_id($conn);
if ($activeEventId === null) {
    import_redirect_error('No active event found. Activate your event before importing.');
}

$eventLabel = admin_get_active_event_label($conn, $activeEventId);

try {
    $spreadsheet = IOFactory::load($_FILES['excelFile']['tmp_name']);
} catch (Throwable $e) {
    import_redirect_error('Could not read the Excel file. Ensure it is a valid .xlsx workbook.');
}

if (import_uses_unified_workbook($spreadsheet)) {
    $validated = import_validate_unified($spreadsheet, $conn);
    if ($validated['errors'] !== []) {
        $preview = array_slice($validated['errors'], 0, 12);
        $extra = count($validated['errors']) > 12
            ? ['…and ' . (count($validated['errors']) - 12) . ' more validation error(s).']
            : [];
        import_redirect_error('Validation failed. Fix the workbook and try again.', array_merge($preview, $extra));
    }
    $flash = import_run_unified($validated, $spreadsheet, $conn, $activeEventId, $eventLabel);
} else {
    $flash = import_run_legacy($spreadsheet, $conn, $activeEventId, $eventLabel);
}

audit_log($conn, 'import', 'import', 'event', $activeEventId, [
    'event_id' => $activeEventId,
    'event_name' => $eventLabel,
    'title' => (string) ($flash['title'] ?? ''),
    'rows' => $flash['rows'] ?? [],
    'skipped_links' => (int) ($flash['skipped_links'] ?? 0),
]);

import_redirect_success($flash);

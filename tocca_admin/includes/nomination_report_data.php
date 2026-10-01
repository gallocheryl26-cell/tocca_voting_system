<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_schema.php';

/** Pick a supported column from a fixed list of schema variants. */
function nomination_report_column(mysqli $conn, string $table, array $candidates): ?string
{
    foreach ($candidates as $column) {
        if (admin_schema_column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

/**
 * One row per registration, shared by the table and all download formats.
 * Award/category filters also limit the titles shown for each registration.
 * @return list<array<string,mixed>>
 */
function nomination_report_fetch_rows(
    mysqli $conn,
    int $eventId,
    string $status = '',
    ?int $categoryId = null,
    ?int $questionId = null
): array {
    if (!admin_schema_column_exists($conn, 'tbl_nominations', 'event_id')) {
        throw new RuntimeException('Registration reports require event-scoped registrations.');
    }

    $ansNom = nomination_report_column($conn, 'tbl_nomination_answers', ['nomination_id', 'nominationId']);
    $ansField = nomination_report_column($conn, 'tbl_nomination_answers', ['field_id', 'fieldId', 'nomination_field_id']);
    $ansValue = nomination_report_column($conn, 'tbl_nomination_answers', ['answer', 'value', 'response', 'text', 'text_value', 'val']);
    $ansId = nomination_report_column($conn, 'tbl_nomination_answers', ['id']);
    $answersUsable = $ansNom && $ansField && $ansValue
        && admin_table_exists($conn, 'tbl_nomination_fields');

    $answer = static function (array $names, ?string $label = null) use ($answersUsable, $ansNom, $ansField, $ansValue, $ansId): string {
        if (!$answersUsable) {
            return 'NULL';
        }
        // All field names and labels here are fixed application constants.
        $quoted = "'" . implode("','", $names) . "'";
        $match = "f.name IN ($quoted)";
        if ($label !== null) {
            $match .= " OR LOWER(f.label) = '$label'";
        }
        $latest = $ansId ? ", a.`$ansId` DESC" : '';
        return "(SELECT NULLIF(TRIM(a.`$ansValue`), '')
                 FROM tbl_nomination_answers a
                 JOIN tbl_nomination_fields f ON f.id = a.`$ansField`
                 WHERE a.`$ansNom` = n.nomination_id AND ($match)
                   AND NULLIF(TRIM(a.`$ansValue`), '') IS NOT NULL
                 ORDER BY FIELD(f.name, $quoted) = 0, FIELD(f.name, $quoted), f.id DESC $latest
                 LIMIT 1)";
    };
    $contact = static function (array $columns, string $fallback) use ($conn): string {
        $parts = [];
        foreach ($columns as $column) {
            if (admin_schema_column_exists($conn, 'tbl_nominations', $column)) {
                $parts[] = "NULLIF(TRIM(n.`$column`), '')";
            }
        }
        $parts[] = $fallback;
        $parts[] = "''";
        return '(CONVERT(COALESCE(' . implode(', ', $parts) . ') USING utf8mb4) COLLATE utf8mb4_unicode_ci)';
    };

    $business = $contact(['business_name'], $answer(['business_name', 'official_business_name', 'company_name', 'company', 'business'], 'business name'));
    $email = $contact(['email'], $answer(['email', 'contact_email', 'contact_email_address'], 'email'));
    $mobile = $contact(['mobile_number', 'phone'], $answer(['mobile_number', 'contact_phone', 'phone', 'mobile', 'contact_number', 'telephone'], 'mobile number'));
    $addressAnswer = $answer(['business_company_address', 'business_address', 'address', 'full_address', 'address_line1']);
    $street = $answer(['street', 'street_building', 'street_building_line']);
    $barangay = $answer(['barangay', 'brgy']);
    $address = $contact(['address', 'business_company_address', 'business_address'], "COALESCE($addressAnswer, NULLIF(CONCAT_WS(', ', $street, $barangay), ''))");

    $choiceJoin = '';
    if (admin_schema_column_exists($conn, 'tbl_nominations', 'merged_choice_id')
        && admin_table_exists($conn, 'tbl_choices')) {
        $choiceJoin = 'LEFT JOIN tbl_choices ch ON ch.choice_id = n.merged_choice_id';
        if (admin_schema_column_exists($conn, 'tbl_choices', 'choice_name')) {
            $business = "COALESCE(NULLIF($business, ''), ch.choice_name, '')";
        }
        if (admin_schema_column_exists($conn, 'tbl_choices', 'email')) {
            $email = "COALESCE(NULLIF($email, ''), ch.email, '')";
        }
    }

    $awardsUsable = admin_table_exists($conn, 'tbl_nomination_questions')
        && admin_table_exists($conn, 'tbl_questions') && admin_table_exists($conn, 'tbl_categories');
    if (($categoryId || $questionId) && !$awardsUsable) {
        return [];
    }
    $where = ['n.event_id = ?', admin_unarchived_events_where($conn, 'e')];
    $types = 'i';
    $params = [$eventId];
    if ($status !== '') {
        $where[] = 'LOWER(n.status) = ?';
        $types .= 's';
        $params[] = $status;
    }
    $awardWhere = ['c.event_id = ?'];
    $awardTypes = 'i';
    $awardParams = [$eventId];
    if ($categoryId) {
        $awardWhere[] = 'q.category_id = ?';
        $awardTypes .= 'i';
        $awardParams[] = $categoryId;
    }
    if ($questionId) {
        $awardWhere[] = 'q.question_id = ?';
        $awardTypes .= 'i';
        $awardParams[] = $questionId;
    }
    $awardCondition = implode(' AND ', $awardWhere);
    if ($categoryId || $questionId) {
        $where[] = "EXISTS (SELECT 1 FROM tbl_nomination_questions nq
                    JOIN tbl_questions q ON q.question_id = nq.question_id
                    JOIN tbl_categories c ON c.category_id = q.category_id
                    WHERE nq.nomination_id = n.nomination_id AND $awardCondition)";
        $types .= $awardTypes;
        $params = array_merge($params, $awardParams);
    }
    $sql = "SELECT n.nomination_id, $business AS establishment, $email AS email,
                   $mobile AS mobile_number, $address AS address, n.status
            FROM tbl_nominations n
            JOIN tbl_events e ON e.event_id = n.event_id
            $choiceJoin
            WHERE " . implode(' AND ', $where) . '
            ORDER BY establishment = \'\', establishment ASC, n.nomination_id ASC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $awards = [];
    if ($awardsUsable && $rows !== []) {
        foreach (array_chunk(array_column($rows, 'nomination_id'), 200) as $ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT DISTINCT nq.nomination_id, q.question_id,
                       q.question_name AS award_title, c.category_name
                FROM tbl_nomination_questions nq
                JOIN tbl_questions q ON q.question_id = nq.question_id
                JOIN tbl_categories c ON c.category_id = q.category_id
                WHERE nq.nomination_id IN ($placeholders) AND $awardCondition
                ORDER BY c.category_name, q.question_name, q.question_id");
            $bindTypes = str_repeat('i', count($ids)) . $awardTypes;
            $bindParams = array_merge($ids, $awardParams);
            $stmt->bind_param($bindTypes, ...$bindParams);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($award = $result->fetch_assoc()) {
                $awards[(int) $award['nomination_id']][] = [
                    'question_id' => (int) $award['question_id'],
                    'award_title' => (string) $award['award_title'],
                    'category_name' => (string) $award['category_name'],
                ];
            }
            $stmt->close();
        }
    }
    foreach ($rows as &$row) {
        $row['nomination_id'] = (int) $row['nomination_id'];
        $row['awards'] = $awards[$row['nomination_id']] ?? [];
        $row['categories'] = array_values(array_unique(array_column($row['awards'], 'category_name')));
    }
    unset($row);
    return $rows;
}

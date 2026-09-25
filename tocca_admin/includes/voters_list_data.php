<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/e-vote-final-enhanced/lib/voter_flow.php';

/**
 * Voters who already cast for this event, plus drafts and registrations in the event dates.
 * Cast voters are included even when tbl_voters.is_archived = 1, because Results lists them
 * from the ballot tables and does not apply that flag.
 *
 * @return list<array{voters_id:int,mobile_number:string,date_verified:?string,status:string}>
 */
function voters_list_rows_for_event(mysqli $conn, int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }

    $branches = [];
    $types = '';
    $params = [];

    $cast = [
        ['tbl_poll_choice', 'pc', true],
        ['tbl_poll_freetext', 'pf', true],
        ['tbl_draft_choice', 'dc', false],
        ['tbl_draft_freetext', 'df', false],
    ];
    foreach ($cast as [$table, $alias, $required]) {
        if (!$required && !admin_table_exists($conn, $table)) {
            continue;
        }
        $branches[] = "EXISTS (
            SELECT 1
            FROM {$table} {$alias}
            INNER JOIN tbl_questions q ON {$alias}.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE {$alias}.voters_id = v.voters_id AND c.event_id = ?
        )";
        $types .= 'i';
        $params[] = $eventId;
    }

    $archivedSql = admin_schema_column_exists($conn, 'tbl_voters', 'is_archived')
        ? 'COALESCE(v.is_archived, 0) = 0 AND '
        : '';
    $branches[] = "(
        {$archivedSql}v.date_verified IS NOT NULL
        AND EXISTS (
            SELECT 1
            FROM tbl_events e
            WHERE e.event_id = ?
              AND COALESCE(e.nomination_start, e.voting_start) IS NOT NULL
              AND COALESCE(e.voting_end, e.nomination_end) IS NOT NULL
              AND DATE(v.date_verified) >= DATE(
                  LEAST(
                      COALESCE(e.nomination_start, e.voting_start),
                      COALESCE(e.voting_start, e.nomination_start)
                  )
              )
              AND DATE(v.date_verified) <= DATE(
                  GREATEST(
                      COALESCE(e.voting_end, e.nomination_end),
                      COALESCE(e.nomination_end, e.voting_end)
                  )
              )
        )
    )";
    $types .= 'i';
    $params[] = $eventId;

    $where = implode("\n OR ", $branches);
    $sql = "
        SELECT DISTINCT v.voters_id,
               CONCAT_WS(' · ', NULLIF(v.google_email, ''), NULLIF(v.mobile_number, '')) AS mobile_number,
               v.date_verified
        FROM tbl_voters v
        WHERE {$where}
        ORDER BY v.date_verified DESC, v.voters_id DESC
    ";

    $result = voters_list_query($conn, $sql, $types, $params);
    if ($result === null) {
        $result = voters_list_query(
            $conn,
            "
                SELECT DISTINCT v.voters_id,
                       CONCAT_WS(' · ', NULLIF(v.google_email, ''), NULLIF(v.mobile_number, '')) AS mobile_number,
                       v.date_verified
                FROM tbl_voters v
                WHERE EXISTS (
                    SELECT 1
                    FROM tbl_poll_choice pc
                    INNER JOIN tbl_questions q ON pc.question_id = q.question_id
                    INNER JOIN tbl_categories c ON q.category_id = c.category_id
                    WHERE pc.voters_id = v.voters_id AND c.event_id = ?
                )
                OR EXISTS (
                    SELECT 1
                    FROM tbl_poll_freetext pf
                    INNER JOIN tbl_questions q ON pf.question_id = q.question_id
                    INNER JOIN tbl_categories c ON q.category_id = c.category_id
                    WHERE pf.voters_id = v.voters_id AND c.event_id = ?
                )
                ORDER BY v.date_verified DESC, v.voters_id DESC
            ",
            'ii',
            [$eventId, $eventId]
        );
    }
    if ($result === null) {
        return [];
    }

    $rows = [];
    while ($voter = $result->fetch_assoc()) {
        $voterId = (int) $voter['voters_id'];
        $formattedDate = !empty($voter['date_verified'])
            ? date('m/d/Y', strtotime((string) $voter['date_verified']))
            : '';

        if (voter_flow_has_finalized_all_awards($conn, $voterId, $eventId)) {
            $status = 'completed';
        } elseif (voter_flow_has_ballot_data($conn, $voterId, $eventId)) {
            $status = 'drafted';
        } else {
            $status = 'not started';
        }

        $rows[] = [
            'voters_id' => $voterId,
            'mobile_number' => (string) ($voter['mobile_number'] ?? ''),
            'date_verified' => $formattedDate,
            'status' => $status,
        ];
    }

    return $rows;
}

/**
 * @param list<int> $params
 */
function voters_list_query(mysqli $conn, string $sql, string $types, array $params): ?mysqli_result
{
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log('voters_list_query prepare failed: ' . $conn->error);
        return null;
    }

    if ($types !== '') {
        $refs = [];
        foreach ($params as $i => $value) {
            $refs[$i] = &$params[$i];
        }
        if (!$stmt->bind_param($types, ...$refs)) {
            error_log('voters_list_query bind failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
    }

    if (!$stmt->execute()) {
        error_log('voters_list_query execute failed: ' . $stmt->error);
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $stmt->close();
    return $result instanceof mysqli_result ? $result : null;
}

function voters_list_status_label(string $status): string
{
    $map = [
        'completed' => 'Completed',
        'drafted' => 'Drafted',
        'not started' => 'Not Started',
    ];
    return $map[$status] ?? ucwords($status);
}

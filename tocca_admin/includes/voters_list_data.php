<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/e-vote-final-enhanced/lib/voter_flow.php';

/**
 * @return list<array{voters_id:int,mobile_number:string,date_verified:?string,status:string}>
 */
function voters_list_rows_for_event(mysqli $conn, int $eventId): array
{
    $sql = "
        SELECT DISTINCT v.voters_id, v.mobile_number, v.date_verified
        FROM tbl_voters v
        WHERE v.is_archived = 0
          AND (
            EXISTS (
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
            OR EXISTS (
                SELECT 1
                FROM tbl_draft_choice dc
                INNER JOIN tbl_questions q ON dc.question_id = q.question_id
                INNER JOIN tbl_categories c ON q.category_id = c.category_id
                WHERE dc.voters_id = v.voters_id AND c.event_id = ?
            )
            OR EXISTS (
                SELECT 1
                FROM tbl_draft_freetext df
                INNER JOIN tbl_questions q ON df.question_id = q.question_id
                INNER JOIN tbl_categories c ON q.category_id = c.category_id
                WHERE df.voters_id = v.voters_id AND c.event_id = ?
            )
            OR (
                v.date_verified IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM tbl_events e
                    WHERE e.event_id = ?
                      AND COALESCE(e.nomination_start, e.voting_start) IS NOT NULL
                      AND COALESCE(e.voting_end, e.nomination_end) IS NOT NULL
                      AND v.date_verified >= DATE(
                          LEAST(
                              COALESCE(e.nomination_start, e.voting_start),
                              COALESCE(e.voting_start, e.nomination_start)
                          )
                      )
                      AND v.date_verified <= DATE(
                          GREATEST(
                              COALESCE(e.voting_end, e.nomination_end),
                              COALESCE(e.nomination_end, e.voting_end)
                          )
                      )
                )
            )
          )
        ORDER BY v.date_verified DESC, v.voters_id DESC
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return [];
    }

    $stmt->bind_param('iiiii', $eventId, $eventId, $eventId, $eventId, $eventId);
    $stmt->execute();
    $result = $stmt->get_result();

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

    $stmt->close();
    return $rows;
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

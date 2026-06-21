<?php
declare(strict_types=1);

/**
 * Admin helpers for Voter Portal settings (event stats + flow reference).
 */

if (!function_exists('voter_portal_event_stats')) {
    /**
     * @return array{
     *   event_id: int,
     *   event_name: string,
     *   event_year: string,
     *   category_count: int,
     *   award_count: int,
     *   voting_open: bool,
     *   voting_period: string,
     *   voter_url: string
     * }
     */
    function voter_portal_event_stats(mysqli $conn, int $eventId): array
    {
        $base = [
            'event_id'        => $eventId,
            'event_name'      => '',
            'event_year'      => '',
            'category_count'  => 0,
            'award_count'     => 0,
            'voting_open'     => false,
            'voting_period'   => 'No active event',
            'voter_url'       => '../e-vote-final-enhanced/index.php',
        ];

        if ($eventId <= 0) {
            return $base;
        }

        $stmt = $conn->prepare(
            'SELECT event_name, year, voting_start, voting_end
             FROM tbl_events WHERE event_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return $base;
        }
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $base;
        }

        $base['event_name'] = (string) ($row['event_name'] ?? '');
        $base['event_year'] = (string) ($row['year'] ?? '');

        $tz = new DateTimeZone('Asia/Manila');
        $start = !empty($row['voting_start']) ? new DateTime((string) $row['voting_start'], $tz) : null;
        $end   = !empty($row['voting_end']) ? new DateTime((string) $row['voting_end'], $tz) : null;
        $now   = new DateTime('now', $tz);

        if ($start && $end) {
            $base['voting_period'] = $start->format('M j, Y g:i A') . ' – ' . $end->format('M j, Y g:i A');
            $base['voting_open']   = ($now >= $start && $now <= $end);
        }

        $cStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM tbl_categories WHERE event_id = ?');
        if ($cStmt) {
            $cStmt->bind_param('i', $eventId);
            $cStmt->execute();
            $cStmt->bind_result($catCnt);
            if ($cStmt->fetch()) {
                $base['category_count'] = (int) $catCnt;
            }
            $cStmt->close();
        }

        $qStmt = $conn->prepare(
            'SELECT COUNT(*) AS cnt FROM tbl_questions q
             INNER JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE c.event_id = ?'
        );
        if ($qStmt) {
            $qStmt->bind_param('i', $eventId);
            $qStmt->execute();
            $qStmt->bind_result($awdCnt);
            if ($qStmt->fetch()) {
                $base['award_count'] = (int) $awdCnt;
            }
            $qStmt->close();
        }

        return $base;
    }
}

if (!function_exists('voter_portal_flow_reference')) {
    /**
     * Stages shown in admin (matches lib/voter_flow.php; labels explain what admins can edit).
     *
     * @return list<array{key:string,label:string,page:string,editable:bool,hint:string}>
     */
    function voter_portal_flow_reference(): array
    {
        return [
            [
                'key'      => 'welcome',
                'label'    => 'Welcome modal',
                'page'     => 'index.php',
                'editable' => true,
                'hint'     => 'Title + body; Terms/Privacy checkboxes are fixed.',
            ],
            [
                'key'      => 'landing',
                'label'    => 'How to vote',
                'page'     => 'index.php',
                'editable' => true,
                'hint'     => 'Hero heading, intro, and numbered steps on the landing card.',
            ],
            [
                'key'      => 'verify',
                'label'    => 'Mobile verification',
                'page'     => 'index.php → modals',
                'editable' => false,
                'hint'     => 'New voters: OTP + 4-digit access code. Returning: mobile + code.',
            ],
            [
                'key'      => 'categories',
                'label'    => 'Categories',
                'page'     => 'category.php',
                'editable' => false,
                'hint'     => 'Pick a category; progress bar shows Categories → Vote → Summary.',
            ],
            [
                'key'      => 'vote',
                'label'    => 'Vote per award',
                'page'     => 'selected-category.php',
                'editable' => false,
                'hint'     => 'One choice per award; drafts save per category.',
            ],
            [
                'key'      => 'summary',
                'label'    => 'Summary & submit',
                'page'     => 'summarypoll.php',
                'editable' => false,
                'hint'     => 'Review answers; Vote All or submit per award.',
            ],
            [
                'key'      => 'done',
                'label'    => 'Thank you',
                'page'     => 'thankyou.php',
                'editable' => false,
                'hint'     => 'Shown after all awards are finalized (one ballot per mobile).',
            ],
        ];
    }
}

if (!function_exists('voter_portal_suggested_lead')) {
    function voter_portal_suggested_lead(array $stats): string
    {
        $year = trim((string) ($stats['event_year'] ?? ''));
        $cats = (int) ($stats['category_count'] ?? 0);
        $awds = (int) ($stats['award_count'] ?? 0);

        $label = $year !== '' ? $year . ' Tatak Ormoc Consumers\' Choice Awards' : 'Tatak Ormoc Consumers\' Choice Awards';
        $parts = [];
        if ($cats > 0) {
            $parts[] = $cats . ' categor' . ($cats === 1 ? 'y' : 'ies');
        }
        if ($awds > 0) {
            $parts[] = $awds . ' award' . ($awds === 1 ? '' : 's');
        }
        $countText = $parts !== [] ? ' include ' . implode(' and ', $parts) . '.' : '.';

        return 'The ' . $label . $countText . ' Follow these steps to cast your ballot.';
    }
}

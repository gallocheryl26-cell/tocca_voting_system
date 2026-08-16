<?php
declare(strict_types=1);

/**
 * TWG Top 10 gate for Confirm for public voting.
 * Public voting has not started yet, so Top 10 is by complete TWG average
 * among businesses already fully graded for that award.
 */

require_once __DIR__ . '/results_formula.php';
require_once __DIR__ . '/ballot_status.php';

if (!function_exists('twg_ballot_award_label')) {
    function twg_ballot_award_label(array $row): string
    {
        $cat = trim((string) ($row['category_name'] ?? ''));
        $name = trim((string) ($row['question_name'] ?? 'Award'));
        return $cat !== '' ? ($cat . ' · ' . $name) : $name;
    }
}

if (!function_exists('twg_ballot_eligibility_for_choice')) {
    /**
     * @return array{
     *   ok:bool,
     *   message:string,
     *   event_id:int,
     *   remaining_count:int,
     *   graded_count:int,
     *   all_graded:bool,
     *   can_release:bool,
     *   none_in_top10:bool,
     *   awards:list<array<string,mixed>>,
     *   top10:list<array<string,mixed>>,
     *   not_top10:list<array<string,mixed>>,
     *   incomplete:list<array<string,mixed>>
     * }
     */
    function twg_ballot_eligibility_for_choice(mysqli $conn, int $choice_id): array
    {
        $empty = [
            'ok' => false,
            'message' => 'Business not found.',
            'event_id' => 0,
            'remaining_count' => 0,
            'graded_count' => 0,
            'all_graded' => false,
            'can_release' => false,
            'none_in_top10' => false,
            'awards' => [],
            'top10' => [],
            'not_top10' => [],
            'incomplete' => [],
        ];
        if ($choice_id <= 0) {
            return $empty;
        }

        twg_member_scores_ensure_schema($conn);
        ballot_award_ensure_column($conn);

        $eventId = 0;
        $st = $conn->prepare('SELECT event_id FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $choice_id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            $eventId = (int) ($row['event_id'] ?? 0);
        }
        if ($eventId <= 0) {
            $empty['message'] = 'This business is not linked to an event.';
            return $empty;
        }

        $memberCount = count(twg_member_keys()) ?: 5;
        $catSql = admin_active_category_sql($conn, 'cat');
        $qSql = admin_active_question_sql($conn, 'q');
        $hasAwardBallot = ballot_award_ensure_column($conn);
        $awardBallotSelect = $hasAwardBallot ? ', qc.on_ballot AS award_on_ballot' : ', 0 AS award_on_ballot';

        $linked = [];
        $sql = "
            SELECT q.question_id, q.question_name, cat.category_id, cat.category_name
                   {$awardBallotSelect}
            FROM tbl_question_choices qc
            INNER JOIN tbl_questions q ON q.question_id = qc.question_id
            INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
            WHERE qc.choice_id = ? AND cat.event_id = ? AND {$catSql} AND {$qSql}
            ORDER BY cat.category_name ASC, q.question_name ASC
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('ii', $choice_id, $eventId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $linked[] = $row;
            }
            $st->close();
        }

        if ($linked === []) {
            return [
                'ok' => true,
                'message' => 'This business has no remaining award titles.',
                'event_id' => $eventId,
                'remaining_count' => 0,
                'graded_count' => 0,
                'all_graded' => false,
                'can_release' => false,
                'none_in_top10' => false,
                'awards' => [],
                'top10' => [],
                'not_top10' => [],
                'incomplete' => [],
            ];
        }

        $qids = array_values(array_filter(array_map(static fn($r) => (int) ($r['question_id'] ?? 0), $linked)));
        $scoreCounts = [];
        $averages = [];
        if ($qids !== []) {
            $ph = implode(',', array_fill(0, count($qids), '?'));
            $types = str_repeat('i', count($qids));
            $sql = "SELECT question_id, choice_id, COUNT(*) AS scored, AVG(score) AS avg_score
                    FROM tbl_twg_member_scores
                    WHERE question_id IN ($ph)
                    GROUP BY question_id, choice_id";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, ...$qids);
                $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) {
                    $qid = (int) $r['question_id'];
                    $cid = (int) $r['choice_id'];
                    $scoreCounts[$qid][$cid] = (int) $r['scored'];
                    $averages[$qid][$cid] = results_formula_round((float) $r['avg_score'], 2);
                }
                $st->close();
            }
        }

        $peersByAward = [];
        if ($qids !== []) {
            $ph = implode(',', array_fill(0, count($qids), '?'));
            $types = 'i' . str_repeat('i', count($qids));
            $sql = "SELECT qc.question_id, qc.choice_id, c.choice_name
                    FROM tbl_question_choices qc
                    INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
                    WHERE c.event_id = ? AND qc.question_id IN ($ph)
                      AND COALESCE(c.status, 1) = 1";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, $eventId, ...$qids);
                $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) {
                    $qid = (int) $r['question_id'];
                    $peersByAward[$qid][] = [
                        'choice_id' => (int) $r['choice_id'],
                        'choice_name' => (string) ($r['choice_name'] ?? ''),
                    ];
                }
                $st->close();
            }
        }

        $awards = [];
        foreach ($linked as $row) {
            $qid = (int) ($row['question_id'] ?? 0);
            $scored = (int) ($scoreCounts[$qid][$choice_id] ?? 0);
            $fullyGraded = $scored >= $memberCount;
            $peers = $peersByAward[$qid] ?? [];
            $ranked = [];
            foreach ($peers as $peer) {
                $cid = (int) $peer['choice_id'];
                $peerScored = (int) ($scoreCounts[$qid][$cid] ?? 0);
                if ($peerScored < $memberCount) {
                    continue;
                }
                $ranked[] = [
                    'choice_id' => $cid,
                    'choice_name' => (string) $peer['choice_name'],
                    'average' => $averages[$qid][$cid] ?? 0.0,
                ];
            }
            usort($ranked, static function (array $a, array $b): int {
                $cmp = ((float) $b['average']) <=> ((float) $a['average']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcasecmp((string) $a['choice_name'], (string) $b['choice_name']);
            });
            $rank = 0;
            $previous = null;
            $rankByChoice = [];
            foreach ($ranked as $item) {
                $avg = (float) $item['average'];
                if ($previous === null || abs($avg - $previous) > 0.0001) {
                    $rank++;
                }
                $rankByChoice[(int) $item['choice_id']] = $rank;
                $previous = $avg;
            }

            $twgRank = $rankByChoice[$choice_id] ?? null;
            $inTop10 = $fullyGraded && $twgRank !== null && $twgRank >= 1 && $twgRank <= 10;
            $ungradedPeers = 0;
            foreach ($peers as $peer) {
                $cid = (int) $peer['choice_id'];
                if ($cid === $choice_id) {
                    continue;
                }
                if ((int) ($scoreCounts[$qid][$cid] ?? 0) < $memberCount) {
                    $ungradedPeers++;
                }
            }

            $awards[] = [
                'question_id' => $qid,
                'question_name' => (string) ($row['question_name'] ?? ''),
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'label' => twg_ballot_award_label($row),
                'scored' => $scored,
                'member_count' => $memberCount,
                'fully_graded' => $fullyGraded,
                'twg_average' => $fullyGraded ? ($averages[$qid][$choice_id] ?? null) : null,
                'twg_rank' => $twgRank,
                'in_top10' => $inTop10,
                'award_on_ballot' => (int) ($row['award_on_ballot'] ?? 0) === 1,
                'ungraded_peers' => $ungradedPeers,
                'graded_field' => count($ranked),
            ];
        }

        $top10 = array_values(array_filter($awards, static fn($a) => !empty($a['in_top10'])));
        $incomplete = array_values(array_filter($awards, static fn($a) => empty($a['fully_graded'])));
        $notTop10 = array_values(array_filter($awards, static fn($a) => !empty($a['fully_graded']) && empty($a['in_top10'])));
        $remaining = count($awards);
        $gradedCount = $remaining - count($incomplete);
        $allGraded = $remaining > 0 && $incomplete === [];

        return [
            'ok' => true,
            'message' => '',
            'event_id' => $eventId,
            'remaining_count' => $remaining,
            'graded_count' => $gradedCount,
            'all_graded' => $allGraded,
            'can_release' => $allGraded && $top10 !== [],
            'none_in_top10' => $allGraded && $top10 === [],
            'awards' => $awards,
            'top10' => $top10,
            'not_top10' => $notTop10,
            'incomplete' => $incomplete,
        ];
    }
}

if (!function_exists('twg_ballot_apply_top10')) {
    /**
     * Marks Top 10 titles on the public ballot and leaves the rest linked but off ballot.
     *
     * @return array{ok:bool,message:string,code?:string,eligibility?:array<string,mixed>,top10_count?:int,not_top10_count?:int}
     */
    function twg_ballot_apply_top10(mysqli $conn, int $choice_id): array
    {
        $elig = twg_ballot_eligibility_for_choice($conn, $choice_id);
        if (empty($elig['ok'])) {
            return ['ok' => false, 'message' => (string) ($elig['message'] ?: 'Could not check TWG scores.'), 'code' => 'lookup'];
        }
        if ((int) ($elig['remaining_count'] ?? 0) < 1) {
            return ['ok' => false, 'message' => 'This business has no remaining award titles. Finish evaluation first, then confirm for public voting.', 'code' => 'no_awards', 'eligibility' => $elig];
        }
        if (empty($elig['all_graded'])) {
            $first = $elig['incomplete'][0]['label'] ?? 'an award';
            $left = (int) ($elig['remaining_count'] ?? 0) - (int) ($elig['graded_count'] ?? 0);
            return [
                'ok' => false,
                'message' => $left === 1
                    ? "Finish TWG scoring first. {$first} is not fully graded."
                    : "Finish TWG scoring first. {$left} award titles are not fully graded.",
                'code' => 'not_graded',
                'eligibility' => $elig,
            ];
        }
        if (!empty($elig['none_in_top10'])) {
            return [
                'ok' => false,
                'message' => 'None of this business’s remaining award titles are in the TWG Top 10, so they cannot be added to the public ballot.',
                'code' => 'none_in_top10',
                'eligibility' => $elig,
            ];
        }

        $topIds = [];
        foreach ($elig['top10'] as $row) {
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid > 0) {
                $topIds[] = $qid;
            }
        }
        if ($topIds === [] || !ballot_award_set_for_choice($conn, $choice_id, $topIds)) {
            return ['ok' => false, 'message' => 'Could not update award ballot status.', 'code' => 'save', 'eligibility' => $elig];
        }
        if (!ballot_status_set($conn, $choice_id, true)) {
            return ['ok' => false, 'message' => 'Failed to confirm this business for public voting.', 'code' => 'save', 'eligibility' => $elig];
        }

        $topCount = count($elig['top10']);
        $otherCount = count($elig['not_top10']);
        $message = $topCount === 1
            ? '1 award title in the TWG Top 10 was added to the public ballot.'
            : ($topCount . ' award titles in the TWG Top 10 were added to the public ballot.');
        if ($otherCount > 0) {
            $message .= $otherCount === 1
                ? ' 1 evaluated title did not place in the Top 10 and will not appear for public voting.'
                : (' ' . $otherCount . ' evaluated titles did not place in the Top 10 and will not appear for public voting.');
        }

        return [
            'ok' => true,
            'message' => $message,
            'eligibility' => $elig,
            'top10_count' => $topCount,
            'not_top10_count' => $otherCount,
        ];
    }
}

if (!function_exists('twg_ballot_award_list_html')) {
    /** @param list<array<string,mixed>> $rows */
    function twg_ballot_award_list_html(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $items = '';
        foreach ($rows as $row) {
            $label = htmlspecialchars((string) ($row['label'] ?? twg_ballot_award_label($row)), ENT_QUOTES, 'UTF-8');
            $items .= '<li style="margin:0 0 6px;">' . $label . '</li>';
        }
        return '<ul style="margin:0 0 14px;padding-left:20px;">' . $items . '</ul>';
    }
}

if (!function_exists('twg_ballot_award_email_html')) {
    /** Award sections used in the Confirm for public voting QR email. */
    function twg_ballot_award_email_html(?array $elig): string
    {
        if (!is_array($elig)) {
            return '';
        }
        $html = '';
        $topList = twg_ballot_award_list_html($elig['top10'] ?? []);
        $otherList = twg_ballot_award_list_html($elig['not_top10'] ?? []);
        if ($topList !== '') {
            $html .= '<p style="margin:0 0 8px;font-weight:700;">Shortlisted for public voting</p>' . $topList;
        }
        if ($otherList !== '') {
            $html .= '<p style="margin:0 0 8px;font-weight:700;">Evaluated, not in the TWG Top 10</p>'
                . '<p style="margin:0 0 8px;color:#4b5563;">These titles will not appear on the public ballot.</p>'
                . $otherList;
        }
        return $html;
    }
}

if (!function_exists('twg_ballot_notice_compose')) {
    /**
     * Build the not-in-Top-10 notice without sending.
     *
     * @return array{ok:bool,message:string,to?:string,name?:string,subject?:string,html?:string,event_id?:int}
     */
    function twg_ballot_notice_compose(mysqli $conn, int $choice_id, array $elig = []): array
    {
        if ($elig === []) {
            $elig = twg_ballot_eligibility_for_choice($conn, $choice_id);
        }
        if (empty($elig['ok']) || empty($elig['none_in_top10'])) {
            return ['ok' => false, 'message' => 'This notice is only for businesses with complete TWG scores and no Top 10 titles.'];
        }

        $st = $conn->prepare('SELECT choice_name, email, event_id FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if (!$st) {
            return ['ok' => false, 'message' => 'Could not load business.'];
        }
        $st->bind_param('i', $choice_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Business not found.'];
        }

        $name = trim((string) ($row['choice_name'] ?? '')) ?: 'Business';
        $email = trim((string) ($row['email'] ?? ''));
        $eventId = (int) ($row['event_id'] ?? 0);

        require_once __DIR__ . '/branded_email.php';

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $list = twg_ballot_award_list_html($elig['not_top10'] ?: $elig['awards']);
        $subject = 'TWG evaluation update';
        $inner = '
          <p style="margin:0 0 14px;">Thank you for taking part in the Tatak Ormoc Consumers&rsquo; Choice Awards. <strong>' . $safeName . '</strong> was evaluated for the award titles below.</p>
          ' . $list . '
          <p style="margin:0 0 14px;">These titles did not place in the TWG Top 10, so they will not appear on the public voting ballot. We appreciate your participation and the work your team put into this event.</p>
        ';
        $html = tocca_branded_status_email($subject, $name, 'Evaluation update', $inner);

        $validEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
        return [
            'ok' => true,
            'message' => $validEmail ? '' : 'No valid email on file for this business.',
            'to' => $email,
            'name' => $name,
            'subject' => $subject,
            'html' => $html,
            'event_id' => $eventId,
            'has_email' => $validEmail,
        ];
    }
}

if (!function_exists('twg_ballot_notice_email')) {
    /**
     * Email when the business was fully evaluated but no titles made TWG Top 10.
     *
     * @return array{ok:bool,sent:bool,message:string}
     */
    function twg_ballot_notice_email(mysqli $conn, int $choice_id, array $elig = []): array
    {
        $composed = twg_ballot_notice_compose($conn, $choice_id, $elig);
        if (empty($composed['ok'])) {
            return ['ok' => false, 'sent' => false, 'message' => (string) ($composed['message'] ?? 'Could not build the evaluation notice.')];
        }
        if (empty($composed['has_email'])) {
            return ['ok' => false, 'sent' => false, 'message' => 'No valid email on file for this business.'];
        }

        require_once dirname(__DIR__) . '/mailer_helper.php';
        try {
            $sent = comm_send_and_log($conn, [
                'event_id' => (int) ($composed['event_id'] ?? 0),
                'type' => 'twg_not_advanced',
                'to_email' => (string) ($composed['to'] ?? ''),
                'to_name' => (string) ($composed['name'] ?? 'Business'),
                'subject' => (string) ($composed['subject'] ?? 'TWG evaluation update'),
                'html' => (string) ($composed['html'] ?? ''),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'sent' => false, 'message' => $e->getMessage()];
        }

        if (!empty($sent['ok'])) {
            return ['ok' => true, 'sent' => true, 'message' => 'Evaluation notice emailed. This business was not added to the public ballot.'];
        }
        return ['ok' => false, 'sent' => false, 'message' => (string) ($sent['error'] ?? 'Could not send the evaluation notice.')];
    }
}

<?php
declare(strict_types=1);

/**
 * Public-ballot gate after TWG scoring.
 * Food and Service: only TWG Top 5 titles go on the ballot.
 * Feelings: every fully graded title is eligible (no shortlist).
 */

require_once __DIR__ . '/results_formula.php';
require_once __DIR__ . '/ballot_status.php';
require_once __DIR__ . '/award_entry_helpers.php';

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
              AND " . twg_award_link_matches_remaining_sql($conn, 'qc.choice_id', 'q.question_id') . "
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
        $fullyMap = [];
        $neededMap = [];
        $titleScoreMap = [];
        if ($qids !== []) {
            $ph = implode(',', array_fill(0, count($qids), '?'));
            $types = str_repeat('i', count($qids));
            $sql = "SELECT question_id, choice_id, member_key, score
                    FROM tbl_twg_member_scores
                    WHERE question_id IN ($ph)";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, ...$qids);
                $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) {
                    $qid = (int) $r['question_id'];
                    $cid = (int) $r['choice_id'];
                    $key = strtolower(trim((string) ($r['member_key'] ?? '')));
                    if ($qid > 0 && $cid > 0 && $key !== '') {
                        $titleScoreMap[$qid][$cid][$key] = results_formula_round((float) $r['score'], 2);
                    }
                }
                $st->close();
            }
        }

        $entryRowsByQ = ($qids !== [] && function_exists('award_entry_score_rows_for_questions'))
            ? award_entry_score_rows_for_questions($conn, $qids)
            : [];
        $entryScoreMap = $qids !== []
            ? twg_fetch_entry_member_score_map($conn, $qids)
            : [];
        $memberDefs = twg_member_definitions();
        $panelByQ = twg_panel_members_by_question($memberDefs, $titleScoreMap, $entryScoreMap);
        $choiceIdsForGrade = [];
        foreach ($titleScoreMap as $qid => $byChoice) {
            foreach ($byChoice as $cid => $_) {
                $choiceIdsForGrade[$qid][$cid] = true;
            }
        }
        foreach ($entryRowsByQ as $qid => $byChoice) {
            foreach ($byChoice as $cid => $_) {
                $choiceIdsForGrade[$qid][$cid] = true;
            }
        }
        foreach ($choiceIdsForGrade as $qid => $byChoice) {
            foreach ($byChoice as $cid => $_) {
                $entries = is_array($entryRowsByQ[$qid][$cid] ?? null) ? $entryRowsByQ[$qid][$cid] : [];
                $panel = $panelByQ[$qid] ?? $memberDefs;
                $grade = twg_grade_award_pair(
                    $entries,
                    $titleScoreMap[$qid][$cid] ?? [],
                    $entryScoreMap[$qid][$cid] ?? [],
                    $panel
                );
                $scoreCounts[$qid][$cid] = (int) $grade['scored'];
                $neededMap[$qid][$cid] = (int) $grade['member_count'];
                $fullyMap[$qid][$cid] = !empty($grade['fully']);
                if ($grade['average'] !== null) {
                    $averages[$qid][$cid] = (float) $grade['average'];
                }
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
                      AND COALESCE(c.status, 1) = 1
                      AND " . twg_award_link_matches_remaining_sql($conn, 'c.choice_id', 'qc.question_id');
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

        $namedByQuestion = [];
        if ($choice_id > 0 && function_exists('award_entry_names_by_question_for_choice')) {
            $namedByQuestion = award_entry_names_by_question_for_choice($conn, $choice_id);
        }

        $awards = [];
        foreach ($linked as $row) {
            $qid = (int) ($row['question_id'] ?? 0);
            if (!isset($neededMap[$qid][$choice_id])) {
                $entries = is_array($entryRowsByQ[$qid][$choice_id] ?? null) ? $entryRowsByQ[$qid][$choice_id] : [];
                $panel = $panelByQ[$qid] ?? $memberDefs;
                $grade = twg_grade_award_pair(
                    $entries,
                    $titleScoreMap[$qid][$choice_id] ?? [],
                    $entryScoreMap[$qid][$choice_id] ?? [],
                    $panel
                );
                $scoreCounts[$qid][$choice_id] = (int) $grade['scored'];
                $neededMap[$qid][$choice_id] = (int) $grade['member_count'];
                $fullyMap[$qid][$choice_id] = !empty($grade['fully']);
                if ($grade['average'] !== null) {
                    $averages[$qid][$choice_id] = (float) $grade['average'];
                }
            }
            $needed = (int) ($neededMap[$qid][$choice_id] ?? $memberCount);
            $scored = (int) ($scoreCounts[$qid][$choice_id] ?? 0);
            $fullyGraded = !empty($fullyMap[$qid][$choice_id]);
            $peers = $peersByAward[$qid] ?? [];
            $ranked = [];
            foreach ($peers as $peer) {
                $cid = (int) $peer['choice_id'];
                $peerFully = !empty($fullyMap[$qid][$cid]);
                if (!$peerFully) {
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
            $place = 0;
            $rankByChoice = [];
            foreach ($ranked as $item) {
                $place++;
                $rankByChoice[(int) $item['choice_id']] = $place;
            }

            $twgRank = $rankByChoice[$choice_id] ?? null;
            $usesShortlist = twg_category_uses_shortlist((string) ($row['category_name'] ?? ''));
            $inTop10 = $fullyGraded && (
                !$usesShortlist
                    ? true
                    : ($twgRank !== null && $twgRank >= 1 && $twgRank <= 5)
            );
            $ungradedPeers = 0;
            foreach ($peers as $peer) {
                $cid = (int) $peer['choice_id'];
                if ($cid === $choice_id) {
                    continue;
                }
                if (empty($fullyMap[$qid][$cid])) {
                    $ungradedPeers++;
                }
            }

            $named = $namedByQuestion[$qid] ?? [];
            $entryNames = array_values(array_filter(array_map('strval', $named['entry_names'] ?? [])));
            $awards[] = [
                'question_id' => $qid,
                'question_name' => (string) ($row['question_name'] ?? ''),
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'label' => twg_ballot_award_label($row),
                'entry_kind' => (string) ($named['entry_kind'] ?? ''),
                'entry_names' => $entryNames,
                'scored' => $scored,
                'member_count' => $needed,
                'fully_graded' => $fullyGraded,
                'twg_average' => $fullyGraded ? ($averages[$qid][$choice_id] ?? null) : null,
                'twg_rank' => $twgRank,
                'in_top10' => $inTop10,
                'in_top5' => $inTop10,
                'uses_shortlist' => $usesShortlist,
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
     * Marks Top 5 titles on the public ballot and leaves the rest linked but off ballot.
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
                'message' => 'None of this business’s remaining Food or Service titles placed in the TWG Top 5, so they cannot be added to the public ballot. Feelings titles skip shortlisting once they are fully graded.',
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
        $shortlisted = array_values(array_filter($elig['top10'], static fn($row) => !empty($row['uses_shortlist'])));
        $feelingsOn = array_values(array_filter($elig['top10'], static fn($row) => empty($row['uses_shortlist'])));
        $parts = [];
        if ($shortlisted !== []) {
            $n = count($shortlisted);
            $parts[] = $n === 1
                ? '1 Food/Service title in the TWG Top 5 was added to the public ballot.'
                : ($n . ' Food/Service titles in the TWG Top 5 were added to the public ballot.');
        }
        if ($feelingsOn !== []) {
            $n = count($feelingsOn);
            $parts[] = $n === 1
                ? '1 Feelings title was added to the public ballot (no shortlist in this category).'
                : ($n . ' Feelings titles were added to the public ballot (no shortlist in this category).');
        }
        $message = $parts !== []
            ? implode(' ', $parts)
            : ($topCount === 1
                ? '1 award title was added to the public ballot.'
                : ($topCount . ' award titles were added to the public ballot.'));
        if ($otherCount > 0) {
            $message .= $otherCount === 1
                ? ' 1 evaluated Food/Service title did not place in the Top 5 and will not appear for public voting.'
                : (' ' . $otherCount . ' evaluated Food/Service titles did not place in the Top 5 and will not appear for public voting.');
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
            $names = array_values(array_filter(array_map('strval', $row['entry_names'] ?? [])));
            $item = $label;
            if ($names !== []) {
                $kind = (string) ($row['entry_kind'] ?? '');
                $kindLabel = $kind === 'artist' ? 'Artist' : ($kind === 'stylist' ? 'Stylist' : 'Product');
                $safeNames = htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8');
                $item .= '<div style="color:#6b7280;font-size:13px;margin-top:2px;"><strong>'
                    . htmlspecialchars($kindLabel, ENT_QUOTES, 'UTF-8')
                    . ':</strong> ' . $safeNames . '</div>';
            }
            $items .= '<li style="margin:0 0 8px;">' . $item . '</li>';
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
            $html .= '<p style="margin:0 0 8px;font-weight:700;">On the public ballot</p>' . $topList;
        }
        if ($otherList !== '') {
            $html .= '<p style="margin:0 0 8px;font-weight:700;">Evaluated, not in the TWG Top 5</p>'
                . '<p style="margin:0 0 8px;color:#4b5563;">These titles will not appear on the public ballot.</p>'
                . $otherList;
        }
        return $html;
    }
}

if (!function_exists('twg_ballot_notice_compose')) {
    /**
     * Build the not-in-Top-5 notice without sending.
     *
     * @return array{ok:bool,message:string,to?:string,name?:string,subject?:string,html?:string,event_id?:int}
     */
    function twg_ballot_notice_compose(mysqli $conn, int $choice_id, array $elig = []): array
    {
        if ($elig === []) {
            $elig = twg_ballot_eligibility_for_choice($conn, $choice_id);
        }
        if (empty($elig['ok']) || empty($elig['none_in_top10'])) {
            return ['ok' => false, 'message' => 'This notice is only for businesses with complete TWG scores and no Top 5 titles.'];
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
          <p style="margin:0 0 14px;">These titles did not place in the evaluation, so they will not appear on the public voting ballot. We appreciate your participation and the work your team put into this event.</p>
        ';
        $html = tocca_branded_status_email($subject, $name, 'Evaluation update', $inner, '', '', false, false);

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
     * Email when the business was fully evaluated but no titles made TWG Top 5.
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

if (!function_exists('twg_ballot_top5_choice_ids_for_questions')) {
    /**
     * Food/Service public ballot: at most 5 businesses per award, ranked by TWG average.
     *
     * @param list<int> $questionIds
     * @return array<int, list<int>>
     */
    function twg_ballot_top5_choice_ids_for_questions(mysqli $conn, int $eventId, array $questionIds): array
    {
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
        if ($eventId <= 0 || $questionIds === []) {
            return [];
        }

        twg_member_scores_ensure_schema($conn);
        $remainingSql = '1=1';
        try {
            $remainingSql = twg_award_link_matches_remaining_sql($conn, 'c.choice_id', 'qc.question_id');
        } catch (Throwable $e) {
            error_log('twg_ballot_top5 remaining sql: ' . $e->getMessage());
        }

        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $types = 'i' . str_repeat('i', count($questionIds));
        $sql = "SELECT qc.question_id, qc.choice_id, c.choice_name
                FROM tbl_question_choices qc
                INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
                WHERE c.event_id = ? AND qc.question_id IN ($ph)
                  AND COALESCE(c.status, 1) = 1
                  AND {$remainingSql}";
        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param($types, $eventId, ...$questionIds);
        $st->execute();
        $res = $st->get_result();
        $peersByAward = [];
        while ($r = $res->fetch_assoc()) {
            $qid = (int) $r['question_id'];
            $peersByAward[$qid][] = [
                'choice_id' => (int) $r['choice_id'],
                'choice_name' => (string) ($r['choice_name'] ?? ''),
            ];
        }
        $st->close();

        $titleScoreMap = [];
        $sql = "SELECT question_id, choice_id, member_key, score
                FROM tbl_twg_member_scores
                WHERE question_id IN ($ph)";
        $st = $conn->prepare($sql);
        if ($st) {
            $qTypes = str_repeat('i', count($questionIds));
            $st->bind_param($qTypes, ...$questionIds);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) {
                $qid = (int) $r['question_id'];
                $cid = (int) $r['choice_id'];
                $key = strtolower(trim((string) ($r['member_key'] ?? '')));
                if ($qid > 0 && $cid > 0 && $key !== '') {
                    $titleScoreMap[$qid][$cid][$key] = results_formula_round((float) $r['score'], 2);
                }
            }
            $st->close();
        }

        $entryRowsByQ = function_exists('award_entry_score_rows_for_questions')
            ? award_entry_score_rows_for_questions($conn, $questionIds)
            : [];
        $entryScoreMap = twg_fetch_entry_member_score_map($conn, $questionIds);
        $memberDefs = twg_member_definitions($conn, $eventId);
        $panelByQ = twg_panel_members_by_question($memberDefs, $titleScoreMap, $entryScoreMap);

        $averages = [];
        $fullyMap = [];
        $choiceIdsForGrade = [];
        foreach ($titleScoreMap as $qid => $byChoice) {
            foreach ($byChoice as $cid => $_) {
                $choiceIdsForGrade[$qid][$cid] = true;
            }
        }
        foreach ($entryRowsByQ as $qid => $byChoice) {
            foreach ($byChoice as $cid => $_) {
                $choiceIdsForGrade[$qid][$cid] = true;
            }
        }
        foreach ($choiceIdsForGrade as $qid => $byChoice) {
            foreach ($byChoice as $cid => $_) {
                $entries = is_array($entryRowsByQ[$qid][$cid] ?? null) ? $entryRowsByQ[$qid][$cid] : [];
                $grade = twg_grade_award_pair(
                    $entries,
                    $titleScoreMap[$qid][$cid] ?? [],
                    $entryScoreMap[$qid][$cid] ?? [],
                    $panelByQ[$qid] ?? $memberDefs
                );
                $fullyMap[$qid][$cid] = !empty($grade['fully']);
                if ($grade['average'] !== null) {
                    $averages[$qid][$cid] = (float) $grade['average'];
                }
            }
        }

        $out = [];
        foreach ($peersByAward as $qid => $peers) {
            $ranked = [];
            foreach ($peers as $peer) {
                $cid = (int) $peer['choice_id'];
                if (empty($fullyMap[$qid][$cid])) {
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
            $ids = [];
            foreach ($ranked as $item) {
                if (count($ids) >= 5) {
                    break;
                }
                $ids[] = (int) $item['choice_id'];
            }
            if ($ids !== []) {
                $out[$qid] = $ids;
            }
        }
        return $out;
    }
}

if (!function_exists('twg_ballot_question_meta')) {
    /**
     * @return array{category_name:string,event_id:int}
     */
    function twg_ballot_question_meta(mysqli $conn, int $questionId): array
    {
        static $cache = [];
        if ($questionId <= 0) {
            return ['category_name' => '', 'event_id' => 0];
        }
        if (isset($cache[$questionId])) {
            return $cache[$questionId];
        }
        $meta = ['category_name' => '', 'event_id' => 0];
        $st = $conn->prepare(
            'SELECT cat.category_name, cat.event_id
             FROM tbl_questions q
             INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
             WHERE q.question_id = ? LIMIT 1'
        );
        if ($st) {
            $st->bind_param('i', $questionId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                $meta = [
                    'category_name' => (string) ($row['category_name'] ?? ''),
                    'event_id' => (int) ($row['event_id'] ?? 0),
                ];
            }
        }
        return $cache[$questionId] = $meta;
    }
}

if (!function_exists('twg_ballot_on_ballot_choice_ids_for_question')) {
    /** @return list<int> */
    function twg_ballot_on_ballot_choice_ids_for_question(mysqli $conn, int $questionId): array
    {
        if ($questionId <= 0) {
            return [];
        }
        $awardSql = ballot_award_sql_and($conn, 'qc');
        $bizSql = ballot_status_sql_and($conn, 'c');
        $st = $conn->prepare(
            "SELECT c.choice_id
             FROM tbl_question_choices qc
             INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
             WHERE qc.question_id = ?
               AND COALESCE(c.status, 1) = 1
               {$awardSql}{$bizSql}"
        );
        if (!$st) {
            return [];
        }
        $st->bind_param('i', $questionId);
        $st->execute();
        $res = $st->get_result();
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $cid = (int) ($row['choice_id'] ?? 0);
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        $st->close();
        return array_values(array_unique($ids));
    }
}

if (!function_exists('twg_ballot_shortlist_from_ids')) {
    /**
     * Keep at most $limit businesses, ordered by TWG average then name.
     *
     * @param list<int> $choiceIds
     * @return list<int>
     */
    function twg_ballot_shortlist_from_ids(mysqli $conn, int $questionId, array $choiceIds, int $limit = 5): array
    {
        $choiceIds = array_values(array_unique(array_filter(array_map('intval', $choiceIds))));
        if ($choiceIds === [] || $limit < 1 || count($choiceIds) <= $limit) {
            return $choiceIds;
        }

        $meta = twg_ballot_question_meta($conn, $questionId);
        $eventId = (int) ($meta['event_id'] ?? 0);

        twg_member_scores_ensure_schema($conn);
        $ph = implode(',', array_fill(0, count($choiceIds), '?'));
        $types = str_repeat('i', count($choiceIds));
        $names = [];
        $st = $conn->prepare("SELECT choice_id, choice_name FROM tbl_choices WHERE choice_id IN ($ph)");
        if ($st) {
            $st->bind_param($types, ...$choiceIds);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $names[(int) $row['choice_id']] = (string) ($row['choice_name'] ?? '');
            }
            $st->close();
        }

        $titleScoreMap = [];
        $st = $conn->prepare(
            "SELECT choice_id, member_key, score
             FROM tbl_twg_member_scores
             WHERE question_id = ? AND choice_id IN ($ph)"
        );
        if ($st) {
            $bindTypes = 'i' . $types;
            $st->bind_param($bindTypes, $questionId, ...$choiceIds);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $cid = (int) $row['choice_id'];
                $key = strtolower(trim((string) ($row['member_key'] ?? '')));
                if ($cid > 0 && $key !== '') {
                    $titleScoreMap[$questionId][$cid][$key] = results_formula_round((float) $row['score'], 2);
                }
            }
            $st->close();
        }

        $entryRowsByQ = function_exists('award_entry_score_rows_for_questions')
            ? award_entry_score_rows_for_questions($conn, [$questionId])
            : [];
        $entryScoreMap = twg_fetch_entry_member_score_map($conn, [$questionId]);
        $memberDefs = twg_member_definitions($conn, $eventId > 0 ? $eventId : null);
        $panelByQ = twg_panel_members_by_question($memberDefs, $titleScoreMap, $entryScoreMap);
        $panel = $panelByQ[$questionId] ?? $memberDefs;

        $ranked = [];
        $ungraded = [];
        foreach ($choiceIds as $cid) {
            $entries = is_array($entryRowsByQ[$questionId][$cid] ?? null) ? $entryRowsByQ[$questionId][$cid] : [];
            $grade = twg_grade_award_pair(
                $entries,
                $titleScoreMap[$questionId][$cid] ?? [],
                $entryScoreMap[$questionId][$cid] ?? [],
                $panel
            );
            $item = [
                'choice_id' => $cid,
                'choice_name' => $names[$cid] ?? '',
                'average' => $grade['average'] !== null ? (float) $grade['average'] : -1.0,
                'fully' => !empty($grade['fully']),
            ];
            if ($item['fully']) {
                $ranked[] = $item;
            } else {
                $ungraded[] = $item;
            }
        }
        $sorter = static function (array $a, array $b): int {
            $cmp = ((float) $b['average']) <=> ((float) $a['average']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) $a['choice_name'], (string) $b['choice_name']);
        };
        usort($ranked, $sorter);
        usort($ungraded, $sorter);
        $ordered = array_merge($ranked, $ungraded);
        $ids = [];
        foreach ($ordered as $item) {
            if (count($ids) >= $limit) {
                break;
            }
            $ids[] = (int) $item['choice_id'];
        }
        return $ids;
    }
}

if (!function_exists('twg_ballot_filter_loaded_choices')) {
    /**
     * @param list<array<string,mixed>> $choices
     * @return list<array<string,mixed>>
     */
    function twg_ballot_filter_loaded_choices(
        mysqli $conn,
        string $categoryName,
        int $questionId,
        array $choices,
        bool $named = false
    ): array {
        if ($choices === [] || $categoryName === '' || !twg_category_uses_shortlist($categoryName)) {
            return $choices;
        }
        $ids = [];
        foreach ($choices as $choice) {
            $cid = $named
                ? (int) ($choice['business_choice_id'] ?? 0)
                : (int) ($choice['choice_id'] ?? 0);
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) <= 5) {
            return $choices;
        }
        $keep = array_fill_keys(
            twg_ballot_shortlist_from_ids($conn, $questionId, $ids, 5),
            true
        );
        $out = [];
        foreach ($choices as $choice) {
            $cid = $named
                ? (int) ($choice['business_choice_id'] ?? 0)
                : (int) ($choice['choice_id'] ?? 0);
            if (isset($keep[$cid])) {
                $out[] = $choice;
            }
        }
        return $out;
    }
}

if (!function_exists('twg_ballot_public_business_allowed')) {
    function twg_ballot_public_business_allowed(mysqli $conn, int $questionId, int $choiceId): bool
    {
        if ($questionId <= 0 || $choiceId <= 0) {
            return false;
        }
        try {
            $meta = twg_ballot_question_meta($conn, $questionId);
            $categoryName = (string) ($meta['category_name'] ?? '');
            if ($categoryName === '' || !twg_category_uses_shortlist($categoryName)) {
                return true;
            }
            $ids = twg_ballot_on_ballot_choice_ids_for_question($conn, $questionId);
            if ($ids === []) {
                return false;
            }
            if (!in_array($choiceId, $ids, true)) {
                return false;
            }
            $keep = twg_ballot_shortlist_from_ids($conn, $questionId, $ids, 5);
            return in_array($choiceId, $keep, true);
        } catch (Throwable $e) {
            error_log('twg_ballot_public_business_allowed: ' . $e->getMessage());
            return true;
        }
    }
}

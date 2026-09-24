<?php
declare(strict_types=1);

/**
 * Text drawn on the voting QR poster: business name, then remaining award titles.
 */

if (!function_exists('qr_poster_sample_caption')) {
    function qr_poster_sample_caption(): string
    {
        return "Sample Cafe\nBest Halo-halo\nBest Coffee Shop";
    }
}

if (!function_exists('qr_poster_award_lines_for_choice')) {
    /**
     * @return list<string>
     */
    function qr_poster_award_lines_for_choice(mysqli $conn, int $choiceId): array
    {
        if ($choiceId <= 0) {
            return [];
        }

        $helpers = __DIR__ . '/award_entry_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
        $formula = __DIR__ . '/results_formula.php';
        if (is_file($formula)) {
            require_once $formula;
        }

        $allowed = null;
        if (function_exists('twg_remaining_question_ids_for_choice')) {
            $allowed = twg_remaining_question_ids_for_choice($conn, $choiceId);
        }
        $allowedMap = null;
        if (is_array($allowed)) {
            $allowedMap = [];
            foreach ($allowed as $qid) {
                $qid = (int) $qid;
                if ($qid > 0) {
                    $allowedMap[$qid] = true;
                }
            }
        }

        $st = $conn->prepare(
            'SELECT q.question_id, q.question_name
             FROM tbl_question_choices qc
             INNER JOIN tbl_questions q ON q.question_id = qc.question_id
             LEFT JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE qc.choice_id = ?
             ORDER BY c.category_name ASC, q.question_name ASC'
        );
        if (!$st) {
            return [];
        }
        $st->bind_param('i', $choiceId);
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $qid = (int) ($row['question_id'] ?? 0);
            $name = trim((string) ($row['question_name'] ?? ''));
            if ($qid <= 0 || $name === '') {
                continue;
            }
            if (is_array($allowedMap) && $allowedMap !== [] && !isset($allowedMap[$qid])) {
                continue;
            }
            if (is_array($allowedMap) && $allowedMap === []) {
                continue;
            }
            $rows[] = ['question_id' => $qid, 'question_name' => $name];
        }
        $st->close();

        $products = function_exists('award_entry_names_for_choice')
            ? award_entry_names_for_choice($conn, $choiceId)
            : [];

        $lines = [];
        foreach ($rows as $row) {
            $qid = (int) $row['question_id'];
            $name = (string) $row['question_name'];
            $product = trim((string) ($products[$qid] ?? ''));
            $lines[] = $product !== '' ? ($name . ' — ' . $product) : $name;
        }

        $max = 5;
        if (count($lines) > $max) {
            $extra = count($lines) - $max;
            $lines = array_slice($lines, 0, $max);
            $lines[] = $extra === 1 ? '+1 more title' : ('+' . $extra . ' more titles');
        }
        return $lines;
    }
}

if (!function_exists('qr_poster_caption_for_choice')) {
    function qr_poster_caption_for_choice(mysqli $conn, int $choiceId, string $choiceName): string
    {
        $parts = [];
        $business = trim($choiceName);
        if ($business !== '') {
            $parts[] = $business;
        }
        foreach (qr_poster_award_lines_for_choice($conn, $choiceId) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $parts[] = $line;
            }
        }
        return implode("\n", $parts);
    }
}

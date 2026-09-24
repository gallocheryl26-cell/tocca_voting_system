<?php
declare(strict_types=1);

require_once __DIR__ . '/category_voting_profile.php';

/**
 * Per-award voter field layout, maintained under File Maintenance → Name of Awards.
 *
 *   business_photo   — business dropdown + optional photo (Food / Service)
 *   product_business — one ballot dropdown of registered names (Business - Product).
 *                    Feelings food awards also show optional proof of purchase.
 *                    Make-up Artist / Event Stylist: named dropdown, no proof.
 *                    Place awards such as Best Date Place should use business_photo.
 *   song_singer      — song title + singer (Best Break Up Song)
 *   meryenda         — product dropdown, vendor name / location, or Not on the list
 */

if (!function_exists('award_answer_fields_keys')) {
    /** @return list<string> */
    function award_answer_fields_keys(): array
    {
        return ['business_photo', 'product_business', 'song_singer', 'meryenda'];
    }
}

if (!function_exists('award_answer_fields_normalize')) {
    function award_answer_fields_normalize(?string $fields): string
    {
        $p = strtolower(trim((string) $fields));
        return in_array($p, award_answer_fields_keys(), true) ? $p : 'business_photo';
    }
}

if (!function_exists('award_answer_fields_admin_options')) {
    /** @return array<string, array{label:string, description:string}> */
    function award_answer_fields_admin_options(): array
    {
        return [
            'business_photo' => [
                'label'       => 'Business name + photo',
                'description' => 'Voters pick a business from the list. A photo is optional. Use for Food and Service.',
            ],
            'product_business' => [
                'label'       => 'Business - product (one dropdown)',
                'description' => 'Registration collects one final product / artist / stylist name. Voters pick from a single dropdown (business - product). Use for Feelings food and Make-up Artist / Event Stylist. Place awards like Best Date Place should use Business name + photo instead.',
            ],
            'song_singer' => [
                'label'       => 'Song title + singer',
                'description' => 'Voters type both answers. No business list and no photo. Use for Best Break Up Song.',
            ],
            'meryenda' => [
                'label'       => 'Product + vendor name / location',
                'description' => 'Voters pick the product, then type the vendor name and location. Not on the list lets them type the product and the vendor. Use for Most Popular Local Meryenda.',
            ],
        ];
    }
}

if (!function_exists('award_answer_fields_label')) {
    function award_answer_fields_label(?string $fields): string
    {
        $opts = award_answer_fields_admin_options();
        $key = award_answer_fields_normalize($fields);
        return $opts[$key]['label'] ?? $opts['business_photo']['label'];
    }
}

if (!function_exists('award_answer_fields_is_artist_award')) {
    /** Makeup / hairstylist awards: two typed answers (artist + business), not a business dropdown. */
    function award_answer_fields_is_artist_award(string $awardName): bool
    {
        $n = mb_strtolower(trim($awardName));
        if ($n === '') {
            return false;
        }
        foreach (['make-up artist', 'makeup artist', 'make up artist', 'hairstylist', 'hair stylist'] as $phrase) {
            if (str_contains($n, $phrase)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('award_answer_fields_artist_name_sql')) {
    function award_answer_fields_artist_name_sql(string $questionAlias = 'q'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $questionAlias) ?: 'q';
        return "(LOWER({$alias}.question_name) LIKE '%make-up artist%'
            OR LOWER({$alias}.question_name) LIKE '%makeup artist%'
            OR LOWER({$alias}.question_name) LIKE '%make up artist%'
            OR LOWER({$alias}.question_name) LIKE '%hairstylist%'
            OR LOWER({$alias}.question_name) LIKE '%hair stylist%')";
    }
}

if (!function_exists('award_answer_fields_is_place_award')) {
    /** Place titles: one typed answer (name of place), not a business dropdown and not a pair. */
    function award_answer_fields_is_place_award(string $awardName): bool
    {
        $n = mb_strtolower(trim($awardName));
        if ($n === '') {
            return false;
        }
        foreach (['date place', 'hangout spot', 'viewing spot', 'tourist spot'] as $phrase) {
            if (str_contains($n, $phrase)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('award_answer_fields_place_name_sql')) {
    function award_answer_fields_place_name_sql(string $questionAlias = 'q'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $questionAlias) ?: 'q';
        return "(LOWER({$alias}.question_name) LIKE '%date place%'
            OR LOWER({$alias}.question_name) LIKE '%hangout spot%'
            OR LOWER({$alias}.question_name) LIKE '%viewing spot%'
            OR LOWER({$alias}.question_name) LIKE '%tourist spot%')";
    }
}

if (!function_exists('award_answer_fields_is_single_field')) {
    function award_answer_fields_is_single_field(string $awardName): bool
    {
        return award_answer_fields_is_place_award($awardName);
    }
}

if (!function_exists('award_answer_fields_product_copy')) {
    /**
     * Typed-answer labels for product_business. Makeup/hairstylist and place awards use special copy.
     *
     * @return array{
     *   open_label:string,
     *   open_placeholder:string,
     *   open_label_2:string,
     *   open_placeholder_2:string,
     *   list_instruction:string,
     *   validation_message:string,
     *   validation_message_switch:string,
     *   single_field:bool
     * }
     */
    function award_answer_fields_product_copy(string $awardName = ''): array
    {
        if (award_answer_fields_is_place_award($awardName)) {
            return [
                'open_label' => 'Name of Place',
                'open_placeholder' => 'Type the name of the place',
                'open_label_2' => '',
                'open_placeholder_2' => '',
                'list_instruction' => 'Type the name of the place.',
                'validation_message' => 'Please enter the name of the place.',
                'validation_message_switch' => 'Please enter the name of the place before changing categories.',
                'show_proof' => false,
                'proof_label' => '',
                'proof_hint' => '',
                'proof_add_label' => '',
                'single_field' => true,
            ];
        }

        if (award_answer_fields_is_artist_award($awardName)) {
            return [
                'open_label' => 'Hairstylist / Artist Name',
                'open_placeholder' => 'Type the hairstylist / artist name',
                'open_label_2' => 'Business Name',
                'open_placeholder_2' => 'Type the business name',
                'list_instruction' => 'Type the hairstylist / artist name and the business name.',
                'validation_message' => 'Please enter the hairstylist / artist name and the business name.',
                'validation_message_switch' => 'Please enter the hairstylist / artist name and the business name before changing categories.',
                'show_proof' => false,
                'proof_label' => '',
                'proof_hint' => '',
                'proof_add_label' => '',
                'single_field' => false,
            ];
        }

        return [
            'open_label' => 'Product Name',
            'open_placeholder' => 'Type the product name',
            'open_label_2' => 'Business Name',
            'open_placeholder_2' => 'Type the business name',
            'list_instruction' => 'Type the product and the business name.',
            'validation_message' => 'Please enter the product name and the business name.',
            'validation_message_switch' => 'Please enter the product name and the business name before changing categories.',
            'single_field' => false,
        ];
    }
}

if (!function_exists('award_answer_fields_infer')) {
    function award_answer_fields_infer(?string $categoryProfile, string $awardName = '', ?int $choiceType = null): string
    {
        if (award_answer_fields_is_artist_award($awardName) || award_answer_fields_is_place_award($awardName)) {
            return 'product_business';
        }
        if (str_contains(mb_strtolower($awardName), 'meryenda')) {
            return 'meryenda';
        }
        if ($choiceType !== null && (int) $choiceType === 0) {
            return 'song_singer';
        }
        if (category_voting_profile_infer_from_award_name($awardName) === 'media') {
            return 'song_singer';
        }
        $profile = category_voting_profile_normalize($categoryProfile);
        if ($profile === 'media') {
            return 'song_singer';
        }
        if ($profile === 'mixed') {
            return 'product_business';
        }
        return 'business_photo';
    }
}

if (!function_exists('award_answer_fields_default')) {
    function award_answer_fields_default(?string $categoryProfile, string $awardName = ''): string
    {
        return award_answer_fields_infer($categoryProfile, $awardName, null);
    }
}

if (!function_exists('award_answer_fields_from_row')) {
    /** @param array<string,mixed> $row */
    function award_answer_fields_from_row(array $row, ?string $categoryProfile = null): string
    {
        $raw = trim((string) ($row['answer_fields'] ?? ''));
        if ($raw !== '') {
            return award_answer_fields_normalize($raw);
        }
        return award_answer_fields_infer(
            $categoryProfile,
            (string) ($row['question_name'] ?? ''),
            isset($row['choice_type']) ? (int) $row['choice_type'] : null
        );
    }
}

if (!function_exists('award_answer_fields_choice_type')) {
    function award_answer_fields_choice_type(?string $fields): int
    {
        // Only song titles stay fully freeform (choice_type 0).
        return award_answer_fields_normalize($fields) === 'song_singer' ? 0 : 1;
    }
}

if (!function_exists('award_answer_fields_uses_open_text')) {
    function award_answer_fields_uses_open_text(?string $fields): bool
    {
        // Feelings food / artist / stylist now use registration-fed ballot dropdowns.
        return award_answer_fields_normalize($fields) === 'song_singer';
    }
}

if (!function_exists('award_answer_fields_uses_product')) {
    function award_answer_fields_uses_product(?string $fields): bool
    {
        return award_answer_fields_normalize($fields) === 'product_business';
    }
}

if (!function_exists('award_answer_fields_uses_ballot_entries')) {
    /** Awards whose voter options come from tbl_award_ballot_entries. */
    function award_answer_fields_uses_ballot_entries(?string $fields, string $awardName = ''): bool
    {
        if (award_answer_fields_normalize($fields) !== 'product_business') {
            return false;
        }
        if (award_answer_fields_is_place_award($awardName)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('award_answer_fields_is_stylist_award')) {
    function award_answer_fields_is_stylist_award(string $awardName): bool
    {
        $n = mb_strtolower(trim($awardName));
        if ($n === '') {
            return false;
        }
        return str_contains($n, 'event stylist') || (str_contains($n, 'stylist') && !str_contains($n, 'hair'));
    }
}

if (!function_exists('award_answer_fields_uses_business_list')) {
    function award_answer_fields_uses_business_list(?string $fields): bool
    {
        return award_answer_fields_normalize($fields) === 'business_photo';
    }
}

if (!function_exists('award_answer_fields_labels')) {
    /**
     * @return array<string,mixed>
     */
    function award_answer_fields_labels(?string $fields, ?string $categoryProfile = null, string $awardName = ''): array
    {
        $fields = award_answer_fields_normalize($fields);
        if ($fields === 'song_singer') {
            $labels = category_voting_profile_labels('media');
            $labels['answer_fields'] = $fields;
            $labels['uses_product'] = false;
            $labels['single_field'] = false;
            return $labels;
        }

        if ($fields === 'meryenda') {
            return [
                'profile' => 'general',
                'answer_fields' => 'meryenda',
                'uses_open_text' => false,
                'uses_product' => false,
                'uses_ballot_entries' => false,
                'show_proof' => false,
                'single_field' => false,
                'list_instruction' => 'Pick the product, then type the vendor name and location. If it is not on the list, choose Not on the list and type the product.',
                'open_label' => 'Product',
                'open_placeholder' => 'Type the product',
                'open_label_2' => 'Vendor name / location',
                'open_placeholder_2' => 'Type the vendor name and location',
                'proof_label' => '',
                'proof_hint' => '',
                'proof_add_label' => '',
                'validation_message' => 'Please choose the product and enter the vendor name and location.',
                'validation_message_switch' => 'Please choose the product and enter the vendor name and location before changing categories.',
            ];
        }

        if ($fields === 'product_business') {
            $labels = category_voting_profile_labels($categoryProfile ?: 'business');
            $labels['answer_fields'] = $fields;
            $labels['uses_open_text'] = false;
            $labels['uses_product'] = false;
            $labels['uses_ballot_entries'] = award_answer_fields_uses_ballot_entries($fields, $awardName);
            $isArtist = award_answer_fields_is_artist_award($awardName);
            $isStylist = award_answer_fields_is_stylist_award($awardName);
            $labels['show_proof'] = !$isArtist && !$isStylist && !award_answer_fields_is_place_award($awardName);
            $labels['single_field'] = true;
            if ($isArtist) {
                $labels['list_instruction'] = 'Pick the business and make-up artist from the list.';
                $labels['validation_message'] = 'Please select a business - artist from the list.';
                $labels['validation_message_switch'] = 'Please select a business - artist from the list before changing categories.';
            } elseif ($isStylist) {
                $labels['list_instruction'] = 'Pick the business and stylist from the list.';
                $labels['validation_message'] = 'Please select a business - stylist from the list.';
                $labels['validation_message_switch'] = 'Please select a business - stylist from the list before changing categories.';
            } else {
                $labels['list_instruction'] = 'Pick the business and product from the list. Proof of purchase is optional.';
                $labels['validation_message'] = 'Please select a business - product from the list.';
                $labels['validation_message_switch'] = 'Please select a business - product from the list before changing categories.';
            }
            return $labels;
        }

        $base = category_voting_profile_labels($categoryProfile ?: 'business');
        $base['answer_fields'] = $fields;
        $base['uses_open_text'] = false;
        $base['uses_product'] = false;
        $base['show_proof'] = true;
        $base['single_field'] = false;
        return $base;
    }
}

if (!function_exists('award_answer_fields_question_meta')) {
    /** @return array{fields:string, name:string} */
    function award_answer_fields_question_meta(mysqli $conn, int $questionId): array
    {
        static $cache = [];
        if ($questionId <= 0) {
            return ['fields' => 'business_photo', 'name' => ''];
        }
        if (isset($cache[$questionId])) {
            return $cache[$questionId];
        }
        award_answer_fields_ensure_schema($conn);
        $st = $conn->prepare(
            'SELECT q.answer_fields, q.question_name, q.choice_type, c.voting_profile
             FROM tbl_questions q
             LEFT JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE q.question_id = ? LIMIT 1'
        );
        if (!$st) {
            $cache[$questionId] = ['fields' => 'business_photo', 'name' => ''];
            return $cache[$questionId];
        }
        $st->bind_param('i', $questionId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
        $profile = isset($row['voting_profile'])
            ? category_voting_profile_from_row($row)
            : null;
        $cache[$questionId] = [
            'fields' => award_answer_fields_from_row($row, $profile),
            'name' => (string) ($row['question_name'] ?? ''),
        ];
        return $cache[$questionId];
    }
}

if (!function_exists('award_answer_fields_for_question')) {
    function award_answer_fields_for_question(mysqli $conn, int $questionId): string
    {
        return award_answer_fields_question_meta($conn, $questionId)['fields'];
    }
}

if (!function_exists('award_answer_fields_question_name')) {
    function award_answer_fields_question_name(mysqli $conn, int $questionId): string
    {
        return award_answer_fields_question_meta($conn, $questionId)['name'];
    }
}

if (!function_exists('award_answer_fields_migrate_artist_awards')) {
    function award_answer_fields_migrate_artist_awards(mysqli $conn): void
    {
        $artistName = award_answer_fields_artist_name_sql('q');
        @$conn->query(
            "UPDATE tbl_questions q
             SET q.answer_fields = 'product_business', q.choice_type = 1
             WHERE {$artistName}"
        );
        @$conn->query(
            "UPDATE tbl_questions q
             SET q.answer_fields = 'product_business', q.choice_type = 1
             WHERE (
               LOWER(q.question_name) LIKE '%event stylist%'
               OR LOWER(q.question_name) LIKE '%stylist%'
             )"
        );
    }
}

if (!function_exists('award_answer_fields_migrate_place_awards')) {
    function award_answer_fields_migrate_place_awards(mysqli $conn): void
    {
        // Place awards: business dropdown only (no typed product/place name).
        $placeName = award_answer_fields_place_name_sql('q');
        @$conn->query(
            "UPDATE tbl_questions q
             SET q.answer_fields = 'business_photo', q.choice_type = 1
             WHERE {$placeName}"
        );
    }
}

if (!function_exists('award_answer_fields_migrate_meryenda')) {
    /** Local meryenda: product list, vendor name / location, and Not on the list. */
    function award_answer_fields_migrate_meryenda(mysqli $conn): void
    {
        @$conn->query(
            "UPDATE tbl_questions
             SET answer_fields = 'meryenda', choice_type = 1
             WHERE LOWER(question_name) LIKE '%meryenda%'"
        );

        $rename = [
            'toron' => 'Toron',
            'lutong mani' => 'Steamed peanuts (mani)',
            'steamed peanuts (mani)' => 'Steamed peanuts (mani)',
            'fried mani' => 'Fried peanuts',
            'fried peanuts' => 'Fried peanuts',
            'hotcake with margarine' => 'Hotcake with margarine',
            'ginabot' => 'Ginabot',
            'leche flan' => 'Homemade leche flan',
            'leche flan ni ____' => 'Homemade leche flan',
            'leche flan ni ___' => 'Homemade leche flan',
            'leche flan ni __' => 'Homemade leche flan',
            'homemade leche flan' => 'Homemade leche flan',
            'bananacue' => 'Banana cue',
            'banana cue' => 'Banana cue',
            'camotecue' => 'Camote cue',
            'camote cue' => 'Camote cue',
            'borj fish ball' => 'Fishball',
            'fishball' => 'Fishball',
            'fish ball' => 'Fishball',
            'kikiam' => 'Kikiam',
        ];
        $canonical = [
            'Toron',
            'Steamed peanuts (mani)',
            'Fried peanuts',
            'Hotcake with margarine',
            'Ginabot',
            'Homemade leche flan',
            'Banana cue',
            'Camote cue',
            'Fishball',
            'Kikiam',
        ];

        $questions = $conn->query(
            "SELECT q.question_id, c.event_id
             FROM tbl_questions q
             INNER JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE q.answer_fields = 'meryenda'"
        );
        if (!$questions) {
            return;
        }

        $hasChoiceBallot = false;
        $choiceCol = $conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'on_ballot'");
        if ($choiceCol && $choiceCol->num_rows > 0) {
            $hasChoiceBallot = true;
        }
        if ($choiceCol) {
            $choiceCol->free();
        }
        $hasLinkBallot = false;
        $linkCol = $conn->query("SHOW COLUMNS FROM tbl_question_choices LIKE 'on_ballot'");
        if ($linkCol && $linkCol->num_rows > 0) {
            $hasLinkBallot = true;
        }
        if ($linkCol) {
            $linkCol->free();
        }

        while ($row = $questions->fetch_assoc()) {
            $questionId = (int) ($row['question_id'] ?? 0);
            $eventId = (int) ($row['event_id'] ?? 0);
            if ($questionId <= 0 || $eventId <= 0) {
                continue;
            }

            $linkedRows = $conn->prepare(
                'SELECT ch.choice_id, ch.choice_name
                 FROM tbl_question_choices qc
                 INNER JOIN tbl_choices ch ON ch.choice_id = qc.choice_id
                 WHERE qc.question_id = ?'
            );
            if (!$linkedRows) {
                continue;
            }
            $linkedRows->bind_param('i', $questionId);
            $linkedRows->execute();
            $linkedRes = $linkedRows->get_result();
            $current = [];
            while ($choice = $linkedRes->fetch_assoc()) {
                $current[] = $choice;
            }
            $linkedRows->close();

            foreach ($current as $choice) {
                $choiceId = (int) ($choice['choice_id'] ?? 0);
                $key = mb_strtolower(trim((string) ($choice['choice_name'] ?? '')));
                if ($choiceId <= 0) {
                    continue;
                }
                if (!isset($rename[$key])) {
                    $unlink = $conn->prepare(
                        'DELETE FROM tbl_question_choices WHERE question_id = ? AND choice_id = ?'
                    );
                    if ($unlink) {
                        $unlink->bind_param('ii', $questionId, $choiceId);
                        $unlink->execute();
                        $unlink->close();
                    }
                    if ($hasChoiceBallot) {
                        $still = $conn->prepare('SELECT 1 FROM tbl_question_choices WHERE choice_id = ? LIMIT 1');
                        $left = false;
                        if ($still) {
                            $still->bind_param('i', $choiceId);
                            $still->execute();
                            $left = (bool) $still->get_result()->fetch_assoc();
                            $still->close();
                        }
                        if (!$left) {
                            $hide = $conn->prepare('UPDATE tbl_choices SET on_ballot = 0 WHERE choice_id = ?');
                            if ($hide) {
                                $hide->bind_param('i', $choiceId);
                                $hide->execute();
                                $hide->close();
                            }
                        }
                    }
                    continue;
                }
                $target = $rename[$key];
                if (trim((string) ($choice['choice_name'] ?? '')) === $target) {
                    continue;
                }
                $shared = $conn->prepare(
                    "SELECT COUNT(*) AS n
                     FROM tbl_question_choices qc
                     INNER JOIN tbl_questions q ON q.question_id = qc.question_id
                     WHERE qc.choice_id = ? AND q.answer_fields <> 'meryenda'"
                );
                $sharedCount = 0;
                if ($shared) {
                    $shared->bind_param('i', $choiceId);
                    $shared->execute();
                    $sharedCount = (int) ($shared->get_result()->fetch_assoc()['n'] ?? 0);
                    $shared->close();
                }
                if ($sharedCount > 0) {
                    $unlink = $conn->prepare(
                        'DELETE FROM tbl_question_choices WHERE question_id = ? AND choice_id = ?'
                    );
                    if ($unlink) {
                        $unlink->bind_param('ii', $questionId, $choiceId);
                        $unlink->execute();
                        $unlink->close();
                    }
                    continue;
                }
                $renameStmt = $conn->prepare('UPDATE tbl_choices SET choice_name = ? WHERE choice_id = ?');
                if ($renameStmt) {
                    $renameStmt->bind_param('si', $target, $choiceId);
                    $renameStmt->execute();
                    $renameStmt->close();
                }
            }

            foreach ($canonical as $snack) {
                $find = $conn->prepare(
                    'SELECT choice_id FROM tbl_choices
                     WHERE event_id = ? AND LOWER(TRIM(choice_name)) = LOWER(?) LIMIT 1'
                );
                if (!$find) {
                    continue;
                }
                $find->bind_param('is', $eventId, $snack);
                $find->execute();
                $choiceId = (int) ($find->get_result()->fetch_assoc()['choice_id'] ?? 0);
                $find->close();
                if ($choiceId <= 0) {
                    $insertSql = $hasChoiceBallot
                        ? 'INSERT INTO tbl_choices (choice_name, email, event_id, status, on_ballot) VALUES (?, NULL, ?, 1, 1)'
                        : 'INSERT INTO tbl_choices (choice_name, email, event_id, status) VALUES (?, NULL, ?, 1)';
                    $insert = $conn->prepare($insertSql);
                    if (!$insert) {
                        continue;
                    }
                    $insert->bind_param('si', $snack, $eventId);
                    $insert->execute();
                    $choiceId = (int) $insert->insert_id;
                    $insert->close();
                } elseif ($hasChoiceBallot) {
                    $turnOn = $conn->prepare('UPDATE tbl_choices SET status = 1, on_ballot = 1 WHERE choice_id = ?');
                    if ($turnOn) {
                        $turnOn->bind_param('i', $choiceId);
                        $turnOn->execute();
                        $turnOn->close();
                    }
                }
                if ($choiceId <= 0) {
                    continue;
                }
                $linked = $conn->prepare(
                    'SELECT 1 FROM tbl_question_choices WHERE question_id = ? AND choice_id = ? LIMIT 1'
                );
                if (!$linked) {
                    continue;
                }
                $linked->bind_param('ii', $questionId, $choiceId);
                $linked->execute();
                $already = (bool) $linked->get_result()->fetch_assoc();
                $linked->close();
                if (!$already) {
                    $link = $conn->prepare(
                        'INSERT INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)'
                    );
                    if ($link) {
                        $link->bind_param('ii', $questionId, $choiceId);
                        $link->execute();
                        $link->close();
                    }
                }
                if ($hasLinkBallot) {
                    $linkOn = $conn->prepare(
                        'UPDATE tbl_question_choices SET on_ballot = 1 WHERE question_id = ? AND choice_id = ?'
                    );
                    if ($linkOn) {
                        $linkOn->bind_param('ii', $questionId, $choiceId);
                        $linkOn->execute();
                        $linkOn->close();
                    }
                }
            }
        }
        $questions->free();
    }
}

if (!function_exists('award_answer_fields_ensure_schema')) {
    function award_answer_fields_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            category_voting_profile_ensure_schema($conn);
        } catch (Throwable $e) {
            error_log('award_answer_fields_ensure_schema profile: ' . $e->getMessage());
        }

        $exists = false;
        try {
            if (function_exists('admin_schema_column_exists')) {
                $exists = admin_schema_column_exists($conn, 'tbl_questions', 'answer_fields');
            } else {
                $res = $conn->query("SHOW COLUMNS FROM tbl_questions LIKE 'answer_fields'");
                $exists = $res && $res->num_rows > 0;
                if ($res) {
                    $res->close();
                }
            }
        } catch (Throwable $e) {
            error_log('award_answer_fields_ensure_schema detect: ' . $e->getMessage());
            return;
        }

        if ($exists) {
            try {
                award_answer_fields_migrate_meryenda($conn);
            } catch (Throwable $e) {
                error_log('award_answer_fields_migrate_meryenda: ' . $e->getMessage());
            }
            return;
        }

        try {
            $conn->query(
                "ALTER TABLE tbl_questions
                 ADD COLUMN answer_fields VARCHAR(32) NOT NULL DEFAULT 'business_photo'
                 AFTER choice_type"
            );
            award_answer_fields_migrate_meryenda($conn);
        } catch (Throwable $e) {
            error_log('award_answer_fields_ensure_schema alter: ' . $e->getMessage());
        }
    }
}

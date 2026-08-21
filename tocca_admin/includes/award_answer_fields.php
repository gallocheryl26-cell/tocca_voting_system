<?php
declare(strict_types=1);

require_once __DIR__ . '/category_voting_profile.php';

/**
 * Per-award voter field layout, maintained under File Maintenance → Name of Awards.
 *
 *   business_photo   — business dropdown + optional photo (Food / Service)
 *   product_business — ballot dropdown of registered names (Product — Business).
 *                    Feelings food awards also show optional proof of purchase.
 *                    Make-up Artist / Event Stylist: named dropdown, no proof.
 *                    Place awards such as Best Date Place should use business_photo.
 *   song_singer      — song title + singer (Best Break Up Song)
 */

if (!function_exists('award_answer_fields_keys')) {
    /** @return list<string> */
    function award_answer_fields_keys(): array
    {
        return ['business_photo', 'product_business', 'song_singer'];
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
                'label'       => 'Named entry + business (ballot)',
                'description' => 'Registration collects product / artist / stylist name(s). Voters pick from a dropdown (name — business). Use for Feelings food and Make-up Artist / Event Stylist. Place awards like Best Date Place should use Business name + photo instead.',
            ],
            'song_singer' => [
                'label'       => 'Song title + singer',
                'description' => 'Voters type both answers. No business list and no photo. Use for Best Break Up Song.',
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

        if ($fields === 'product_business') {
            $labels = category_voting_profile_labels($categoryProfile ?: 'business');
            $labels['answer_fields'] = $fields;
            $labels['uses_open_text'] = false;
            $labels['uses_product'] = false;
            $labels['uses_ballot_entries'] = award_answer_fields_uses_ballot_entries($fields, $awardName);
            $isArtist = award_answer_fields_is_artist_award($awardName);
            $isStylist = award_answer_fields_is_stylist_award($awardName);
            $labels['show_proof'] = !$isArtist && !$isStylist && !award_answer_fields_is_place_award($awardName);
            $labels['single_field'] = false;
            if ($isArtist) {
                $labels['list_instruction'] = 'Pick the make-up artist and business from the list.';
                $labels['validation_message'] = 'Please select a make-up artist from the list.';
                $labels['validation_message_switch'] = 'Please select a make-up artist from the list before changing categories.';
            } elseif ($isStylist) {
                $labels['list_instruction'] = 'Pick the stylist and business from the list.';
                $labels['validation_message'] = 'Please select a stylist from the list.';
                $labels['validation_message_switch'] = 'Please select a stylist from the list before changing categories.';
            } else {
                $labels['list_instruction'] = 'Pick the product and business from the list. Proof of purchase is optional.';
                $labels['validation_message'] = 'Please select a product from the list.';
                $labels['validation_message_switch'] = 'Please select a product from the list before changing categories.';
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

if (!function_exists('award_answer_fields_ensure_schema')) {
    function award_answer_fields_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        category_voting_profile_ensure_schema($conn);

        $res = $conn->query("SHOW COLUMNS FROM tbl_questions LIKE 'answer_fields'");
        $exists = $res && $res->num_rows > 0;
        if ($res) {
            $res->close();
        }
        if ($exists) {
            award_answer_fields_migrate_artist_awards($conn);
            award_answer_fields_migrate_place_awards($conn);
            // Named product/artist/stylist awards use ballot dropdowns (choice_type 1).
            @$conn->query(
                "UPDATE tbl_questions
                 SET choice_type = 1
                 WHERE answer_fields = 'product_business'
                   AND COALESCE(choice_type, 1) <> 1"
            );
            @$conn->query(
                "UPDATE tbl_questions
                 SET choice_type = 0
                 WHERE answer_fields = 'song_singer'
                   AND COALESCE(choice_type, 1) <> 0"
            );
            return;
        }

        @$conn->query(
            "ALTER TABLE tbl_questions
             ADD COLUMN answer_fields VARCHAR(32) NOT NULL DEFAULT 'business_photo'
             AFTER choice_type"
        );

        $songName = "(LOWER(q.question_name) LIKE '%song%'
            OR LOWER(q.question_name) LIKE '%music%'
            OR LOWER(q.question_name) LIKE '%anthem%'
            OR LOWER(q.question_name) LIKE '%album%'
            OR LOWER(q.question_name) LIKE '%lyric%')";
        @$conn->query(
            "UPDATE tbl_questions q
             SET q.answer_fields = 'song_singer', q.choice_type = 0
             WHERE COALESCE(q.choice_type, 1) <> 1
                OR {$songName}"
        );
        @$conn->query(
            "UPDATE tbl_questions q
             INNER JOIN tbl_categories c ON c.category_id = q.category_id
             SET q.answer_fields = 'product_business', q.choice_type = 1
             WHERE q.answer_fields = 'business_photo'
               AND (
                    LOWER(TRIM(COALESCE(c.voting_profile, ''))) = 'mixed'
                    OR LOWER(TRIM(c.category_name)) LIKE '%feeling%'
               )"
        );
        award_answer_fields_migrate_artist_awards($conn);
        award_answer_fields_migrate_place_awards($conn);
    }
}

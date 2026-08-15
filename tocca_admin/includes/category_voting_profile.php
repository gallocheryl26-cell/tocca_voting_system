<?php
declare(strict_types=1);

/**
 * Category voting profiles — control voter manual-entry labels (business vs song vs place, etc.).
 */

if (!function_exists('category_voting_profile_ensure_schema')) {
    function category_voting_profile_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $res = $conn->query("SHOW COLUMNS FROM tbl_categories LIKE 'voting_profile'");
        if ($res && $res->num_rows > 0) {
            $res->close();
            return;
        }
        if ($res) {
            $res->close();
        }

        @$conn->query(
            "ALTER TABLE tbl_categories
             ADD COLUMN voting_profile VARCHAR(32) NOT NULL DEFAULT 'business'
             AFTER status"
        );
    }
}

if (!function_exists('category_voting_profile_keys')) {
    /** @return list<string> */
    function category_voting_profile_keys(): array
    {
        return ['business', 'media', 'places', 'general', 'mixed'];
    }
}

if (!function_exists('category_voting_profile_normalize')) {
    function category_voting_profile_normalize(?string $profile): string
    {
        $p = strtolower(trim((string) $profile));
        return in_array($p, category_voting_profile_keys(), true) ? $p : 'business';
    }
}

if (!function_exists('category_voting_profile_infer_from_award_name')) {
    /**
     * Guess label set from award title (for mixed categories: songs, places, businesses, etc.).
     */
    function category_voting_profile_infer_from_award_name(string $awardName): string
    {
        $n = mb_strtolower(trim($awardName));
        if ($n === '') {
            return 'general';
        }

        $phrases = [
            'media' => ['break up song', 'breakup song', 'love song', 'theme song', 'music video'],
            'places' => ['date place', 'hangout spot', 'viewing spot', 'tourist spot'],
            'business' => ['food establishment', 'business establishment', 'food service'],
        ];
        foreach ($phrases as $profile => $list) {
            foreach ($list as $phrase) {
                if (str_contains($n, $phrase)) {
                    return $profile;
                }
            }
        }

        $words = [
            'media' => ['song', 'music', 'anthem', 'opm', 'artist', 'band', 'album', 'tune', 'lyric', 'singer', 'dj'],
            'places' => ['place', 'venue', 'location', 'park', 'beach', 'resort', 'spot', 'hangout', 'destination', 'view'],
            'business' => [
                'business', 'establishment', 'store', 'shop', 'salon', 'spa', 'restaurant', 'cafe', 'coffee',
                'hotel', 'bar', 'mall', 'brand', 'company', 'service', 'provider', 'clinic', 'gym',
            ],
        ];
        foreach (['media', 'places', 'business'] as $profile) {
            foreach ($words[$profile] as $word) {
                if (str_contains($n, $word)) {
                    return $profile;
                }
            }
        }

        return 'general';
    }
}

if (!function_exists('category_voting_profile_admin_options')) {
    /** @return array<string, array{label:string,description:string}> */
    function category_voting_profile_admin_options(): array
    {
        return [
            'business' => [
                'label'       => 'Business establishments',
                'description' => 'Food, shops, services — second box asks for business name.',
            ],
            'media' => [
                'label'       => 'Music & entertainment',
                'description' => 'Songs, artists, performers — second box asks for artist name.',
            ],
            'places' => [
                'label'       => 'Places & locations',
                'description' => 'Date spots, venues — second box asks for city or area.',
            ],
            'general' => [
                'label'       => 'General / feelings',
                'description' => 'Open picks — second box asks for extra detail (artist, area, etc.).',
            ],
            'mixed' => [
                'label'       => 'Mixed (auto by award title)',
                'description' => 'Songs, places, businesses, and more — labels change per award (e.g. Feelings).',
            ],
        ];
    }
}

if (!function_exists('category_voting_profile_labels')) {
    /**
     * @return array{
     *   profile:string,
     *   instruction:string,
     *   list_instruction:string,
     *   field1_label:string,
     *   field1_placeholder:string,
     *   field2_label:string,
     *   field2_placeholder:string,
     *   other_label:string,
     *   other_placeholder:string,
     *   validation_message:string,
     *   validation_message_switch:string
     * }
     */
    function category_voting_profile_labels(?string $profile): array
    {
        $profile = category_voting_profile_normalize($profile);

        $listInstruction = 'Pick your choice from the list, then upload proof of purchase below.';

        $sets = [
            'business' => [
                'proof_label'                => 'Proof of purchase',
                'proof_hint'               => 'Upload a photo showing you at the selected establishment — for example, eating BBQ at the restaurant you chose.',
                'proof_add_label'            => 'Add photo',
                'validation_message'         => 'Please select a business from the list and upload at least one proof-of-purchase photo.',
                'validation_message_switch'  => 'Please complete your dropdown choice and proof upload before changing categories.',
            ],
            'media' => [
                'proof_label'                => 'Proof of purchase',
                'proof_hint'               => 'Upload a photo that shows your experience with your pick — for example, related to the song or artist you chose.',
                'proof_add_label'            => 'Add photo',
                'validation_message'         => 'Please select your choice from the list and upload at least one proof photo.',
                'validation_message_switch'  => 'Please complete your dropdown choice and proof upload before changing categories.',
            ],
            'places' => [
                'proof_label'                => 'Proof of purchase',
                'proof_hint'               => 'Upload a photo showing you at the selected place — for example, at the venue or location you chose.',
                'proof_add_label'            => 'Add photo',
                'validation_message'         => 'Please select a place from the list and upload at least one proof photo.',
                'validation_message_switch'  => 'Please complete your dropdown choice and proof upload before changing categories.',
            ],
            'general' => [
                'proof_label'                => 'Proof of purchase',
                'proof_hint'               => 'Upload a photo that supports your selection.',
                'proof_add_label'            => 'Add photo',
                'validation_message'         => 'Please select your pick from the list and upload at least one proof photo.',
                'validation_message_switch'  => 'Please complete your dropdown choice and proof upload before changing categories.',
            ],
            'mixed' => [
                'proof_label'                => 'Proof of purchase',
                'proof_hint'               => 'Upload a photo showing your experience with your selection.',
                'proof_add_label'            => 'Add photo',
                'validation_message'         => 'Please select your choice from the list and upload at least one proof photo.',
                'validation_message_switch'  => 'Please complete your dropdown choice and proof upload before changing categories.',
            ],
        ];

        $set = $sets[$profile] ?? $sets['business'];

        return array_merge(
            ['profile' => $profile, 'list_instruction' => $listInstruction],
            $set
        );
    }
}

if (!function_exists('category_voting_profile_from_row')) {
    /** @param array<string,mixed> $row */
    function category_voting_profile_from_row(array $row): string
    {
        return category_voting_profile_normalize($row['voting_profile'] ?? 'business');
    }
}

if (!function_exists('category_voting_profile_labels_for_award')) {
    /**
     * Labels for one award — uses per-award inference when category profile is "mixed".
     */
    function category_voting_profile_labels_for_award(?string $categoryProfile, string $awardName = ''): array
    {
        $profile = category_voting_profile_normalize($categoryProfile);
        if ($profile === 'mixed') {
            $inferred = category_voting_profile_infer_from_award_name($awardName);
            $labels = category_voting_profile_labels($inferred);
            $labels['category_profile'] = 'mixed';
            $labels['inferred_profile'] = $inferred;
            return $labels;
        }

        return category_voting_profile_labels($profile);
    }
}

if (!function_exists('category_voting_profile_uses_per_award_labels')) {
    function category_voting_profile_uses_per_award_labels(?string $categoryProfile): bool
    {
        return category_voting_profile_normalize($categoryProfile) === 'mixed';
    }
}

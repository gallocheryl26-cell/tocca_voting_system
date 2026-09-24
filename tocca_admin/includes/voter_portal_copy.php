<?php
declare(strict_types=1);

/**
 * Per-event copy for the public voter portal (welcome modal + how-to-vote).
 */

require_once dirname(__DIR__, 2) . '/nomination/rich_text_helpers.php';

if (!function_exists('voter_portal_copy_ensure_schema')) {
    function voter_portal_copy_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `tbl_voter_portal_copy` (
  `event_id` INT UNSIGNED NOT NULL,
  `intro_title` VARCHAR(255) NOT NULL DEFAULT '',
  `intro_body` MEDIUMTEXT NOT NULL,
  `how_to_title` VARCHAR(255) NOT NULL DEFAULT '',
  `how_to_lead` TEXT NOT NULL,
  `steps_json` JSON NOT NULL,
  `footer_note` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        @$conn->query($sql);
    }
}

if (!function_exists('voter_portal_copy_defaults')) {
    /** @return array{intro_title:string,intro_body:string,how_to_title:string,how_to_lead:string,steps:array<int,array{title:string,body:string}>,footer_note:string} */
    function voter_portal_copy_defaults(): array
    {
        return [
            'intro_title'  => 'Welcome to TOCCA 2026',
            'intro_body'   => <<<'MD'
The City Government of Ormoc through the Tatak Ormoc Business Awards Organizing Committee in partnership with the Ormoc City Chamber of Commerce and Industry is pleased to inform the public of the opening of the **2026 Tatak Ormoc Consumers' Choice Awards (TOCCA)**.

The 2026 TOCCA determines which Ormocanon products and services are top of mind to the citizens, and recognizes the best among them. Vote and choose which of your favorites deserves to be called one of Tatak Ormoc!

Business registration is held through the official TOCCA 2026 registration portal. Qualified entries are placed in each award category for public voting during the official voting period.

Qualified businesses which receive the highest votes shall be declared **2026 Tatak Ormoc Consumers' Choice Award** winners.

**QUALIFICATIONS:**
- Duly registered and in good standing per records of the Business Permits and Licensing Office (BPLO) and other regulatory offices;
- The registered business must have been in operation for at least one (1) year at the time of the award.
MD,
            'how_to_title' => 'How to Vote',
            'how_to_lead'  => "The 2026 Tatak Ormoc Consumers' Choice Awards include multiple categories. Follow these steps to cast your ballot.",
            'steps'        => [
                [
                    'title' => 'Verify your mobile number',
                    'body'  => 'one ballot per mobile. New voters complete OTP and set a 4-digit access code; returning voters enter mobile + code.',
                ],
                [
                    'title' => 'Choose your best per category',
                    'body'  => 'pick from registered businesses in the dropdown. Proof of purchase photos are optional.',
                ],
                [
                    'title' => 'Review your summary',
                    'body'  => 'check every award before submitting. Use Back to change any category.',
                ],
                [
                    'title' => 'Cast your votes',
                    'body'  => 'submit each award from the summary page or use Vote All when every answer is ready.',
                ],
            ],
            'footer_note'  => 'Proof-of-purchase photos may be reviewed by the awards body for eligibility.',
        ];
    }
}

if (!function_exists('voter_portal_copy_normalize')) {
    /** @param array<string,mixed> $row */
    function voter_portal_copy_normalize(array $row): array
    {
        $defaults = voter_portal_copy_defaults();
        $stepsRaw = $row['steps'] ?? $row['steps_json'] ?? null;
        if (is_string($stepsRaw)) {
            $decoded = json_decode($stepsRaw, true);
            $stepsRaw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($stepsRaw)) {
            $stepsRaw = [];
        }

        $steps = [];
        foreach ($stepsRaw as $step) {
            if (!is_array($step)) {
                continue;
            }
            $title = trim((string) ($step['title'] ?? ''));
            $body  = trim((string) ($step['body'] ?? ''));
            if ($title === '' && $body === '') {
                continue;
            }
            $steps[] = [
                'title' => function_exists('tocca_fix_mojibake') ? tocca_fix_mojibake($title) : $title,
                'body'  => function_exists('tocca_fix_mojibake') ? tocca_fix_mojibake($body) : $body,
            ];
        }
        if ($steps === []) {
            $steps = $defaults['steps'];
        }

        $fix = static function (string $s): string {
            return function_exists('tocca_fix_mojibake') ? tocca_fix_mojibake($s) : $s;
        };

        return [
            'intro_title'  => $fix(trim((string) ($row['intro_title'] ?? '')) ?: $defaults['intro_title']),
            'intro_body'   => $fix((string) ($row['intro_body'] ?? '') !== ''
                ? (string) $row['intro_body']
                : $defaults['intro_body']),
            'how_to_title' => $fix(trim((string) ($row['how_to_title'] ?? '')) ?: $defaults['how_to_title']),
            'how_to_lead'  => $fix(trim((string) ($row['how_to_lead'] ?? '')) ?: $defaults['how_to_lead']),
            'steps'        => $steps,
            'footer_note'  => $fix(trim((string) ($row['footer_note'] ?? '')) ?: $defaults['footer_note']),
        ];
    }
}

if (!function_exists('voter_portal_copy_load')) {
    function voter_portal_copy_load(mysqli $conn, int $eventId): array
    {
        voter_portal_copy_ensure_schema($conn);
        $defaults = voter_portal_copy_defaults();

        if ($eventId <= 0) {
            return $defaults;
        }

        $stmt = $conn->prepare(
            'SELECT intro_title, intro_body, how_to_title, how_to_lead, steps_json, footer_note
             FROM tbl_voter_portal_copy WHERE event_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return $defaults;
        }
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $defaults;
        }

        $row['steps'] = $row['steps_json'] ?? '[]';
        return voter_portal_copy_normalize($row);
    }
}

if (!function_exists('voter_portal_copy_save')) {
    /** @param array<string,mixed> $input */
    function voter_portal_copy_save(mysqli $conn, int $eventId, array $input): bool
    {
        if ($eventId <= 0) {
            return false;
        }

        voter_portal_copy_ensure_schema($conn);

        $copy = voter_portal_copy_normalize($input);
        $stepsJson = json_encode($copy['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($stepsJson === false) {
            return false;
        }

        $sql = <<<'SQL'
INSERT INTO tbl_voter_portal_copy
  (event_id, intro_title, intro_body, how_to_title, how_to_lead, steps_json, footer_note)
VALUES (?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  intro_title = VALUES(intro_title),
  intro_body = VALUES(intro_body),
  how_to_title = VALUES(how_to_title),
  how_to_lead = VALUES(how_to_lead),
  steps_json = VALUES(steps_json),
  footer_note = VALUES(footer_note),
  updated_at = CURRENT_TIMESTAMP
SQL;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'issssss',
            $eventId,
            $copy['intro_title'],
            $copy['intro_body'],
            $copy['how_to_title'],
            $copy['how_to_lead'],
            $stepsJson,
            $copy['footer_note']
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('voter_portal_voting_on_hold')) {
    function voter_portal_voting_on_hold(): bool
    {
        return tocca_config('voting_on_hold') === true;
    }
}

if (!function_exists('voter_portal_hold_copy')) {
    /** @return array{intro_title:string,intro_body:string,how_to_title:string,how_to_lead:string,steps:array<int,array{title:string,body:string}>,footer_note:string} */
    function voter_portal_hold_copy(): array
    {
        return [
            'intro_title'  => 'Voting on hold',
            'intro_body'   => <<<'MD'
Due to the high volume of votes, the TOCCA voting system is currently experiencing technical issues.

**Voting is temporarily on hold.**

Voting will resume at the soonest possible time. Thank you for your patience and understanding.
MD,
            'how_to_title' => 'Voting on hold',
            'how_to_lead'  => 'Due to the high volume of votes, the TOCCA voting system is currently experiencing technical issues.',
            'steps'        => [
                [
                    'title' => 'System under maintenance',
                    'body'  => 'Voting is temporarily on hold.',
                ],
                [
                    'title' => 'Please check back soon',
                    'body'  => 'Voting will resume at the soonest possible time.',
                ],
            ],
            'footer_note'  => 'Thank you for your patience and understanding.',
        ];
    }
}

if (!function_exists('voter_portal_copy_intro_html')) {
    function voter_portal_copy_intro_html(array $copy): string
    {
        if (!function_exists('md_to_html_basic')) {
            require_once dirname(__DIR__, 2) . '/nomination/rich_text_helpers.php';
        }
        return md_to_html_basic((string) ($copy['intro_body'] ?? ''));
    }
}

if (!function_exists('voter_portal_copy_from_post')) {
    /** @return array<string,mixed> */
    function voter_portal_copy_from_post(array $post): array
    {
        $steps = [];
        for ($i = 1; $i <= 4; $i++) {
            $steps[] = [
                'title' => trim((string) ($post['step_title_' . $i] ?? '')),
                'body'  => trim((string) ($post['step_body_' . $i] ?? '')),
            ];
        }

        return [
            'intro_title'  => trim((string) ($post['intro_title'] ?? '')),
            'intro_body'   => trim((string) ($post['intro_body'] ?? '')),
            'how_to_title' => trim((string) ($post['how_to_title'] ?? '')),
            'how_to_lead'  => trim((string) ($post['how_to_lead'] ?? '')),
            'steps'        => $steps,
            'footer_note'  => trim((string) ($post['footer_note'] ?? '')),
        ];
    }
}

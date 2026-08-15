<?php
declare(strict_types=1);

/**
 * Reasons for removing an award during registration validation.
 */

if (!function_exists('award_removal_reason_options')) {
    /** @return array<string, string> slug => label */
    function award_removal_reason_options(): array
    {
        return [
            'wrong_category'   => 'Wrong category or business category',
            'does_not_qualify' => 'Does not meet award criteria',
            'business_request' => 'Requested by business',
            'other'            => 'Other',
        ];
    }
}

if (!function_exists('award_removal_reason_keys')) {
    /** @return list<string> */
    function award_removal_reason_keys(): array
    {
        return array_keys(award_removal_reason_options());
    }
}

if (!function_exists('award_removal_reason_normalize')) {
    function award_removal_reason_normalize(?string $reason): ?string
    {
        $key = strtolower(trim((string) $reason));
        return in_array($key, award_removal_reason_keys(), true) ? $key : null;
    }
}

if (!function_exists('award_removal_reason_label')) {
    function award_removal_reason_label(?string $reason): string
    {
        $raw = trim((string) $reason);
        if ($raw === '') {
            return '';
        }
        if ($raw === 'nominee_request') {
            $raw = 'business_request';
        }
        $key = award_removal_reason_normalize($raw);
        if ($key === null) {
            return $raw === 'business_request' ? 'Requested by business' : $raw;
        }
        $opts = award_removal_reason_options();
        return $opts[$key] ?? $raw;
    }
}

if (!function_exists('award_removal_schema_ensure')) {
    /** Ensure tbl_nomination_question_audit.reason exists. */
    function award_removal_schema_ensure(mysqli $conn): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $res = @$conn->query("SHOW COLUMNS FROM tbl_nomination_question_audit LIKE 'reason'");
        if ($res && $res->num_rows > 0) {
            $res->close();
            $available = true;
            return true;
        }
        if ($res) {
            $res->close();
        }

        @$conn->query(
            "ALTER TABLE tbl_nomination_question_audit
             ADD COLUMN reason VARCHAR(64) NULL DEFAULT NULL AFTER action"
        );

        $res = @$conn->query("SHOW COLUMNS FROM tbl_nomination_question_audit LIKE 'reason'");
        $available = ($res && $res->num_rows > 0);
        if ($res) {
            $res->close();
        }
        return $available;
    }
}

if (!function_exists('award_removal_fetch_for_nomination')) {
    /**
     * Latest removal per award for a registration, excluding awards still linked.
     *
     * @return list<array{question_id:int,question_name:string,category_id:int,category_name:string,reason:string,reason_label:string,removed:bool}>
     */
    function award_removal_fetch_for_nomination(mysqli $conn, int $nomination_id): array
    {
        if ($nomination_id <= 0) {
            return [];
        }
        try {
            $hasAudit = false;
            if ($tblChk = $conn->query("SHOW TABLES LIKE 'tbl_nomination_question_audit'")) {
                $hasAudit = $tblChk->num_rows > 0;
                $tblChk->close();
            }
            if (!$hasAudit) {
                return [];
            }

            $hasReasonCol = award_removal_schema_ensure($conn);
            $reasonSelect = $hasReasonCol ? 'a.reason' : "''";
            $sql = "
              SELECT q.question_id,
                     q.question_name,
                     COALESCE(c.category_id, 0) AS category_id,
                     COALESCE(c.category_name, '') AS category_name,
                     {$reasonSelect} AS reason
              FROM tbl_nomination_question_audit a
              INNER JOIN (
                SELECT question_id, MAX(audit_id) AS max_id
                FROM tbl_nomination_question_audit
                WHERE nomination_id = ? AND action = 'REMOVED'
                GROUP BY question_id
              ) latest ON latest.max_id = a.audit_id
              JOIN tbl_questions q ON q.question_id = a.question_id
              LEFT JOIN tbl_categories c ON c.category_id = q.category_id
              WHERE a.nomination_id = ?
                AND a.action = 'REMOVED'
                AND NOT EXISTS (
                  SELECT 1 FROM tbl_nomination_questions nq
                  WHERE nq.nomination_id = a.nomination_id
                    AND nq.question_id = a.question_id
                )
              ORDER BY c.category_name, q.question_name
            ";
            $st = $conn->prepare($sql);
            if (!$st) {
                return [];
            }
            $st->bind_param('ii', $nomination_id, $nomination_id);
            $st->execute();
            $res = $st->get_result();
            $out = [];
            while ($res && ($row = $res->fetch_assoc())) {
                $reasonKey = (string)($row['reason'] ?? '');
                $out[] = [
                    'question_id'   => (int)($row['question_id'] ?? 0),
                    'question_name' => (string)($row['question_name'] ?? ''),
                    'category_id'   => (int)($row['category_id'] ?? 0),
                    'category_name' => (string)($row['category_name'] ?? ''),
                    'reason'        => $reasonKey,
                    'reason_label'  => award_removal_reason_label($reasonKey),
                    'removed'       => true,
                ];
            }
            $st->close();
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

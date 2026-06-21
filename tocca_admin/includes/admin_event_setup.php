<?php
declare(strict_types=1);

/**
 * Setup checklist for the active event (Events page and related admin flows).
 */

if (!function_exists('admin_event_setup_steps')) {
    /**
     * @return array{
     *   event_id: ?int,
     *   event_label: ?string,
     *   complete: bool,
     *   done_count: int,
     *   total: int,
     *   steps: list<array{key:string,label:string,url:string,done:bool,count:int,hint:string}>
     * }
     */
    function admin_event_setup_steps(mysqli $conn): array
    {
        $steps = [
            [
                'key'   => 'activate',
                'label' => 'Activate an event',
                'url'   => 'events.php',
                'done'  => false,
                'count' => 0,
                'hint'  => 'Set one non-archived event as active.',
            ],
            [
                'key'   => 'categories',
                'label' => 'Add categories',
                'url'   => 'categories.php',
                'done'  => false,
                'count' => 0,
                'hint'  => 'Create award categories for this event.',
            ],
            [
                'key'   => 'awards',
                'label' => 'Add awards',
                'url'   => 'questions.php',
                'done'  => false,
                'count' => 0,
                'hint'  => 'Add at least one award under those categories.',
            ],
            [
                'key'   => 'establishment_types',
                'label' => 'Configure establishment types',
                'url'   => 'establishment_types.php',
                'done'  => false,
                'count' => 0,
                'hint'  => 'Link establishment types to this event\'s awards.',
            ],
            [
                'key'   => 'establishments',
                'label' => 'Add establishments',
                'url'   => 'choices.php',
                'done'  => false,
                'count' => 0,
                'hint'  => 'Register nominees / establishments for voting.',
            ],
        ];

        $eventId = null;
        $eventLabel = null;

        try {
            $res = $conn->query(
                "SELECT event_id, event_name, year
                 FROM tbl_events
                 WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
                 ORDER BY year DESC, event_id DESC
                 LIMIT 1"
            );
            if ($res && ($row = $res->fetch_assoc())) {
                $eventId = (int) $row['event_id'];
                $eventLabel = trim(($row['event_name'] ?? '') . ' ' . ($row['year'] ?? ''));
            }
        } catch (Throwable $e) {
            $eventId = null;
        }

        if ($eventId === null) {
            return [
                'event_id'     => null,
                'event_label'  => null,
                'complete'     => false,
                'done_count'   => 0,
                'total'        => count($steps),
                'steps'        => $steps,
            ];
        }

        $steps[0]['done'] = true;
        $steps[0]['count'] = 1;

        $countFor = static function (mysqli $c, string $sql, int $eid): int {
            $stmt = $c->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('i', $eid);
            $stmt->execute();
            $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt->close();
            return $cnt;
        };

        $catCount = $countFor(
            $conn,
            'SELECT COUNT(*) AS cnt FROM tbl_categories WHERE event_id = ?',
            $eventId
        );
        $steps[1]['count'] = $catCount;
        $steps[1]['done'] = $catCount > 0;

        $awardCount = $countFor(
            $conn,
            'SELECT COUNT(*) AS cnt
             FROM tbl_questions q
             INNER JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE c.event_id = ?',
            $eventId
        );
        $steps[2]['count'] = $awardCount;
        $steps[2]['done'] = $awardCount > 0;

        $typeCount = 0;
        try {
            $hasTypes = $conn->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'tbl_establishment_type_awards' LIMIT 1"
            );
            if ($hasTypes && $hasTypes->num_rows > 0) {
                $stmt = $conn->prepare(
                    'SELECT COUNT(DISTINCT x.type_id) AS cnt
                     FROM tbl_establishment_type_awards x
                     INNER JOIN tbl_questions q ON q.question_id = x.question_id
                     INNER JOIN tbl_categories c ON c.category_id = q.category_id AND c.event_id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('i', $eventId);
                    $stmt->execute();
                    $typeCount = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            $typeCount = 0;
        }
        $steps[3]['count'] = $typeCount;
        $steps[3]['done'] = $typeCount > 0;

        $choiceCount = $countFor(
            $conn,
            'SELECT COUNT(*) AS cnt FROM tbl_choices WHERE event_id = ?',
            $eventId
        );
        $steps[4]['count'] = $choiceCount;
        $steps[4]['done'] = $choiceCount > 0;

        $doneCount = 0;
        foreach ($steps as $s) {
            if ($s['done']) {
                $doneCount++;
            }
        }

        return [
            'event_id'    => $eventId,
            'event_label' => $eventLabel,
            'complete'    => $doneCount === count($steps),
            'done_count'  => $doneCount,
            'total'       => count($steps),
            'steps'       => $steps,
        ];
    }
}

if (!function_exists('render_admin_event_setup_checklist')) {
    function render_admin_event_setup_checklist(): string
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            return '';
        }

        $setup = admin_event_setup_steps($conn);
        $partial = dirname(__DIR__) . '/partials/admin_event_setup_checklist.php';
        if (!is_file($partial)) {
            return '';
        }

        ob_start();
        include $partial;
        return (string) ob_get_clean();
    }
}

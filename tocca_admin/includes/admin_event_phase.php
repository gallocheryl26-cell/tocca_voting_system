<?php
declare(strict_types=1);

if (!function_exists('admin_event_phase')) {
    /**
     * Determine phase metadata for an event row.
     *
     * @param array<string,mixed> $e
     * @return array{0:string,1:string,2:string,3:?DateTime,4:string}
     *   [phase_key, phase_label, bootstrap_color, next_deadline, deadline_label]
     */
    function admin_event_phase(array $e): array
    {
        $tz  = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $tz);

        $parse = static function ($v) use ($tz): ?DateTime {
            if (empty($v) || $v === '0000-00-00 00:00:00') {
                return null;
            }
            try {
                return new DateTime((string) $v, $tz);
            } catch (Throwable $ex) {
                return null;
            }
        };
        $ns = $parse($e['nomination_start'] ?? null);
        $ne = $parse($e['nomination_end'] ?? null);
        $vs = $parse($e['voting_start'] ?? null);
        $ve = $parse($e['voting_end'] ?? null);

        if ($ve && $now > $ve) {
            return ['voting_closed', 'Voting Closed', 'secondary', null, ''];
        }
        if ($vs && $ve && $now >= $vs && $now <= $ve) {
            return ['voting_open', 'Voting Open', 'success', $ve, 'Voting ends in'];
        }
        if ($ns && $ne && $now >= $ns && $now <= $ne) {
            return ['nominations_open', 'Registration Open', 'primary', $ne, 'Registration closes in'];
        }
        if ($ns && $now < $ns) {
            return ['unscheduled', 'Awaiting Start', 'warning', $ns, 'Registration starts in'];
        }
        if ($ne && $now > $ne && (!$vs || $now < $vs)) {
            return ['between', 'Between Phases', 'info', $vs, 'Voting starts in'];
        }
        return ['unscheduled', 'Unscheduled', 'secondary', null, ''];
    }
}

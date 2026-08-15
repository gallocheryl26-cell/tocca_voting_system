<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_event_phase.php';

if (!function_exists('events_fetch_all')) {
    /** @return list<array<string,mixed>> */
    function events_fetch_all(mysqli $conn): array
    {
        $sql = "SELECT event_id, event_name, year, description, is_active, is_archived, created_at,
                       nomination_start, nomination_end, voting_start, voting_end
                FROM tbl_events
                WHERE COALESCE(is_archived, 0) = 0
                ORDER BY is_active DESC, created_at DESC";
        $res = $conn->query($sql);
        if (!$res instanceof mysqli_result) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
        return $rows;
    }
}

if (!function_exists('events_fmt_datetime')) {
    function events_fmt_datetime(?string $sql): string
    {
        $sql = trim((string) $sql);
        if ($sql === '') {
            return '—';
        }
        try {
            $d = new DateTime(str_replace(' ', 'T', $sql), new DateTimeZone('Asia/Manila'));
            return $d->format('F j, Y') . ' at ' . $d->format('g:i A');
        } catch (Throwable $e) {
            return '—';
        }
    }
}

if (!function_exists('events_status_badge_html')) {
    function events_status_badge_html(array $ev): string
    {
        if ((int) ($ev['is_archived'] ?? 0) === 1) {
            return '<span class="badge badge-status rounded-pill text-bg-dark">Archived</span>';
        }
        if ((int) ($ev['is_active'] ?? 0) === 1) {
            [, $phaseLabel, $phaseColor] = admin_event_phase($ev);
            return '<div class="d-flex flex-column gap-1">'
                . '<span class="badge badge-status rounded-pill text-bg-primary">Active</span>'
                . '<span class="badge rounded-pill text-bg-' . h($phaseColor) . '">' . h($phaseLabel) . '</span>'
                . '</div>';
        }
        return '<span class="badge badge-status rounded-pill text-bg-secondary">Inactive</span>';
    }
}

if (!function_exists('events_actions_html')) {
    function events_actions_html(array $ev): string
    {
        if ((int) ($ev['is_archived'] ?? 0) === 1) {
            return '<span class="text-muted">No Action</span>';
        }

        $evId = (int) ($ev['event_id'] ?? 0);
        $name = rawurlencode((string) ($ev['event_name'] ?? ''));
        $desc = rawurlencode((string) ($ev['description'] ?? ''));
        $active = (int) ($ev['is_active'] ?? 0);
        $ns = rawurlencode((string) ($ev['nomination_start'] ?? ''));
        $ne = rawurlencode((string) ($ev['nomination_end'] ?? ''));
        $vs = rawurlencode((string) ($ev['voting_start'] ?? ''));
        $ve = rawurlencode((string) ($ev['voting_end'] ?? ''));

        $parts = [];
        if ($active === 0) {
            $parts[] = '<button type="button" class="btn btn-sm btn-success activate-event-btn" data-event-id="' . $evId . '">Activate</button>';
        }
        $parts[] = '<button type="button" class="btn btn-sm btn-info btn-qr registration-qr-btn" data-event-id="' . $evId . '" data-event-name="' . h($name) . '">Public links</button>';
        $parts[] = '<button type="button" class="btn btn-sm btn-edit edit-event-btn" data-event-id="' . $evId . '" data-event-name="' . h($name) . '" data-event-description="' . h($desc) . '" data-event-active="' . $active . '" data-nom-start="' . h($ns) . '" data-nom-end="' . h($ne) . '" data-vote-start="' . h($vs) . '" data-vote-end="' . h($ve) . '">Edit</button>';
        $parts[] = '<button type="button" class="btn btn-sm btn-danger archive-event-btn" data-event-id="' . $evId . '" data-event-active="' . $active . '">Archive</button>';

        return '<div class="admin-table-actions event-table-actions" role="group">' . implode('', $parts) . '</div>';
    }
}

if (!function_exists('events_render_table_rows')) {
    function events_render_table_rows(array $events): void
    {
        if ($events === []) {
            echo '<tr><td colspan="4" class="text-center text-muted">No events found.</td></tr>';
            return;
        }

        foreach ($events as $ev) {
            $nsF = events_fmt_datetime($ev['nomination_start'] ?? null);
            $neF = events_fmt_datetime($ev['nomination_end'] ?? null);
            $vsF = events_fmt_datetime($ev['voting_start'] ?? null);
            $veF = events_fmt_datetime($ev['voting_end'] ?? null);
            $name = h((string) ($ev['event_name'] ?? ''));
            $desc = h((string) ($ev['description'] ?? ''));
            ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= $name ?></div>
                <div class="event-schedule text-muted mt-1">
                  <div><strong>Registration:</strong> <?= h($nsF) ?> <span class="mx-1">–</span> <?= h($neF) ?></div>
                  <div><strong>Voting:</strong> <?= h($vsF) ?> <span class="mx-1">–</span> <?= h($veF) ?></div>
                </div>
              </td>
              <td><?= $desc ?></td>
              <td style="width:110px"><?= events_status_badge_html($ev) ?></td>
              <td class="actions"><?= events_actions_html($ev) ?></td>
            </tr>
            <?php
        }
    }
}

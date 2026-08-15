<?php
declare(strict_types=1);

/**
 * Public vanity-path slugs for shareable URLs:
 *   /vote
 *   /register
 *   /track
 *   /{business-slug}
 */

if (!function_exists('public_slug_reserved')) {
    /** @return list<string> */
    function public_slug_reserved(): array
    {
        return [
            'track', 'vote', 'register', 'b', 'admin', 'api', 'www', 'assets',
            'uploads', 'vendor', 'docs', 'tests', 'tools', 'mobile', 'nominee',
            'nomination', 'tocca_admin', 'e-vote-final-enhanced', 'index', 'public_router',
        ];
    }
}

if (!function_exists('public_slug_normalize')) {
    function public_slug_normalize(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if (strlen($slug) > 80) {
            $slug = rtrim(substr($slug, 0, 80), '-');
        }
        return $slug;
    }
}

if (!function_exists('public_slug_ensure_event_column')) {
    function public_slug_ensure_event_column(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $res = $conn->query("SHOW COLUMNS FROM tbl_events LIKE 'public_slug'");
        if ($res instanceof mysqli_result && $res->num_rows === 0) {
            $conn->query(
                "ALTER TABLE tbl_events
                 ADD COLUMN public_slug VARCHAR(100) NULL DEFAULT NULL AFTER event_name,
                 ADD UNIQUE KEY uq_events_public_slug (public_slug)"
            );
        }
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        $done = true;
    }
}

if (!function_exists('public_slug_ensure_choice_column')) {
    function public_slug_ensure_choice_column(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $res = $conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'public_slug'");
        if ($res instanceof mysqli_result && $res->num_rows === 0) {
            $conn->query(
                "ALTER TABLE tbl_choices
                 ADD COLUMN public_slug VARCHAR(100) NULL DEFAULT NULL AFTER choice_name,
                 ADD UNIQUE KEY uq_choices_public_slug (public_slug)"
            );
        }
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        $done = true;
    }
}

if (!function_exists('public_slug_allocate')) {
    /**
     * Pick a unique slug, appending -2, -3… when needed.
     *
     * @param callable(string):bool $isTaken
     */
    function public_slug_allocate(string $preferred, callable $isTaken): string
    {
        $base = public_slug_normalize($preferred);
        if ($base === '' || in_array($base, public_slug_reserved(), true)) {
            $base = 'item';
        }

        $candidate = $base;
        $n = 2;
        while ($isTaken($candidate) || in_array($candidate, public_slug_reserved(), true)) {
            $suffix = '-' . $n;
            $trimTo = max(1, 80 - strlen($suffix));
            $candidate = rtrim(substr($base, 0, $trimTo), '-') . $suffix;
            $n++;
            if ($n > 500) {
                $candidate = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $candidate;
    }
}

if (!function_exists('public_slug_for_event')) {
    function public_slug_for_event(mysqli $conn, int $eventId): string
    {
        if ($eventId <= 0) {
            return '';
        }
        public_slug_ensure_event_column($conn);

        $stmt = $conn->prepare('SELECT event_name, public_slug FROM tbl_events WHERE event_id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return '';
        }

        $existing = trim((string) ($row['public_slug'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $slug = public_slug_allocate((string) ($row['event_name'] ?? 'event'), static function (string $candidate) use ($conn, $eventId): bool {
            $st = $conn->prepare(
                'SELECT event_id FROM tbl_events WHERE public_slug = ? AND event_id <> ? LIMIT 1'
            );
            if (!$st) {
                return true;
            }
            $st->bind_param('si', $candidate, $eventId);
            $st->execute();
            $r = $st->get_result();
            $taken = $r && $r->fetch_assoc();
            $st->close();
            return (bool) $taken;
        });

        $upd = $conn->prepare('UPDATE tbl_events SET public_slug = ? WHERE event_id = ? AND (public_slug IS NULL OR public_slug = \'\')');
        if ($upd) {
            $upd->bind_param('si', $slug, $eventId);
            $upd->execute();
            $upd->close();
        }

        return $slug;
    }
}

if (!function_exists('public_slug_for_choice')) {
    function public_slug_for_choice(mysqli $conn, int $choiceId): string
    {
        if ($choiceId <= 0) {
            return '';
        }
        public_slug_ensure_choice_column($conn);

        $stmt = $conn->prepare('SELECT choice_name, public_slug FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $choiceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return '';
        }

        $existing = trim((string) ($row['public_slug'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $slug = public_slug_allocate((string) ($row['choice_name'] ?? 'business'), static function (string $candidate) use ($conn, $choiceId): bool {
            $st = $conn->prepare(
                'SELECT choice_id FROM tbl_choices WHERE public_slug = ? AND choice_id <> ? LIMIT 1'
            );
            if (!$st) {
                return true;
            }
            $st->bind_param('si', $candidate, $choiceId);
            $st->execute();
            $r = $st->get_result();
            $taken = $r && $r->fetch_assoc();
            $st->close();
            return (bool) $taken;
        });

        $upd = $conn->prepare('UPDATE tbl_choices SET public_slug = ? WHERE choice_id = ? AND (public_slug IS NULL OR public_slug = \'\')');
        if ($upd) {
            $upd->bind_param('si', $slug, $choiceId);
            $upd->execute();
            $upd->close();
        }

        return $slug;
    }
}

if (!function_exists('public_slug_lookup_event')) {
    /** @return array<string,mixed>|null */
    function public_slug_lookup_event(mysqli $conn, string $slug): ?array
    {
        $slug = public_slug_normalize($slug);
        if ($slug === '') {
            return null;
        }
        public_slug_ensure_event_column($conn);

        $stmt = $conn->prepare(
            'SELECT event_id, event_name, year, is_active, is_archived, nomination_start, nomination_end, voting_start, voting_end, public_slug
             FROM tbl_events WHERE public_slug = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('public_slug_lookup_choice')) {
    /** @return array<string,mixed>|null */
    function public_slug_lookup_choice(mysqli $conn, string $slug): ?array
    {
        $slug = public_slug_normalize($slug);
        if ($slug === '') {
            return null;
        }
        public_slug_ensure_choice_column($conn);

        $stmt = $conn->prepare(
            'SELECT choice_id, choice_name, email, status, event_id, public_slug
             FROM tbl_choices WHERE public_slug = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

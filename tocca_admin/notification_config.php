<?php
declare(strict_types=1);

if (!defined('NOTIF_CONFIG_DEFAULT_NOMINATION_START_HOURS')) {
    define('NOTIF_CONFIG_DEFAULT_NOMINATION_START_HOURS', [-24, 0, 24, 72, 168]);
}
if (!defined('NOTIF_CONFIG_DEFAULT_NOMINATION_END_HOURS')) {
    define('NOTIF_CONFIG_DEFAULT_NOMINATION_END_HOURS', [-24, 0, 24, 72, 168]);
}
if (!defined('NOTIF_CONFIG_DEFAULT_PENDING_DEADLINE_HOURS')) {
    define('NOTIF_CONFIG_DEFAULT_PENDING_DEADLINE_HOURS', [-24, 0, 24, 48, 72]);
}
if (!defined('NOTIF_CONFIG_DEFAULT_VOTING_START_HOURS')) {
    define('NOTIF_CONFIG_DEFAULT_VOTING_START_HOURS', [-24, 0, 24, 72, 168]);
}
if (!defined('NOTIF_CONFIG_DEFAULT_VOTING_END_HOURS')) {
    define('NOTIF_CONFIG_DEFAULT_VOTING_END_HOURS', [-24, 0, 24, 72, 168]);
}
if (!defined('NOTIF_CONFIG_DEFAULT_PENDING_MIN_TOTAL')) {
    define('NOTIF_CONFIG_DEFAULT_PENDING_MIN_TOTAL', 1);
}
if (!defined('NOTIF_CONFIG_DEFAULT_PENDING_WARNING_HOURS')) {
    define('NOTIF_CONFIG_DEFAULT_PENDING_WARNING_HOURS', 48);
}

if (!function_exists('notif_config_fetch_raw')) {
    function notif_config_fetch_raw(mysqli $conn, string $key, ?string $default = null): string
    {
        $stmt = $conn->prepare('SELECT config_value FROM tbl_config WHERE config_key = ? LIMIT 1');
        if (!$stmt) {
            return $default ?? '';
        }

        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->bind_result($value);

        $out = $default ?? '';
        if ($stmt->fetch()) {
            $out = (string)($value ?? '');
        }

        $stmt->close();
        return $out;
    }
}

if (!function_exists('notif_config_set_raw')) {
    function notif_config_set_raw(mysqli $conn, string $key, string $value): bool
    {
        $stmt = $conn->prepare(
            'INSERT INTO tbl_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $key, $value);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('notif_config_parse_thresholds_raw')) {
    function notif_config_parse_thresholds_raw(string $raw, array $defaults = [], int $minHours = -720, int $maxHours = 720): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $defaults;
        }

        $tokens = preg_split('/[\s,]+/', $raw) ?: [];
        $hoursList = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (!preg_match('/^([+-]?[0-9]*\.?[0-9]+)\s*([hdHD]?)$/', $token, $matches)) {
                continue;
            }

            $value = (float)($matches[1] ?? 0);
            $unit  = strtolower($matches[2] ?? '');

            if ($unit === 'd') {
                $hours = (int)round($value * 24);
            } else {
                $hours = (int)round($value);
            }

            if ($hours < $minHours || $hours > $maxHours) {
                continue;
            }

            if (!in_array($hours, $hoursList, true)) {
                $hoursList[] = $hours;
            }
        }

        if (empty($hoursList)) {
            return $defaults;
        }

        sort($hoursList, SORT_NUMERIC);
        return $hoursList;
    }
}

if (!function_exists('notif_config_get_thresholds')) {
    function notif_config_get_thresholds(mysqli $conn, string $key, array $defaults, int $minHours = -720, int $maxHours = 720): array
    {
        $raw = notif_config_fetch_raw($conn, $key, '');
        $parsed = notif_config_parse_thresholds_raw($raw, $defaults, $minHours, $maxHours);
        return $parsed ?: $defaults;
    }
}

if (!function_exists('notif_config_format_hours_list')) {
    function notif_config_format_hours_list(array $hours, bool $withUnits = true): string
    {
        if (empty($hours)) {
            return '';
        }

        $parts = [];
        foreach ($hours as $hour) {
            $hour = (int)$hour;
            if (!$withUnits) {
                $parts[] = (string)$hour;
                continue;
            }

            if ($hour === 0) {
                $parts[] = '0h';
                continue;
            }

            if ($hour % 24 === 0) {
                $days = (int)($hour / 24);
                $parts[] = $days . 'd';
            } else {
                $parts[] = $hour . 'h';
            }
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('notif_config_normalize_hours_list')) {
    function notif_config_normalize_hours_list(array $hours): string
    {
        if (empty($hours)) {
            return '';
        }

        $hours = array_map(static fn($h) => (int)$h, $hours);
        $hours = array_values(array_unique($hours));
        sort($hours, SORT_NUMERIC);

        return implode(',', array_map(static fn($h) => (string)$h, $hours));
    }
}

if (!function_exists('notif_config_parse_user_input')) {
    function notif_config_parse_user_input(string $raw, array $defaults, int $minHours = -720, int $maxHours = 720): array
    {
        $parsed = notif_config_parse_thresholds_raw($raw, $defaults, $minHours, $maxHours);
        return $parsed ?: $defaults;
    }
}

if (!function_exists('notif_config_set_thresholds')) {
    function notif_config_set_thresholds(mysqli $conn, string $key, array $hours): bool
    {
        $normalized = notif_config_normalize_hours_list($hours);
        return notif_config_set_raw($conn, $key, $normalized);
    }
}

if (!function_exists('notif_config_get_int')) {
    function notif_config_get_int(mysqli $conn, string $key, int $default, int $min, int $max): int
    {
        $raw = notif_config_fetch_raw($conn, $key, (string)$default);
        $value = (int)filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => [
                'default'   => $default,
                'min_range' => $min,
                'max_range' => $max,
            ],
        ]);

        if ($value < $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return $value;
    }
}

if (!function_exists('notif_config_set_int')) {
    function notif_config_set_int(mysqli $conn, string $key, int $value, int $min, int $max): bool
    {
        if ($value < $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return notif_config_set_raw($conn, $key, (string)$value);
    }
}
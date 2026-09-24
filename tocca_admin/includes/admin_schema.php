<?php
declare(strict_types=1);

/**
 * Cached schema introspection helpers for admin pages and APIs.
 * Uses information_schema (prepared statements work reliably on MariaDB/MySQL).
 */

if (!function_exists('admin_schema_column_exists')) {
    function admin_schema_column_exists(mysqli $conn, string $table, string $column, bool $refresh = false): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!$refresh && array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1'
        );
        if (!$stmt) {
            $cache[$key] = false;
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $stmt->store_result();
        $cache[$key] = $stmt->num_rows > 0;
        $stmt->close();
        return $cache[$key];
    }
}

if (!function_exists('admin_table_exists')) {
    function admin_table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             LIMIT 1'
        );
        if (!$stmt) {
            $cache[$table] = false;
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->store_result();
        $cache[$table] = $stmt->num_rows > 0;
        $stmt->close();
        return $cache[$table];
    }
}

if (!function_exists('admin_unarchived_events_where')) {
    function admin_unarchived_events_where(mysqli $conn, string $alias = 'e'): string
    {
        static $cached = [];
        if (isset($cached[$alias])) {
            return $cached[$alias];
        }

        if (admin_schema_column_exists($conn, 'tbl_events', 'is_archived')) {
            $cached[$alias] = "$alias.is_archived=0";
            return $cached[$alias];
        }
        if (admin_schema_column_exists($conn, 'tbl_events', 'archived_at')) {
            $cached[$alias] = "$alias.archived_at IS NULL";
            return $cached[$alias];
        }

        $cached[$alias] = "$alias.is_active=1";
        return $cached[$alias];
    }
}

if (!function_exists('admin_active_category_sql')) {
    /** Active, non-archived categories (status = 1). */
    function admin_active_category_sql(mysqli $conn, string $alias = 'c'): string
    {
        $sql = "COALESCE({$alias}.status, 1) = 1";
        if (admin_schema_column_exists($conn, 'tbl_categories', 'is_archived')) {
            $sql .= " AND COALESCE({$alias}.is_archived, 0) = 0";
        }
        return $sql;
    }
}

if (!function_exists('admin_active_question_sql')) {
    /** Active award titles when tbl_questions.status exists. */
    function admin_active_question_sql(mysqli $conn, string $alias = 'q'): string
    {
        if (admin_schema_column_exists($conn, 'tbl_questions', 'status')) {
            return "COALESCE({$alias}.status, 1) = 1";
        }
        return '1=1';
    }
}

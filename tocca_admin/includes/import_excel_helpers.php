<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Reserved workbook sheet names (case-insensitive). */
const IMPORT_RESERVED_SHEETS = [
    'categories',
    'awards',
    'establishment types',
    'establishment_types',
    'establishments',
    'business categories',
    'business category',
    'businesses',
    'instructions',
];

function import_normalize_sheet_key(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return strtolower($name);
}

function import_is_reserved_sheet(string $sheetName): bool
{
    return in_array(import_normalize_sheet_key($sheetName), IMPORT_RESERVED_SHEETS, true);
}

function import_uses_unified_workbook(Spreadsheet $spreadsheet): bool
{
    foreach ($spreadsheet->getSheetNames() as $name) {
        $key = import_normalize_sheet_key($name);
        if ($key === 'categories' || $key === 'awards') {
            return true;
        }
    }
    return false;
}

/**
 * @param list<string> $aliases
 */
function import_find_sheet(Spreadsheet $spreadsheet, array $aliases): ?Worksheet
{
    $want = [];
    foreach ($aliases as $alias) {
        $want[import_normalize_sheet_key($alias)] = true;
    }
    foreach ($spreadsheet->getSheetNames() as $name) {
        if (isset($want[import_normalize_sheet_key($name)])) {
            return $spreadsheet->getSheetByName($name);
        }
    }
    return null;
}

function import_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    if (!$stmt) {
        return $cache[$table] = false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $cache[$table] = $ok;
}

function import_column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    if (!$stmt) {
        return $cache[$key] = false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $cache[$key] = $ok;
}

/**
 * Locate the header row when row 1 is a human-readable hint row.
 *
 * @param array<int, array<int, mixed>> $rows
 * @param list<string> $expectedHeaders
 */
function import_find_header_row_index(array $rows, array $expectedHeaders): int
{
    $bestIdx = 0;
    $bestScore = 0;
    $scan = min(6, count($rows));
    for ($i = 0; $i < $scan; $i++) {
        $found = [];
        foreach ($rows[$i] as $cell) {
            $key = strtolower(trim((string) $cell));
            if ($key !== '') {
                $found[$key] = true;
            }
        }
        $score = 0;
        foreach ($expectedHeaders as $header) {
            if (isset($found[strtolower($header)])) {
                $score++;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestIdx = $i;
        }
    }
    return $bestIdx;
}

function import_row_is_example(array $record): bool
{
    foreach ($record as $key => $value) {
        if ($key === '_line') {
            continue;
        }
        if (stripos($value, '(example)') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<int, array<int, mixed>> $rows
 * @param list<string> $expectedHeaders
 * @return array{headers: array<string, int>, data: array<int, array<string, string>>, header_row: int}
 */
function import_parse_sheet_by_header(array $rows, array $expectedHeaders = []): array
{
    if ($rows === []) {
        return ['headers' => [], 'data' => [], 'header_row' => 0, 'skipped_examples' => 0];
    }

    $headerIdx = $expectedHeaders !== []
        ? import_find_header_row_index($rows, $expectedHeaders)
        : 0;

    $headerRow = $rows[$headerIdx];
    $headers = [];
    foreach ($headerRow as $idx => $cell) {
        $key = strtolower(trim((string) $cell));
        if ($key !== '') {
            $headers[$key] = (int) $idx;
        }
    }

    $data = [];
    $skippedExamples = 0;
    foreach ($rows as $rowNum => $row) {
        if ($rowNum <= $headerIdx) {
            continue;
        }
        $line = $rowNum + 1;
        $record = [];
        $hasValue = false;
        foreach ($headers as $name => $colIdx) {
            $val = trim((string) ($row[$colIdx] ?? ''));
            $record[$name] = $val;
            if ($val !== '') {
                $hasValue = true;
            }
        }
        if (!$hasValue) {
            continue;
        }
        if (import_row_is_example($record)) {
            $skippedExamples++;
            continue;
        }
        $record['_line'] = (string) $line;
        $data[] = $record;
    }

    return ['headers' => $headers, 'data' => $data, 'header_row' => $headerIdx + 1, 'skipped_examples' => $skippedExamples];
}

/**
 * @return list<string>
 */
function import_split_keys(string $raw): array
{
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\s*,\s*/', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $key = strtoupper(trim($part));
        if ($key !== '') {
            $out[] = $key;
        }
    }
    return array_values(array_unique($out));
}

function import_require_columns(array $headers, array $required, string $sheetLabel): array
{
    $errors = [];
    foreach ($required as $col) {
        if (!isset($headers[$col])) {
            $errors[] = "Sheet \"{$sheetLabel}\" is missing required column \"{$col}\".";
        }
    }
    return $errors;
}

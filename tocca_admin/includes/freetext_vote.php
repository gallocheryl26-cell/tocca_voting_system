<?php
declare(strict_types=1);

/**
 * Normalize typed song/singer answers so casing and dash variants count as one vote.
 * "multo - cup of joe" and "Multo — Cup of Joe" share the same key.
 */

function freetext_vote_parse(string $raw): array
{
    $s = trim($raw);
    if ($s === '') {
        return ['title' => '', 'singer' => ''];
    }

    $sep = ' — ';
    $pos = function_exists('mb_strpos') ? mb_strpos($s, $sep, 0, 'UTF-8') : strpos($s, $sep);
    if ($pos !== false) {
        $sepLen = function_exists('mb_strlen') ? mb_strlen($sep, 'UTF-8') : strlen($sep);
        $title = function_exists('mb_substr') ? mb_substr($s, 0, $pos, 'UTF-8') : substr($s, 0, (int) $pos);
        $singer = function_exists('mb_substr')
            ? mb_substr($s, $pos + $sepLen, null, 'UTF-8')
            : substr($s, (int) $pos + strlen($sep));
        return ['title' => trim((string) $title), 'singer' => trim((string) $singer)];
    }

    if (preg_match('/^(.*?)\s+[-–—]\s+(.+)$/u', $s, $m)) {
        return ['title' => trim((string) $m[1]), 'singer' => trim((string) $m[2])];
    }
    if (preg_match('/^(.*?)\s+by\s+(.+)$/iu', $s, $m)) {
        return ['title' => trim((string) $m[1]), 'singer' => trim((string) $m[2])];
    }

    return ['title' => $s, 'singer' => ''];
}

function freetext_vote_part_key(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $s);
    $s = preg_replace('/[-–—\/\\\\_,.]+/u', ' ', $s) ?? $s;
    $s = preg_replace("/[^\p{L}\p{N}'\s]+/u", '', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

function freetext_vote_key(string $raw): string
{
    $parts = freetext_vote_parse($raw);
    $title = freetext_vote_part_key($parts['title']);
    $singer = freetext_vote_part_key($parts['singer']);
    if ($title === '' && $singer === '') {
        return '';
    }
    return $title . "\n" . $singer;
}

function freetext_vote_title_case(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_convert_case')) {
        return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($s));
}

function freetext_vote_display(string $raw): string
{
    $parts = freetext_vote_parse($raw);
    $title = freetext_vote_title_case($parts['title']);
    $singer = freetext_vote_title_case($parts['singer']);
    if ($title === '' && $singer === '') {
        return '';
    }
    if ($singer === '') {
        return $title;
    }
    if ($title === '') {
        return $singer;
    }
    return $title . ' — ' . $singer;
}

function freetext_vote_canonicalize(string $raw): string
{
    return freetext_vote_display($raw);
}

function freetext_vote_canonicalize_product(string $raw): string
{
    return freetext_vote_title_case($raw);
}

/**
 * Merge vote rows that share a normalized key. Sums vote_count and uses a title-cased display name.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function freetext_vote_merge_rows(array $rows): array
{
    $merged = [];
    foreach ($rows as $row) {
        $name = (string) ($row['choice_name'] ?? '');
        $key = freetext_vote_key($name);
        $count = (int) ($row['vote_count'] ?? 0);
        if ($key === '') {
            $key = "\0empty";
            $display = '(No Answer)';
        } else {
            $display = freetext_vote_display($name);
        }
        if (!isset($merged[$key])) {
            $row['choice_name'] = $display;
            $row['vote_count'] = $count;
            $merged[$key] = $row;
            continue;
        }
        $merged[$key]['vote_count'] = (int) $merged[$key]['vote_count'] + $count;
    }
    return array_values($merged);
}

/**
 * Merge typed answers that are a single name (e.g. Best Date Place), without splitting on dashes.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function freetext_vote_merge_rows_single(array $rows): array
{
    $merged = [];
    foreach ($rows as $row) {
        $name = (string) ($row['choice_name'] ?? '');
        $key = freetext_vote_part_key($name);
        $count = (int) ($row['vote_count'] ?? 0);
        if ($key === '') {
            $key = "\0empty";
            $display = '(No Answer)';
        } else {
            $display = freetext_vote_title_case($name);
        }
        if (!isset($merged[$key])) {
            $row['choice_name'] = $display;
            $row['vote_count'] = $count;
            $merged[$key] = $row;
            continue;
        }
        $merged[$key]['vote_count'] = (int) $merged[$key]['vote_count'] + $count;
    }
    return array_values($merged);
}

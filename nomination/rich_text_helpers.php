<?php
declare(strict_types=1);

/**
 * Safe Markdown / admin HTML helpers for public registration pages.
 * Shared with tocca_admin/nomination_fields.php preview where possible.
 */

if (!function_exists('tocca_fix_mojibake')) {
    /**
     * Repair UTF-8 punctuation that was stored as CP437/latin1 mojibake
     * (e.g. ΓÇÖ instead of ’, ΓÇô instead of –, ├⌐ instead of é).
     */
    function tocca_fix_mojibake(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        static $map = null;
        if ($map === null) {
            $map = [
                'ΓÇÖ' => "\u{2019}",
                'ΓÇô' => "\u{2013}",
                'ΓÇö' => "\u{2014}",
                'ΓÇ£' => "\u{201C}",
                'ΓÇ¥' => "\u{201D}",
                'ΓÇª' => "\u{2026}",
                'â€™' => "\u{2019}",
                'â€˜' => "\u{2018}",
                'â€“' => "\u{2013}",
                'â€”' => "\u{2014}",
                'â€œ' => "\u{201C}",
                'â€' => "\u{201D}",
                'â€¦' => "\u{2026}",
                '├⌐' => "\u{00E9}",
                '├®' => "\u{00E9}",
                '├á' => "\u{00E1}",
                '├│' => "\u{00F3}",
                '├▒' => "\u{00F1}",
            ];
        }
        $out = str_replace(array_keys($map), array_values($map), $text);
        if (function_exists('mb_convert_encoding') && preg_match('/Ã.|Â./u', $out)) {
            $try = @mb_convert_encoding($out, 'UTF-8', 'Windows-1252');
            if (is_string($try) && $try !== '' && !preg_match('/Ã.|Â./u', $try)) {
                $out = $try;
            }
        }

        return $out;
    }
}

if (!function_exists('md_to_html_basic')) {
    /** Block Markdown → HTML (paragraphs, bold, links, autolink bare URLs). */
    function md_to_html_basic(string $src): string
    {
        $x = htmlspecialchars(tocca_fix_mojibake($src), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $x = preg_replace_callback(
            '/\[(.*?)\]\((https?:\/\/[^\s)]+)\)/i',
            static function (array $m): string {
                $text = $m[1];
                $url  = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
            },
            $x
        );
        $x = preg_replace_callback(
            '/(^|[\s(])((?:https?:\/\/|www\.)[^\s<)]+)(?=$|[\s<)])/i',
            static function (array $m): string {
                $lead    = $m[1];
                $urlText = $m[2];
                $href    = (stripos($urlText, 'www.') === 0) ? 'https://' . $urlText : $urlText;
                $hrefEsc = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $lead . '<a href="' . $hrefEsc . '" target="_blank" rel="noopener noreferrer">' . $urlText . '</a>';
            },
            $x
        );
        $x = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $x);
        $x = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $x);
        $paras = preg_split("/(\r?\n){2,}/", $x) ?: [];
        $paras = array_map(static fn(string $p): string => '<p>' . nl2br($p, false) . '</p>', $paras);
        return implode("\n", $paras);
    }
}

if (!function_exists('md_inline_to_html')) {
    /** Inline Markdown → HTML (no paragraph wrappers). */
    function md_inline_to_html(string $src): string
    {
        $x = htmlspecialchars(tocca_fix_mojibake($src), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $x = preg_replace_callback(
            '/\[(.*?)\]\((https?:\/\/[^\s)]+)\)/i',
            static function (array $m): string {
                $text = $m[1];
                $url  = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
            },
            $x
        );
        $x = preg_replace_callback(
            '/(^|[\s(])((?:https?:\/\/|www\.)[^\s<)]+)(?=$|[\s<)])/i',
            static function (array $m): string {
                $lead    = $m[1];
                $urlText = $m[2];
                $href    = (stripos($urlText, 'www.') === 0) ? 'https://' . $urlText : $urlText;
                $hrefEsc = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $lead . '<a href="' . $hrefEsc . '" target="_blank" rel="noopener noreferrer">' . $urlText . '</a>';
            },
            $x
        );
        $x = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $x);
        $x = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $x);
        return $x;
    }
}

if (!function_exists('md_inline_basic')) {
    /** Alias used by admin registration fields preview. */
    function md_inline_basic(string $src): string
    {
        return md_inline_to_html($src);
    }
}

if (!function_exists('admin_html_sanitize')) {
    function admin_html_sanitize(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a>';
        $clean   = strip_tags($html, $allowed);
        $clean   = preg_replace_callback(
            '#<a\s+[^>]*href=("|\')(.*?)\1[^>]*>#i',
            static function (array $m): string {
                $tag = $m[0];
                if (!preg_match('/\btarget=/', $tag)) {
                    $tag = rtrim($tag, '>') . ' target="_blank">';
                }
                if (!preg_match('/\brel=/', $tag)) {
                    $tag = rtrim($tag, '>') . ' rel="noopener noreferrer">';
                }
                return $tag;
            },
            $clean
        );
        return $clean ?? '';
    }
}

if (!function_exists('easy_rich_to_html')) {
    /** Admin body: HTML if tagged, otherwise Markdown blocks. */
    function easy_rich_to_html(?string $src): string
    {
        $src = tocca_fix_mojibake((string)($src ?? ''));
        if ($src === '') {
            return '';
        }
        if (preg_match('/<\w+[^>]*>/', $src)) {
            return admin_html_sanitize($src);
        }
        return md_to_html_basic($src);
    }
}

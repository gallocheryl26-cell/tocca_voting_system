<?php
declare(strict_types=1);

/**
 * Email format check + common typo suggestions (registration form).
 */

if (!function_exists('email_popular_domains')) {
    function email_popular_domains(): array
    {
        return [
            'gmail.com',
            'yahoo.com',
            'yahoo.com.ph',
            'hotmail.com',
            'outlook.com',
            'icloud.com',
            'live.com',
            'proton.me',
            'protonmail.com',
            'mail.com',
            'aol.com',
            'ymail.com',
            'msn.com',
        ];
    }
}

if (!function_exists('email_domain_typo_map')) {
    /** @return array<string, string> */
    function email_domain_typo_map(): array
    {
        return [
            'gmial.com'      => 'gmail.com',
            'gmai.com'       => 'gmail.com',
            'gmil.com'       => 'gmail.com',
            'gamil.com'      => 'gmail.com',
            'gnail.com'      => 'gmail.com',
            'gmail.co'       => 'gmail.com',
            'gmail.con'      => 'gmail.com',
            'gmail.om'       => 'gmail.com',
            'gmail.comm'     => 'gmail.com',
            'gmail.coml'     => 'gmail.com',
            'gmail.cmo'      => 'gmail.com',
            'gmailcom'       => 'gmail.com',
            'hotmial.com'    => 'hotmail.com',
            'hotmal.com'     => 'hotmail.com',
            'hotmail.co'     => 'hotmail.com',
            'hotmali.com'    => 'hotmail.com',
            'outlok.com'     => 'outlook.com',
            'outlook.co'     => 'outlook.com',
            'outllok.com'    => 'outlook.com',
            'yaho.com'       => 'yahoo.com',
            'yahooo.com'     => 'yahoo.com',
            'yahoo.co'       => 'yahoo.com',
            'yhoo.com'       => 'yahoo.com',
            'yahho.com'      => 'yahoo.com',
            'icloud.co'      => 'icloud.com',
            'iclod.com'      => 'icloud.com',
            'protonmail.co'  => 'protonmail.com',
        ];
    }
}

if (!function_exists('email_parse_address')) {
    /** @return array{local:string,domain:string,full:string}|null */
    function email_parse_address(string $raw): ?array
    {
        $s = trim(str_replace([' ', "\t"], '', $raw));
        if ($s === '') {
            return null;
        }
        if (stripos($s, 'mailto:') === 0) {
            $s = substr($s, 7);
        }
        if (!str_contains($s, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $s, 2);
        $local  = trim($local);
        $domain = strtolower(trim($domain));
        if ($local === '' || $domain === '') {
            return null;
        }
        return [
            'local'  => $local,
            'domain' => $domain,
            'full'   => $local . '@' . $domain,
        ];
    }
}

if (!function_exists('email_clean_domain')) {
    function email_clean_domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (preg_match('/^(.+\.(?:com|net|org|ph|edu|gov|co\.uk|com\.ph))(\d+)$/', $domain, $m)) {
            return $m[1];
        }
        if (preg_match('/^(.+\.(?:com|net|org|ph))[^a-z0-9.]+$/', $domain, $m)) {
            return $m[1];
        }
        return $domain;
    }
}

if (!function_exists('email_suggest_domain')) {
    function email_suggest_domain(string $domain): ?string
    {
        $domain = email_clean_domain(strtolower(trim($domain)));
        $map    = email_domain_typo_map();
        if (isset($map[$domain])) {
            return $map[$domain];
        }

        $popular = email_popular_domains();
        if (in_array($domain, $popular, true)) {
            return null;
        }

        $best     = null;
        $bestDist = 3;
        foreach ($popular as $candidate) {
            $dist = levenshtein($domain, $candidate);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $candidate;
            }
        }
        if ($best !== null && $bestDist <= 2 && $best !== $domain) {
            return $best;
        }

        return null;
    }
}

if (!function_exists('email_is_valid_format')) {
    function email_is_valid_format(string $email): bool
    {
        if ($email === '' || strlen($email) > 254) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $parsed = email_parse_address($email);
        if ($parsed === null) {
            return false;
        }
        if (str_contains($parsed['local'], '..') || str_contains($parsed['domain'], '..')) {
            return false;
        }
        if (!str_contains($parsed['domain'], '.')) {
            return false;
        }
        return true;
    }
}

if (!function_exists('email_check')) {
    /**
     * @return array{
     *   valid: bool,
     *   normalized: string,
     *   suggestion: ?array{email:string,reason:string},
     *   error: ?string
     * }
     */
    function email_check(string $raw): array
    {
        $parsed = email_parse_address($raw);
        if ($parsed === null) {
            return [
                'valid'      => false,
                'normalized' => trim($raw),
                'suggestion' => null,
                'error'      => 'Enter a valid email address (example: name@example.com).',
            ];
        }

        $cleanDomain = email_clean_domain($parsed['domain']);
        $normalized  = $parsed['local'] . '@' . $cleanDomain;

        if (!email_is_valid_format($normalized)) {
            return [
                'valid'      => false,
                'normalized' => $normalized,
                'suggestion' => null,
                'error'      => 'Enter a valid email address (example: name@example.com).',
            ];
        }

        $suggestedDomain = email_suggest_domain($parsed['domain']);
        $suggestion      = null;
        if ($cleanDomain !== $parsed['domain']) {
            $suggestion = [
                'email'  => $parsed['local'] . '@' . $cleanDomain,
                'reason' => 'Extra characters were detected in the domain.',
            ];
        } elseif ($suggestedDomain !== null && $suggestedDomain !== $cleanDomain) {
            $suggestion = [
                'email'  => $parsed['local'] . '@' . $suggestedDomain,
                'reason' => 'The domain looks mistyped.',
            ];
        }

        return [
            'valid'      => true,
            'normalized' => $normalized,
            'suggestion' => $suggestion,
            'error'      => null,
        ];
    }
}

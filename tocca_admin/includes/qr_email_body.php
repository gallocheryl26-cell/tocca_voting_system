<?php
declare(strict_types=1);

require_once __DIR__ . '/branded_email.php';

if (!function_exists('qr_email_default_plain_message')) {
    function qr_email_default_plain_message(): string
    {
        return "Thank you for participating in the Tatak Ormoc Consumers' Choice Awards.\n\n"
            . "Your QR poster is attached. Use the links below when you promote voting.\n\n"
            . "Best regards,\n"
            . 'TOCCA Team';
    }
}

if (!function_exists('qr_email_apply_placeholders')) {
    function qr_email_apply_placeholders(string $message, string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'Business';
        return str_replace(['[NAME]', '{{NAME}}', '{{name}}'], $name, $message);
    }
}

if (!function_exists('qr_email_plain_to_inner_html')) {
    function qr_email_plain_to_inner_html(string $plainMessage, string $name): string
    {
        $bodyText = qr_email_apply_placeholders($plainMessage, $name);
        $bodyText = preg_replace('/^\s*(hi|hello)\s+[^,\n]*,\s*/i', '', $bodyText) ?? $bodyText;
        $bodyText = trim($bodyText);
        $parts = preg_split("/\n\s*\n/", $bodyText) ?: [$bodyText];
        $html = '';
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $html .= '<p style="margin:0 0 14px;">' . nl2br(htmlspecialchars($part, ENT_QUOTES, 'UTF-8')) . "</p>\n";
        }
        return $html;
    }
}

if (!function_exists('qr_email_labeled_url_html')) {
    function qr_email_labeled_url_html(string $title, string $hint, string $url, bool $first = false): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $hintSafe = htmlspecialchars($hint, ENT_QUOTES, 'UTF-8');
        $top = $first ? '18px' : '16px';
        return '<p style="margin:' . $top . ' 0 4px;font-weight:700;">' . $titleSafe . "</p>\n"
            . '<p style="margin:0 0 6px;color:#4b5563;font-size:14px;line-height:1.5;">' . $hintSafe . "</p>\n"
            . '<p style="margin:0 0 4px;word-break:break-all;"><a href="' . $safe . '" style="color:#2563eb;">' . $safe . "</a></p>\n";
    }
}

if (!function_exists('qr_email_voting_page_html')) {
    function qr_email_voting_page_html(string $portalUrl, string $businessUrl = ''): string
    {
        if ($businessUrl !== '' && function_exists('qr_normalize_business_vote_url')) {
            $businessUrl = qr_normalize_business_vote_url($businessUrl);
        }
        $html = qr_email_labeled_url_html(
            'All awards',
            'Share this if you want customers to browse every category and pick businesses themselves.',
            $portalUrl,
            true
        );
        if ($businessUrl !== '' && rtrim($businessUrl, '/') !== rtrim($portalUrl, '/')) {
            $html .= qr_email_labeled_url_html(
                'Your business (best to promote)',
                'Share this on Facebook, Messenger, or posters so customers go straight to voting for your business.',
                $businessUrl,
                false
            );
        }
        return $html;
    }
}

if (!function_exists('qr_email_build_html')) {
    function qr_email_build_html(
        string $name,
        string $plainMessage,
        string $voteUrl = '',
        string $subject = 'Your QR Code for Tatak Ormoc Voting',
        string $businessVoteUrl = ''
    ): string {
        if ($voteUrl === '' && function_exists('qr_vote_portal_url')) {
            global $conn;
            if ($conn instanceof mysqli) {
                $voteUrl = qr_vote_portal_url($conn);
            }
        }
        $inner = qr_email_plain_to_inner_html($plainMessage, $name)
            . qr_email_voting_page_html($voteUrl, $businessVoteUrl);
        return tocca_branded_status_email(
            $subject !== '' ? $subject : 'Your QR Code for Tatak Ormoc Voting',
            $name,
            'Your Voting QR Code',
            $inner,
            '',
            '',
            false
        );
    }
}

if (!function_exists('qr_email_append_attachment_meta')) {
    /** Hidden marker so QR Emails preview uses the same PNG that was attached. */
    function qr_email_append_attachment_meta(string $html, int $choiceId, string $basename): string
    {
        if ($choiceId <= 0 || $basename === '') {
            return $html;
        }
        $file = htmlspecialchars(basename($basename), ENT_QUOTES, 'UTF-8');
        return $html . '<!-- TOCCA_QR_META choice_id="' . $choiceId . '" file="' . $file . '" -->';
    }
}

if (!function_exists('qr_email_parse_attachment_meta')) {
    /** @return array{choice_id:int,file:string}|null */
    function qr_email_parse_attachment_meta(string $html): ?array
    {
        if (!preg_match('/<!--\s*TOCCA_QR_META\s+choice_id="(\d+)"\s+file="([^"]+)"\s*-->/i', $html, $m)) {
            return null;
        }
        return ['choice_id' => (int)$m[1], 'file' => basename($m[2])];
    }
}

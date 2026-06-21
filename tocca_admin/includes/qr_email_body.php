<?php
declare(strict_types=1);

if (!function_exists('qr_email_default_plain_message')) {
    function qr_email_default_plain_message(): string
    {
        return "Hi [NAME],\n\n"
            . "Thank you for participating in the Tatak Ormoc Consumers' Choice Awards.\n\n"
            . "Your QR poster is attached. Share the direct voting link in this email with customers "
            . "(social media, Messenger, etc.).\n\n"
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

if (!function_exists('qr_email_build_html')) {
    function qr_email_build_html(
        string $name,
        string $plainMessage,
        string $voteUrl = ''
    ): string {
        $bodyText = qr_email_apply_placeholders($plainMessage, $name);

        $voteBlock = $voteUrl !== ''
            ? '<p style="margin:16px 0;"><a href="' . htmlspecialchars($voteUrl, ENT_QUOTES, 'UTF-8')
            . '" style="display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;">Vote for us</a></p>'
            . '<p style="margin:0 0 16px;font-size:13px;color:#475569;">Direct voting link: <a href="'
            . htmlspecialchars($voteUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($voteUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
            : '';

        return '<div style="max-width:600px;font:14px/1.6 Arial,sans-serif;color:#222;word-wrap:break-word;">'
            . '<div style="margin:0 0 16px;">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>'
            . $voteBlock
            . '</div>';
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

<?php
declare(strict_types=1);

/**
 * Shared branded email chrome (header, greeting, heading, optional CTA + QR, footer).
 * Used by registration status, QR, and recover-reference emails.
 * $showRegistrationAssist adds the Track My Registration footer line — only for
 * registration emails, never for QR / voting / TWG notices.
 */
if (!function_exists('tocca_branded_status_email')) {
    function tocca_branded_status_email(
        string $subject,
        string $greetingName,
        string $heading,
        string $bodyHtml,
        string $ctaLabel = '',
        string $ctaUrl = '',
        bool $includeQr = false,
        bool $showRegistrationAssist = true
    ): string {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeHeading = htmlspecialchars($heading !== '' ? $heading : $subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = trim($greetingName);
        $greeting = $name !== ''
            ? 'Hello ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
            : 'Hello,';
        $year = date('Y');
        $assistHtml = 'Replies to this mailbox are not monitored.';
        if ($showRegistrationAssist) {
            $trackUrl = '';
            if (function_exists('qr_tracking_url')) {
                global $conn;
                if (isset($conn) && $conn instanceof mysqli) {
                    try {
                        $trackUrl = qr_tracking_url($conn);
                    } catch (Throwable $e) {
                        $trackUrl = '';
                    }
                }
            }
            $assistHtml = 'Replies to this mailbox are not monitored. To check a registration, use Track My Registration on the Tatak Ormoc website.';
            if ($trackUrl !== '') {
                $safeTrack = htmlspecialchars($trackUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $assistHtml = 'Replies to this mailbox are not monitored. To check a registration, open <a href="' . $safeTrack . '" style="color:#2563eb;">Track My Registration</a>.';
            }
        }
        $inner = preg_replace(
            '/\s*color\s*:\s*(#fff(?:fff)?|#f[5-9a-f]{5}|#e5e7eb|#eee|#f3f4f6|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))\s*;?/i',
            '',
            $bodyHtml
        ) ?? $bodyHtml;

        $ctaBlock = '';
        if ($ctaUrl !== '' && $ctaLabel !== '') {
            $safeUrl = htmlspecialchars($ctaUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeCta = htmlspecialchars($ctaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $ctaBlock = '
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 12px;">
            <tr>
              <td bgcolor="#2563eb" style="border-radius:6px;">
                <a href="' . $safeUrl . '" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">' . $safeCta . '</a>
              </td>
            </tr>
          </table>
          <p style="margin:0 0 8px;color:#6b7280;font-size:13px;line-height:1.5;">If the button does not work, copy and paste this link into your browser:</p>
          <p style="margin:0 0 20px;word-break:break-all;"><a href="' . $safeUrl . '" style="color:#2563eb;font-size:13px;">' . $safeUrl . '</a></p>';
        }

        $qrBlock = '';
        if ($includeQr) {
            $qrBlock = '
          <p style="margin:8px 0 12px;color:#374151;font-size:15px;">You can also print or display this QR code:</p>
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px;">
            <tr>
              <td bgcolor="#ffffff" style="padding:10px;border-radius:8px;border:1px solid #e5e7eb;">
                <img src="cid:tocca_qr" alt="Voting QR poster" width="280" style="display:block;max-width:280px;width:100%;height:auto;border:0;">
              </td>
            </tr>
          </table>';
        }

        return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . $safeSubject . '</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f3f4f6" style="background:#f3f4f6;">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;font-family:Arial,Helvetica,sans-serif;">
          <tr><td bgcolor="#2563eb" style="height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>
          <tr>
            <td bgcolor="#eeeeee" style="padding:28px 32px;background:#eeeeee;">
              <div style="color:#111111;font-size:11px;letter-spacing:1.6px;font-weight:700;">CITY GOVERNMENT OF ORMOC</div>
              <div style="color:#111111;font-size:26px;font-weight:700;margin-top:8px;line-height:1.2;">Tatak Ormoc</div>
              <div style="color:#6b7280;font-size:14px;margin-top:6px;">Consumers&rsquo; Choice Awards (TOCCA)</div>
            </td>
          </tr>
          <tr>
            <td bgcolor="#ffffff" style="padding:32px;background:#ffffff;color:#111111;">
              <p style="margin:0 0 18px;color:#111111;font-size:16px;">' . $greeting . '</p>
              <h1 style="margin:0 0 18px;color:#111111;font-size:28px;line-height:1.25;font-weight:700;">' . $safeHeading . '</h1>
              <div style="color:#111111;font-size:15px;line-height:1.65;">' . $inner . '</div>
              ' . $ctaBlock . $qrBlock . '
            </td>
          </tr>
          <tr>
            <td bgcolor="#eeeeee" style="padding:24px 32px;background:#eeeeee;">
              <p style="margin:0 0 10px;color:#111111;font-size:14px;font-weight:700;">This is an automated message. Please do not reply.</p>
              <p style="margin:0 0 14px;color:#6b7280;font-size:13px;line-height:1.5;">' . $assistHtml . '</p>
              <p style="margin:0;color:#6b7280;font-size:12px;">&copy; ' . $year . ' Tatak Ormoc Consumers&rsquo; Choice Awards</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }
}

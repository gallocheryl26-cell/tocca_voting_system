<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/includes/twg_ballot.php';

$composed = twg_ballot_notice_compose($conn, 21);
echo ($composed['ok'] ? "ok\n" : "fail\n");
echo ($composed['subject'] ?? '') . "\n";
$html = (string) ($composed['html'] ?? '');
echo (str_contains($html, 'Track My Registration') ? "HAS track footer\n" : "no track footer\n");
echo (str_contains($html, 'TWG Top 5') ? "HAS TWG Top 5\n" : "no TWG Top 5\n");
echo (str_contains($html, 'place in the evaluation') ? "has evaluation wording\n" : "missing evaluation wording\n");
echo (str_contains($html, 'Happy Shake') ? "has Happy Shake\n" : "missing Happy Shake\n");
echo (str_contains($html, 'Comfort Soup') ? "has Comfort Soup\n" : "missing Comfort Soup\n");
echo (str_contains($html, 'Date Tart') ? "has Date Tart\n" : "missing Date Tart\n");
echo (str_contains($html, 'Replies to this mailbox are not monitored.') ? "has replies line\n" : "missing replies line\n");

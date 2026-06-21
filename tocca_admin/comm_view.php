<?php

declare(strict_types=1);



require_once __DIR__ . '/require_admin_api.php';

require_once __DIR__ . '/qr_utils.php';

require_once __DIR__ . '/includes/qr_email_body.php';

date_default_timezone_set('Asia/Manila');



function fail($m,$c=400){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m]); exit; }

function ok($p=[]){ echo json_encode(['status'=>'success'] + $p, JSON_UNESCAPED_UNICODE); exit; }



$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) fail('Missing id');



$stmt = $conn->prepare("

  SELECT id, created_at, scheduled_at, sent_at,

         status, retries, error_text,

         type, recipient_name, recipient_email,

         subject, body_html

  FROM tbl_comm_messages

  WHERE id=?

  LIMIT 1

");

$stmt->bind_param('i', $id);

$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();

$stmt->close();



if (!$row) fail('Not found', 404);



$qrUrl = null;

if (strtolower($row['type'] ?? '') === 'qr_email') {

    $bodyHtml = (string)($row['body_html'] ?? '');

    $meta = qr_email_parse_attachment_meta($bodyHtml);

    if ($meta && $meta['file'] !== '') {

        $fs = __DIR__ . '/qrcodes/' . basename($meta['file']);

        if (is_file($fs)) {

            $qrUrl = 'qrcodes/' . basename($meta['file']);

        }

    }

    if (!$qrUrl) {

        $choiceId = $meta['choice_id'] ?? null;

        if (!$choiceId) {

            $choiceId = qr_choice_id_by_recipient(

                $conn,

                (string)($row['recipient_email'] ?? ''),

                (string)($row['recipient_name'] ?? '')

            );

        }

        if ($choiceId) {

            $slug = qr_slug_from_choice_name((string)($row['recipient_name'] ?? ''));

            if ($slug !== '') {

                $fs = __DIR__ . '/qrcodes/' . $slug . '.png';

                if (is_file($fs)) {

                    $qrUrl = 'qrcodes/' . $slug . '.png';

                }

            }

        } elseif (!empty($row['recipient_name'])) {

            $qrUrl = qr_public_path_for_choice_name((string)$row['recipient_name']);

        }

    }

}

$row['qr_url'] = $qrUrl;



ok(['row' => $row]);



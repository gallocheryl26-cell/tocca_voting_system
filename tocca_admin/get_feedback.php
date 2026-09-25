<?php
// tocca_admin/get_feedback.php
declare(strict_types=1);

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/feedback_api_errors.log');

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/admin_init.php';

function jerr(string $m,int $c=500,array $extra=[]){
  http_response_code($c);
  echo json_encode(['status'=>'error','message'=>$m]+$extra);
  exit;
}
function jok(array $d){
  echo json_encode(['status'=>'success']+$d);
  exit;
}

set_error_handler(function($severity,$message,$file,$line){
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if (!isset($conn) || !$conn instanceof mysqli) jerr('DB not available', 500);

  $type     = $_GET['type']      ?? 'nominee';
  $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
  if ($event_id <= 0 && isset($conn) && $conn instanceof mysqli) {
    $activeId = admin_active_event_id($conn);
    if ($activeId) {
      $event_id = $activeId;
    }
  }
  $from     = trim((string)($_GET['date_from'] ?? ''));
  $to       = trim((string)($_GET['date_to']   ?? ''));
  $q        = trim((string)($_GET['q']         ?? ''));
  $start    = max(0, (int)($_GET['start'] ?? 0));
  $length   = max(1, min(200, (int)($_GET['length'] ?? 25)));
  $dateCol  = 'submitted_at';

  /* ===== VOTER FEEDBACK ===== */
  if ($type === 'voter') {
    $where = ['f.feedback_type = ?']; $types='s'; $args=['voter'];
    if ($event_id > 0) { $where[]='f.event_id = ?';          $types.='i'; $args[]=$event_id; }
    if ($from   !== ''){ $where[]="DATE(f.`$dateCol`) >= ?"; $types.='s'; $args[]=$from; }
    if ($to     !== ''){ $where[]="DATE(f.`$dateCol`) <= ?"; $types.='s'; $args[]=$to; }
    if ($q      !== ''){ $where[]="(f.feedback LIKE ? OR v.mobile_number LIKE ? OR v.google_email LIKE ?)"; $types.='sss'; $like="%$q%"; array_push($args,$like,$like,$like); }
    $whereSql = 'WHERE '.implode(' AND ', $where);
    $base = "
      FROM tbl_feedback f
      LEFT JOIN tbl_voters v ON v.voters_id = f.voters_id
      $whereSql
    ";

    $stc = $conn->prepare("SELECT COUNT(*) AS c $base");
    $stc->bind_param($types, ...$args);
    $stc->execute();
    $cnt = (int)($stc->get_result()->fetch_assoc()['c'] ?? 0);
    $stc->close();

    $sql = "SELECT
              f.feedback_id,
              f.feedback,
              f.is_anonymous,
              v.mobile_number AS phone_raw,
              v.google_email,
              v.auth_provider,
              f.`$dateCol` AS submitted_at
            $base
            ORDER BY f.`$dateCol` DESC, f.feedback_id DESC
            LIMIT ?, ?";
    $std = $conn->prepare($sql);
    $types2 = $types.'ii'; $args2  = array_merge($args, [$start, $length]);
    $std->bind_param($types2, ...$args2);
    $std->execute(); $rs = $std->get_result();

    $rows = [];
    while ($r = $rs->fetch_assoc()) {
      $isAnon = (int)($r['is_anonymous'] ?? 1);
      $phone  = (string)($r['phone_raw'] ?? '');
      $googleEmail = trim((string)($r['google_email'] ?? ''));
      $display = $isAnon
        ? 'Anonymous'
        : ($googleEmail !== '' ? $googleEmail : ($phone !== '' ? admin_mask_mobile($phone) : '—'));

      $rows[] = [
        'feedback_id'   => (int)$r['feedback_id'],
        'feedback'      => (string)$r['feedback'],
        'phone_raw'     => $phone !== '' ? $phone : $googleEmail,
        'submitted_at'  => (string)$r['submitted_at'],
        'is_anonymous'  => $isAnon,
        'display_name'  => $display,
        'contact_person'=> ''
      ];
    }
    $std->close();

    jok(['data'=>$rows, 'recordsTotal'=>$cnt, 'recordsFiltered'=>$cnt]);
  }

  /* ===== NOMINEE FEEDBACK ===== */
  // (unchanged from your latest version)
  $estFieldId = null; $logoFieldId = null;
  $res = $conn->query("SELECT id, LOWER(name) AS n, LOWER(label) AS l, type FROM tbl_nomination_fields");
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $id=(int)$r['id']; $n=(string)$r['n']; $l=(string)$r['l']; $t=(string)$r['type'];
      if ($estFieldId===null && $t!=='file') {
        if (in_array($n,['establishment','establishment_name','business','business_name','company','company_name','nominee','nominee_name','store','shop'],true)
            || preg_match('/\b(establish|business|company|nominee|store|shop)\b/',$l)) $estFieldId=$id;
      }
      if ($logoFieldId===null) {
        $looksLogo = in_array($n,['logo','logo_url','business_logo','establishment_logo','image','image_url','photo'],true) || strpos($l,'logo')!==false;
        if ($looksLogo && ($t==='file'||$t==='url'||$t==='text')) $logoFieldId=$id;
      }
      if ($estFieldId!==null && $logoFieldId!==null) break;
    }
    $res->close();
  }

  $where = ['f.feedback_type = ?']; $types='s'; $args=['nominee'];
  if ($event_id > 0) { $where[]='f.event_id = ?';          $types.='i'; $args[]=$event_id; }
  if ($from   !== ''){ $where[]="DATE(f.`$dateCol`) >= ?"; $types.='s'; $args[]=$from; }
  if ($to     !== ''){ $where[]="DATE(f.`$dateCol`) <= ?"; $types.='s'; $args[]=$to; }

  $fallbackSub = "
    (
      SELECT aa.answer
      FROM tbl_nomination_answers aa
      JOIN tbl_nomination_fields ff ON ff.id = aa.field_id
      WHERE aa.nomination_id = f.nomination_id
        AND ff.type <> 'file'
        AND (
          LOWER(ff.name) IN ('establishment','establishment_name','business','business_name','company','company_name','nominee','nominee_name','store','shop')
          OR ff.label REGEXP '(?i)(establish|business|company|nominee|store|shop)'
        )
      ORDER BY aa.id ASC
      LIMIT 1
    )
  ";
  if ($q !== '') {
    $where[] = "(f.feedback LIKE ? OR ($fallbackSub) LIKE ?)";
    $types  .= 'ss'; $like="%$q%"; array_push($args,$like,$like);
  }
  $whereSql = 'WHERE '.implode(' AND ', $where);

  $joins=''; $joinTypes=''; $joinArgs=[];
  if ($estFieldId!==null){ $joins.=" LEFT JOIN tbl_nomination_answers a_est ON a_est.nomination_id=f.nomination_id AND a_est.field_id=? "; $joinTypes.='i'; $joinArgs[]=$estFieldId; }
  if ($logoFieldId!==null){ $joins.=" LEFT JOIN tbl_nomination_answers a_logo ON a_logo.nomination_id=f.nomination_id AND a_logo.field_id=? "; $joinTypes.='i'; $joinArgs[]=$logoFieldId; }

  $totalWhere = ['f.feedback_type = ?']; $totalTypes='s'; $totalArgs=['nominee'];
  if ($event_id > 0) { $totalWhere[]='f.event_id = ?'; $totalTypes.='i'; $totalArgs[]=$event_id; }
  $stTot = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_feedback f WHERE ".implode(' AND ',$totalWhere));
  $stTot->bind_param($totalTypes, ...$totalArgs); $stTot->execute();
  $recordsTotal = (int)($stTot->get_result()->fetch_assoc()['c'] ?? 0); $stTot->close();

  $stCount = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_feedback f $whereSql");
  $stCount->bind_param($types, ...$args); $stCount->execute();
  $recordsFiltered = (int)($stCount->get_result()->fetch_assoc()['c'] ?? 0); $stCount->close();

  $sql = "
    SELECT
      f.feedback_id,
      f.feedback,
      f.is_anonymous,
      f.`$dateCol` AS submitted_at,
      f.nomination_id
      ".($estFieldId!==null ? ", a_est.answer  AS establishment_name" : "")."
      ".($logoFieldId!==null ? ", a_logo.answer AS establishment_logo" : "").",
      $fallbackSub AS establishment_fallback
    FROM tbl_feedback f
    $joins
    $whereSql
    ORDER BY f.`$dateCol` DESC, f.feedback_id DESC
    LIMIT ?, ?
  ";
  $st = $conn->prepare($sql);
  $typesAll = $joinTypes.$types.'ii';
  $argsAll  = array_merge($joinArgs,$args,[$start,$length]);
  $st->bind_param($typesAll, ...$argsAll);
  $st->execute(); $rs = $st->get_result();

  $rows=[];
  while ($r = $rs->fetch_assoc()) {
    $business = $r['establishment_name'] ?? $r['establishment_fallback'] ?? '—';
    $business = trim((string)$business);
    if ($business !== '' && preg_match('/\.(png|jpe?g|webp|gif|svg)(\?.*)?$/i',$business)) $business='—';
    elseif ($business !== '' && preg_match('#^https?://#i',$business) && preg_match('/\.(png|jpe?g|webp|gif|svg)(\?.*)?$/i',$business)) $business='—';

    $logo = !empty($r['establishment_logo']) ? (string)$r['establishment_logo'] : null;

    $isAnon = (int)($r['is_anonymous'] ?? 1);
    $displayName = ($isAnon === 1) ? 'Anonymous' : (($business !== '') ? $business : '—');

    $rows[] = [
      'feedback_id'   => (int)$r['feedback_id'],
      'feedback'      => (string)$r['feedback'],
      'is_anonymous'  => $isAnon,
      'display_name'  => $displayName,
      'business_name' => ($business !== '' ? $business : '—'),
      'logo_url'      => $logo,
      'submitted_at'  => (string)$r['submitted_at'],
      'contact_person'=> ''
    ];
  }
  $st->close();

  jok(['data'=>$rows,'recordsTotal'=>$recordsTotal,'recordsFiltered'=>$recordsFiltered]);

} catch (Throwable $e) {
  jerr('Unhandled error', 500, ['exception'=>get_class($e),'error'=>$e->getMessage()]);
}

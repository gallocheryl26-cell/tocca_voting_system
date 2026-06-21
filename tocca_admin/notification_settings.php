<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/notification_config.php';

$rawBody = file_get_contents('php://input') ?: '';
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $data = $_POST;
}
if (!is_array($data)) {
    $data = [];
}

function respond_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function respond_success(array $payload = []): void
{
    echo json_encode(['status' => 'success', 'data' => $payload]);
    exit;
}

$existingNominationStart = notif_config_get_thresholds(
    $conn,
    'notif_nomination_start_hours',
    NOTIF_CONFIG_DEFAULT_NOMINATION_START_HOURS
);

$existingNominationEnd = notif_config_get_thresholds(
    $conn,
    'notif_nomination_end_hours',
    NOTIF_CONFIG_DEFAULT_NOMINATION_END_HOURS
);
$existingPendingDeadline = notif_config_get_thresholds(
    $conn,
    'notif_pending_deadline_hours',
    NOTIF_CONFIG_DEFAULT_PENDING_DEADLINE_HOURS
);
if (empty($existingPendingDeadline)) {
    $existingPendingDeadline = $existingNominationEnd;
}
$existingVotingStart = notif_config_get_thresholds(
    $conn,
    'notif_voting_start_hours',
    NOTIF_CONFIG_DEFAULT_VOTING_START_HOURS
);
$existingVotingEnd = notif_config_get_thresholds(
    $conn,
    'notif_voting_end_hours',
    NOTIF_CONFIG_DEFAULT_VOTING_END_HOURS
);
$existingPendingMin = notif_config_get_int(
    $conn,
    'notif_pending_min_total',
    NOTIF_CONFIG_DEFAULT_PENDING_MIN_TOTAL,
    0,
    10000
);
$existingPendingWarning = notif_config_get_int(
    $conn,
    'notif_pending_warning_hours',
    NOTIF_CONFIG_DEFAULT_PENDING_WARNING_HOURS,
    0,
    720
);

$nominationStartInput = (string)($data['nomination_start_thresholds'] ?? '');
$nominationEndInput   = (string)($data['nomination_end_thresholds'] ?? '');
$pendingDeadlineInput = (string)($data['pending_deadline_thresholds'] ?? '');
$votingStartInput = (string)($data['voting_start_thresholds'] ?? '');
$votingEndInput = (string)($data['voting_end_thresholds'] ?? '');

$nominationStartHours = notif_config_parse_user_input($nominationStartInput, $existingNominationStart);
$nominationEndHours   = notif_config_parse_user_input($nominationEndInput, $existingNominationEnd);
$pendingDeadlineHours = notif_config_parse_user_input($pendingDeadlineInput, $existingPendingDeadline);
if (empty($pendingDeadlineHours)) {
    $pendingDeadlineHours = $nominationEndHours;
}
$votingStartHours = notif_config_parse_user_input($votingStartInput, $existingVotingStart);
$votingEndHours = notif_config_parse_user_input($votingEndInput, $existingVotingEnd);

$pendingMinRaw = $data['pending_min_total'] ?? $existingPendingMin;
$pendingMin = filter_var($pendingMinRaw, FILTER_VALIDATE_INT, [
    'options' => [
        'default' => $existingPendingMin,
        'min_range' => 0,
        'max_range' => 10000,
    ],
]);
if (!is_int($pendingMin)) {
    $pendingMin = $existingPendingMin;
}

$pendingWarningRaw = $data['pending_warning_hours'] ?? $existingPendingWarning;
$pendingWarning = filter_var($pendingWarningRaw, FILTER_VALIDATE_INT, [
    'options' => [
        'default' => $existingPendingWarning,
        'min_range' => 0,
        'max_range' => 720,
    ],
]);
if (!is_int($pendingWarning)) {
    $pendingWarning = $existingPendingWarning;
}

if (!notif_config_set_thresholds($conn, 'notif_nomination_start_hours', $nominationStartHours)) {
    respond_error('Failed to save nomination opening alerts.', 500);
}

if (!notif_config_set_thresholds($conn, 'notif_nomination_end_hours', $nominationEndHours)) {
    respond_error('Failed to save nomination deadline alerts.', 500);
}
if (!notif_config_set_thresholds($conn, 'notif_pending_deadline_hours', $pendingDeadlineHours)) {
    respond_error('Failed to save pending deadline alerts.', 500);
}
if (!notif_config_set_thresholds($conn, 'notif_voting_start_hours', $votingStartHours)) {
    respond_error('Failed to save voting start alerts.', 500);
}
if (!notif_config_set_thresholds($conn, 'notif_voting_end_hours', $votingEndHours)) {
    respond_error('Failed to save voting end alerts.', 500);
}
if (!notif_config_set_int($conn, 'notif_pending_min_total', $pendingMin, 0, 10000)) {
    respond_error('Failed to save pending nomination threshold.', 500);
}
if (!notif_config_set_int($conn, 'notif_pending_warning_hours', $pendingWarning, 0, 720)) {
    respond_error('Failed to save warning window.', 500);
}

respond_success([
    'nomination_start_thresholds'         => $nominationStartHours,
    'nomination_start_thresholds_display' => notif_config_format_hours_list($nominationStartHours),
    'nomination_end_thresholds'           => $nominationEndHours,
    'nomination_end_thresholds_display'   => notif_config_format_hours_list($nominationEndHours),   
    'pending_deadline_thresholds'      => $pendingDeadlineHours,
    'pending_deadline_thresholds_display' => notif_config_format_hours_list($pendingDeadlineHours),
    'voting_start_thresholds'          => $votingStartHours,
    'voting_start_thresholds_display'  => notif_config_format_hours_list($votingStartHours),
    'voting_end_thresholds'            => $votingEndHours,
    'voting_end_thresholds_display'    => notif_config_format_hours_list($votingEndHours),
    'pending_min_total'                => $pendingMin,
    'pending_warning_hours'            => $pendingWarning,
]);
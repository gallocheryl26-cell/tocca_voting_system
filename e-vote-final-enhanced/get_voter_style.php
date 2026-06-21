<?php
declare(strict_types=1);

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/includes/voter_appearance.php';

header('Content-Type: application/json; charset=utf-8');

$appearance = voter_appearance_load($conn);

echo json_encode([
    'bgColor'      => $appearance['bgColor'],
    'textColor'    => $appearance['textColor'],
    'headerLogo'   => $appearance['headerLogo'],
    'primaryColor' => voter_appearance_get_config($conn, 'voterPrimaryColor', '#007bff'),
    'buttonColor'  => voter_appearance_get_config($conn, 'voterButtonColor', '#007bff'),
    'configured'   => voter_appearance_is_configured($conn),
], JSON_THROW_ON_ERROR);

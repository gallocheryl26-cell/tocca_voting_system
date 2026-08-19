<?php
declare(strict_types=1);

/**
 * Same-folder QR endpoint for Events → Public links.
 * Avoids guessing /nomination/ from the admin URL (broken on domain-root hosts).
 */
require __DIR__ . '/../nomination/generate_nomination_qr.php';

<?php
declare(strict_types=1);

/**
 * Text drawn on the voting QR poster: business name only.
 */

if (!function_exists('qr_poster_sample_caption')) {
    function qr_poster_sample_caption(): string
    {
        return 'Sample Cafe';
    }
}

if (!function_exists('qr_poster_caption_for_choice')) {
    function qr_poster_caption_for_choice(mysqli $conn, int $choiceId, string $choiceName): string
    {
        unset($conn, $choiceId);
        return trim($choiceName);
    }
}

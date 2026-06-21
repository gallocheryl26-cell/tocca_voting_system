<?php
declare(strict_types=1);

/**
 * Short URL tokens for nominee QR codes.
 */
function choice_token_ensure_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS tbl_choice_tokens (
  token_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  choice_id  INT NOT NULL,
  token      VARCHAR(16) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_choice_tokens_token (token),
  UNIQUE KEY uq_choice_tokens_choice (choice_id),
  KEY idx_choice_tokens_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $conn->query($sql);
    $done = true;
}

function choice_token_generate(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    $token = '';
    for ($i = 0; $i < 8; $i++) {
        $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $token;
}

function choice_token_get_or_create(mysqli $conn, int $choiceId): string
{
    choice_token_ensure_table($conn);

    $stmt = $conn->prepare(
        'SELECT token FROM tbl_choice_tokens WHERE choice_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return (string)$row['token'];
    }
    $stmt->close();

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $token = choice_token_generate();
        $ins = $conn->prepare(
            'INSERT INTO tbl_choice_tokens (choice_id, token, is_active) VALUES (?, ?, 1)'
        );
        $ins->bind_param('is', $choiceId, $token);
        if ($ins->execute()) {
            $ins->close();
            return $token;
        }
        $ins->close();
        if ($conn->errno !== 1062) {
            break;
        }
    }

    throw new RuntimeException('Unable to allocate QR token for choice #' . $choiceId);
}

function choice_token_lookup(mysqli $conn, string $token): ?array
{
    choice_token_ensure_table($conn);

    $token = trim($token);
    if ($token === '' || strlen($token) > 16) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT t.token, t.choice_id, c.choice_name, c.email, c.event_id, c.status
         FROM tbl_choice_tokens t
         JOIN tbl_choices c ON c.choice_id = t.choice_id
         WHERE t.token = ? AND t.is_active = 1
         LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function choice_token_backfill_all(mysqli $conn): int
{
    choice_token_ensure_table($conn);

    $count = 0;
    $res = $conn->query('SELECT choice_id FROM tbl_choices');
    if (!$res) {
        return 0;
    }

    while ($row = $res->fetch_assoc()) {
        choice_token_get_or_create($conn, (int)$row['choice_id']);
        $count++;
    }

    return $count;
}

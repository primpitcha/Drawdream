<?php
// includes/payment_transaction_schema.php — คอลัมน์สำหรับรอชำระ (ไม่สร้างแถว donation จนกว่าจะสำเร็จ)
declare(strict_types=1);

function drawdream_payment_transaction_ensure_schema(mysqli $conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM payment_transaction LIKE 'donate_id'");
    $row = $chk ? $chk->fetch_assoc() : null;
    if ($row && strtoupper((string)($row['Null'] ?? '')) === 'NO') {
        @$conn->query('ALTER TABLE payment_transaction MODIFY donate_id INT NULL DEFAULT NULL');
    }

    $cols = [
        'pending_category_id' => 'INT NULL DEFAULT NULL',
        'pending_target_id' => 'INT NULL DEFAULT NULL',
        'pending_amount' => 'DECIMAL(10,2) NULL DEFAULT NULL',
        'pending_donor_user_id' => 'INT NULL DEFAULT NULL',
    ];
    foreach ($cols as $name => $def) {
        $c = @$conn->query("SHOW COLUMNS FROM payment_transaction LIKE '" . $conn->real_escape_string($name) . "'");
        if ($c && $c->num_rows === 0) {
            @$conn->query("ALTER TABLE payment_transaction ADD COLUMN `{$name}` {$def}");
        }
    }
}

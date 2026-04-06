<?php

// includes/drawdream_soft_delete.php — คอลัมน์ soft delete เด็ก/โครงการ
declare(strict_types=1);

/**
 * Soft delete: foundation_children และ foundation_project
 *
 * - deleted_at = เวลาที่ “ซ่อน” แทนลบถาวร | query สาธารณะใช้ deleted_at IS NULL
 * - เรียกจาก db.php ทุก request ผ่าน drawdream_ensure_soft_delete_columns()
 */
function drawdream_ensure_soft_delete_columns(mysqli $conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'deleted_at'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_children ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL');
    }
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'profile_delete_reason'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_children ADD COLUMN profile_delete_reason TEXT NULL DEFAULT NULL');
    }
    $chk = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'deleted_at'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_project ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL');
    }
    $chk = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'project_delete_reason'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_project ADD COLUMN project_delete_reason TEXT NULL DEFAULT NULL');
    }
}

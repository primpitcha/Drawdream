<?php
// tools/fix_donation_category_cli.php — แก้ donation.category_id โดยไม่ผ่าน phpMyAdmin (กัน Error 1175 / encoding)
// ใช้: c:\xampp\php\php.exe tools\fix_donation_category_cli.php project_first
//       c:\xampp\php\php.exe tools\fix_donation_category_cli.php child_first
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run: php tools/fix_donation_category_cli.php project_first|child_first\n");
    exit(1);
}

$mode = strtolower(trim($argv[1] ?? 'project_first'));
$root = dirname(__DIR__);
require_once $root . '/db.php';

if (!$conn instanceof mysqli) {
    fwrite(STDERR, "No mysqli connection\n");
    exit(1);
}

$conn->query('SET SESSION sql_safe_updates = 0');

if ($mode === 'child_first') {
    $sql = <<<'SQL'
UPDATE donation d
SET d.category_id = (
    CASE
        WHEN EXISTS (
            SELECT 1 FROM foundation_children c
            WHERE c.child_id = d.target_id AND c.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.child_donate IS NOT NULL
              AND TRIM(COALESCE(dc.child_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_project p
            WHERE p.project_id = d.target_id AND p.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.project_donate IS NOT NULL
              AND TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_profile fp
            WHERE fp.foundation_id = d.target_id
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.needitem_donate IS NOT NULL
              AND TRIM(COALESCE(dc.needitem_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        ELSE d.category_id
    END
)
WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
SQL;
} else {
    $sql = <<<'SQL'
UPDATE donation d
SET d.category_id = (
    CASE
        WHEN EXISTS (
            SELECT 1 FROM foundation_project p
            WHERE p.project_id = d.target_id AND p.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.project_donate IS NOT NULL
              AND TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_children c
            WHERE c.child_id = d.target_id AND c.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.child_donate IS NOT NULL
              AND TRIM(COALESCE(dc.child_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_profile fp
            WHERE fp.foundation_id = d.target_id
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.needitem_donate IS NOT NULL
              AND TRIM(COALESCE(dc.needitem_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        ELSE d.category_id
    END
)
WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
SQL;
}

if (!$conn->query($sql)) {
    fwrite(STDERR, 'MySQL error: ' . $conn->error . "\n");
    exit(1);
}

echo 'OK. Rows matched/affected: ' . $conn->affected_rows . "\n";
exit(0);

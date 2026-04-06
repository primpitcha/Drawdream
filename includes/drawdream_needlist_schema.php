<?php

// includes/drawdream_needlist_schema.php — Schema/migration รายการสิ่งของ
declare(strict_types=1);

/**
 * โครงสร้าง foundation_needlist: รูปสิ่งของได้หลายไฟล์ (คอลัมน์ 3 + item_image เป็น TEXT)
 */
function drawdream_ensure_needlist_schema(mysqli $conn): void
{
    $t = @$conn->query("SHOW TABLES LIKE 'foundation_needlist'");
    if (!$t || $t->num_rows === 0) {
        return;
    }

    $chk = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'item_image'");
    if ($chk && ($col = $chk->fetch_assoc())) {
        $type = strtolower((string)($col['Type'] ?? ''));
        if (preg_match('/^varchar\(/i', $type) || preg_match('/^char\(/i', $type)) {
            @$conn->query('ALTER TABLE foundation_needlist MODIFY COLUMN `item_image` TEXT NULL DEFAULT NULL');
        }
    }

    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'item_image_2'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN item_image_2 VARCHAR(255) NULL DEFAULT NULL AFTER item_image');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'item_image_3'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN item_image_3 VARCHAR(255) NULL DEFAULT NULL AFTER item_image_2');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_foundation_image'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN need_foundation_image VARCHAR(255) NULL DEFAULT NULL AFTER item_image_3');
    }

    $addedDonateWindowEnd = false;
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'donate_window_end_at'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN donate_window_end_at DATETIME NULL DEFAULT NULL');
        $addedDonateWindowEnd = true;
    }
    if ($addedDonateWindowEnd) {
        require_once __DIR__ . '/needlist_donate_window.php';
        drawdream_needlist_backfill_donate_window_ends($conn);
    }
}

/**
 * แยกชื่อไฟล์จากสตริงเก็บใน item_image (| หรือ ,)
 *
 * @return list<string>
 */
function foundation_parse_need_item_filenames(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    if (strpos($raw, '|') !== false) {
        $parts = preg_split('/\|+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    } elseif (strpos($raw, ',') !== false) {
        $parts = preg_split('/\s*,\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    } else {
        $parts = [$raw];
    }
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '' && $p !== '.' && $p !== '..') {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * รวมชื่อไฟล์รูปสิ่งของจากแถว needlist (รองรับทั้งแบบ pipe ใน item_image แบบเก่า และ 3 คอลัมน์)
 *
 * @param array<string,mixed> $row
 * @return list<string>
 */
function foundation_needlist_item_filenames_from_row(array $row): array
{
    $raw1 = trim((string)($row['item_image'] ?? ''));
    if ($raw1 !== '' && (strpos($raw1, '|') !== false || strpos($raw1, ',') !== false)) {
        return array_slice(foundation_parse_need_item_filenames($raw1), 0, 3);
    }

    $out = [];
    foreach (['item_image', 'item_image_2', 'item_image_3'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v === '' || $v === '.' || $v === '..') {
            continue;
        }
        $out[] = basename($v);
    }
    return array_slice($out, 0, 3);
}

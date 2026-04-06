<?php
// includes/donate_category_resolve.php — หมวดบริจาคจาก donate_category (คอลัมน์ที่ไม่ใช้มักเป็น '-' ซึ่งยัง IS NOT NULL อยู่)
declare(strict_types=1);

/** ค่าที่ถือว่าเป็นหมวดที่ «ใช้งานจริง» (ไม่ใช่ placeholder) */
function drawdream_donate_cat_label_is_active(?string $v): bool
{
    $t = trim((string)$v);

    return $t !== '' && $t !== '-';
}

function drawdream_donate_category_id_for_child(mysqli $conn): int
{
    $stmt = $conn->prepare(
        'SELECT category_id FROM donate_category
         WHERE child_donate IS NOT NULL
           AND TRIM(COALESCE(child_donate, \'\')) NOT IN (\'\', \'-\')
         ORDER BY category_id ASC LIMIT 1'
    );
    if (!$stmt || !$stmt->execute()) {
        return 0;
    }
    $row = $stmt->get_result()->fetch_assoc();

    return $row ? (int)$row['category_id'] : 0;
}

function drawdream_donate_category_id_for_project(mysqli $conn): int
{
    $stmt = $conn->prepare(
        'SELECT category_id FROM donate_category
         WHERE project_donate IS NOT NULL
           AND TRIM(COALESCE(project_donate, \'\')) NOT IN (\'\', \'-\')
         ORDER BY category_id ASC LIMIT 1'
    );
    if (!$stmt || !$stmt->execute()) {
        return 0;
    }
    $row = $stmt->get_result()->fetch_assoc();

    return $row ? (int)$row['category_id'] : 0;
}

function drawdream_donate_category_id_for_needitem(mysqli $conn): int
{
    $stmt = $conn->prepare(
        'SELECT category_id FROM donate_category
         WHERE needitem_donate IS NOT NULL
           AND TRIM(COALESCE(needitem_donate, \'\')) NOT IN (\'\', \'-\')
         ORDER BY category_id ASC LIMIT 1'
    );
    if (!$stmt || !$stmt->execute()) {
        return 0;
    }
    $row = $stmt->get_result()->fetch_assoc();

    return $row ? (int)$row['category_id'] : 0;
}

function drawdream_get_or_create_child_donate_category_id(mysqli $conn): int
{
    $id = drawdream_donate_category_id_for_child($conn);
    if ($id > 0) {
        return $id;
    }
    $col = @$conn->query("SHOW COLUMNS FROM donate_category LIKE 'child_donate'");
    if ($col && $col->num_rows === 0) {
        @$conn->query('ALTER TABLE donate_category ADD COLUMN child_donate VARCHAR(100) NULL');
    }
    if ($conn->query("INSERT INTO donate_category (child_donate) VALUES ('บริจาคให้เด็ก')")) {
        return (int)$conn->insert_id;
    }

    return 0;
}

function drawdream_get_or_create_project_donate_category_id(mysqli $conn): int
{
    $id = drawdream_donate_category_id_for_project($conn);
    if ($id > 0) {
        return $id;
    }
    if ($conn->query("INSERT INTO donate_category (project_donate) VALUES ('บริจาคโครงการ')")) {
        return (int)$conn->insert_id;
    }

    return 0;
}

function drawdream_get_or_create_needitem_donate_category_id(mysqli $conn): int
{
    $id = drawdream_donate_category_id_for_needitem($conn);
    if ($id > 0) {
        return $id;
    }
    if ($conn->query("INSERT INTO donate_category (needitem_donate) VALUES ('บริจาคสิ่งของ')")) {
        return (int)$conn->insert_id;
    }

    return 0;
}

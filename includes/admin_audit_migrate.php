<?php
// includes/admin_audit_migrate.php — Migration ตาราง audit แอดมิน + helpers
// ตาราง admin แบบย่อ: id, admin_id, target_id, target_entity, remark, notif_*, action_at
// ไม่ใช้คอลัมน์ action_type / actor_user_id — ใช้ notif_type ระบุเหตุการณ์แทน

declare(strict_types=1);

function drawdream_admin_try_query(mysqli $conn, string $sql): bool
{
    try {
        return (bool) $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string, true> */
function drawdream_admin_table_columns(mysqli $conn): array
{
    $cols = [];
    $r = @$conn->query('SHOW COLUMNS FROM `admin`');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $f = (string)($row['Field'] ?? '');
            if ($f !== '') {
                $cols[$f] = true;
            }
        }
    }
    return $cols;
}

function drawdream_admin_drop_all_foreign_keys(mysqli $conn, string $table): void
{
    $dbRow = @$conn->query('SELECT DATABASE()');
    $dbEsc = $conn->real_escape_string($dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '');
    $tblEsc = $conn->real_escape_string($table);
    if ($dbEsc === '' || $tblEsc === '') {
        return;
    }
    $q = $conn->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = '{$dbEsc}' AND TABLE_NAME = '{$tblEsc}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    if (!$q) {
        return;
    }
    while ($row = $q->fetch_assoc()) {
        $n = $row['CONSTRAINT_NAME'] ?? '';
        if ($n === '') {
            continue;
        }
        $nEsc = $conn->real_escape_string($n);
        drawdream_admin_try_query(
            $conn,
            "ALTER TABLE `" . str_replace('`', '``', $table) . "` DROP FOREIGN KEY `{$nEsc}`"
        );
    }
}

function drawdream_admin_constraint_exists(mysqli $conn, string $table, string $constraintName): bool
{
    $dbRow = @$conn->query('SELECT DATABASE()');
    $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
    if ($db === '') {
        return false;
    }
    $dbEsc = $conn->real_escape_string($db);
    $tblEsc = $conn->real_escape_string($table);
    $nameEsc = $conn->real_escape_string($constraintName);
    $q = @$conn->query(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = '{$dbEsc}' AND TABLE_NAME = '{$tblEsc}' AND CONSTRAINT_NAME = '{$nameEsc}' LIMIT 1"
    );
    return $q && $q->num_rows > 0;
}

/** แปลงชื่อเหตุการณ์ภายใน (เช่น Approve_Project) เป็น target_entity */
function drawdream_action_target_entity(string $actionType): string
{
    if (str_contains($actionType, 'Need') || str_contains($actionType, 'need')) {
        return 'need';
    }
    if (str_contains($actionType, 'Child') || str_contains($actionType, 'child')) {
        return 'child';
    }
    if (str_contains($actionType, 'Submit_Project') || str_contains($actionType, 'Project')) {
        return 'project';
    }
    if (str_contains($actionType, 'Foundation')) {
        return 'foundation';
    }
    return 'other';
}

/** ค่าเก็บ/แสดงมาตรฐาน 3 ระดับ: กำลังรอดำเนินการ | อนุมัติ | ไม่อนุมัติ */
function drawdream_normalize_notif_type_to_th(?string $type): string
{
    $raw = trim((string)$type);
    if ($raw === '') {
        return 'กำลังรอดำเนินการ';
    }
    if ($raw === 'อนุมัติ' || $raw === 'ไม่อนุมัติ' || $raw === 'กำลังรอดำเนินการ') {
        return $raw;
    }
    $t = strtolower($raw);
    if (str_contains($t, 'reject')) {
        return 'ไม่อนุมัติ';
    }
    if (str_contains($t, 'approv')
        || str_contains($t, 'completed')
        || str_contains($t, 'funded')
        || str_contains($t, 'success')
        || $t === 'needlist_done') {
        return 'อนุมัติ';
    }
    return 'กำลังรอดำเนินการ';
}

/** ป้ายภาษาไทยจาก notif_type — คืนหนึ่งในสามค่ามาตรฐาน (รองรับโค้ดภาษาอังกฤษในแถวเก่า) */
function drawdream_admin_notif_type_label_th(string $type): string
{
    if (trim($type) === '') {
        return 'กำลังรอดำเนินการ';
    }
    return drawdream_normalize_notif_type_to_th($type);
}

/**
 * รันอัตโนมัติหลังเชื่อม DB — ปลอดภัยถ้ารันซ้ำ
 */
function drawdream_ensure_admin_audit_table(mysqli $conn): void
{
    $exists = @$conn->query("SHOW TABLES LIKE 'admin'");
    if (!$exists || $exists->num_rows === 0) {
        return;
    }

    drawdream_admin_drop_all_foreign_keys($conn, 'admin');
    $cols = drawdream_admin_table_columns($conn);

    if (isset($cols['log_id']) && !isset($cols['id'])) {
        drawdream_admin_try_query($conn, 'ALTER TABLE `admin` CHANGE COLUMN log_id id INT(11) NOT NULL AUTO_INCREMENT');
        $cols = drawdream_admin_table_columns($conn);
    }

    if (isset($cols['action_type'])) {
        drawdream_admin_try_query($conn, 'ALTER TABLE `admin` DROP COLUMN action_type');
        $cols = drawdream_admin_table_columns($conn);
    }
    if (isset($cols['actor_user_id'])) {
        drawdream_admin_try_query($conn, 'ALTER TABLE `admin` DROP COLUMN actor_user_id');
        $cols = drawdream_admin_table_columns($conn);
    }

    if (isset($cols['admin_id'])) {
        drawdream_admin_try_query($conn, 'ALTER TABLE `admin` MODIFY COLUMN admin_id INT(11) NULL DEFAULT NULL');
    }

    $cols = drawdream_admin_table_columns($conn);
    if (!isset($cols['target_entity']) && isset($cols['target_id'])) {
        drawdream_admin_try_query(
            $conn,
            "ALTER TABLE `admin` ADD COLUMN target_entity VARCHAR(32) NULL DEFAULT NULL
             COMMENT 'project|child|need|foundation|other' AFTER target_id"
        );
        $cols = drawdream_admin_table_columns($conn);
    }

    if (!isset($cols['notif_recipient_user_id'])) {
        $after = isset($cols['remark']) ? 'remark' : (isset($cols['target_entity']) ? 'target_entity' : 'target_id');
        drawdream_admin_try_query(
            $conn,
            "ALTER TABLE `admin` ADD COLUMN notif_recipient_user_id INT(11) UNSIGNED NULL DEFAULT NULL AFTER `{$after}`"
        );
        $cols = drawdream_admin_table_columns($conn);
    }

    if (!isset($cols['notif_type'])) {
        drawdream_admin_try_query(
            $conn,
            'ALTER TABLE `admin` ADD COLUMN notif_type VARCHAR(64) NULL DEFAULT NULL AFTER notif_recipient_user_id'
        );
        $cols = drawdream_admin_table_columns($conn);
    }

    if (!isset($cols['action_at'])) {
        drawdream_admin_try_query(
            $conn,
            'ALTER TABLE `admin` ADD COLUMN action_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );
    }

    if (isset($cols['admin_id']) && !drawdream_admin_constraint_exists($conn, 'admin', 'admin_admin_user_fk')) {
        drawdream_admin_try_query(
            $conn,
            'ALTER TABLE `admin` ADD CONSTRAINT admin_admin_user_fk
             FOREIGN KEY (admin_id) REFERENCES `user`(user_id)
             ON DELETE SET NULL ON UPDATE RESTRICT'
        );
    }
}

/**
 * รวมแถวซ้ำใน `admin` ที่มี target_id + target_entity เดียวกัน (ข้อมูลก่อนแก้โค้ดอาจมี 2 แถว: รอดำเนินการ + อนุมัติ)
 * เก็บแถว id เล็กสุดไว้ อัปเดตสถานะ/แอดมิน/ผู้รับแจ้งจากแถวที่มี admin_id หรือแถวล่าสุด แล้วลบแถวที่เหลือ
 */
function drawdream_admin_deduplicate_entity_rows(mysqli $conn): void
{
    $probe = @$conn->query(
        "SELECT 1 AS x FROM `admin` a
         INNER JOIN `admin` b
           ON a.target_id = b.target_id AND a.target_entity <=> b.target_entity
           AND a.id < b.id
           AND a.target_id IS NOT NULL AND TRIM(COALESCE(a.target_entity,'')) != ''
         LIMIT 1"
    );
    if (!$probe || $probe->num_rows === 0) {
        return;
    }

    $groups = @$conn->query(
        "SELECT target_id, target_entity FROM `admin`
         WHERE target_id IS NOT NULL AND TRIM(COALESCE(target_entity,'')) != ''
         GROUP BY target_id, target_entity
         HAVING COUNT(*) > 1"
    );
    if (!$groups) {
        return;
    }

    while ($g = $groups->fetch_assoc()) {
        $tid = (int)($g['target_id'] ?? 0);
        $ent = trim((string)($g['target_entity'] ?? ''));
        if ($tid <= 0 || $ent === '') {
            continue;
        }

        $st = $conn->prepare(
            'SELECT id, admin_id, remark, notif_recipient_user_id, notif_type, action_at
             FROM `admin` WHERE target_id = ? AND target_entity = ? ORDER BY id ASC'
        );
        if (!$st) {
            continue;
        }
        $st->bind_param('is', $tid, $ent);
        if (!$st->execute()) {
            $st->close();
            continue;
        }
        $res = $st->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $st->close();
        if (count($rows) < 2) {
            continue;
        }

        $keepId = (int)($rows[0]['id']);
        $remarkParts = [];
        foreach ($rows as $r) {
            $rm = trim((string)($r['remark'] ?? ''));
            if ($rm !== '' && !in_array($rm, $remarkParts, true)) {
                $remarkParts[] = $rm;
            }
        }
        $mergedRemark = implode(' | ', $remarkParts);

        $picked = null;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $aid = $rows[$i]['admin_id'];
            if ($aid !== null && (int)$aid > 0) {
                $picked = $rows[$i];
                break;
            }
        }
        if ($picked === null) {
            $picked = $rows[count($rows) - 1];
        }

        $adminId = $picked['admin_id'] !== null && (int)$picked['admin_id'] > 0 ? (int)$picked['admin_id'] : null;
        $notifUid = $picked['notif_recipient_user_id'] !== null && (int)$picked['notif_recipient_user_id'] > 0
            ? (int)$picked['notif_recipient_user_id'] : null;
        $notifType = drawdream_normalize_notif_type_to_th((string)($picked['notif_type'] ?? ''));
        $actionAt = trim((string)($picked['action_at'] ?? ''));
        if ($actionAt === '') {
            $actionAt = date('Y-m-d H:i:s');
        }

        $mrEsc = $conn->real_escape_string($mergedRemark);
        $ntEsc = $conn->real_escape_string($notifType);
        $atEsc = $conn->real_escape_string($actionAt);
        $aidSql = $adminId !== null ? (string)$adminId : 'NULL';
        $nuidSql = $notifUid !== null ? (string)$notifUid : 'NULL';

        $sqlUp = "UPDATE `admin` SET admin_id = {$aidSql}, remark = '{$mrEsc}', notif_recipient_user_id = {$nuidSql}, notif_type = '{$ntEsc}', action_at = '{$atEsc}' WHERE id = " . $keepId;
        if (!@$conn->query($sqlUp)) {
            continue;
        }

        $del = $conn->prepare('DELETE FROM `admin` WHERE target_id = ? AND target_entity = ? AND id != ?');
        if ($del) {
            $kid = $keepId;
            $del->bind_param('isi', $tid, $ent, $kid);
            @$del->execute();
            $del->close();
        }
    }
}


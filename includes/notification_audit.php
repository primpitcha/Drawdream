<?php
// includes/notification_audit.php — แจ้งเตือน notifications + audit แอดมิน
/**
 * แจ้งเตือน (notifications) + บันทึกคิว/audit ฝั่งแอดมิน (ตาราง admin)
 *
 * drawdream_send_notification(..., $entityKey) แทนที่แถวเดิมต่อ (user_id, entity_key)
 * ลดการสะสมซ้ำ | legacy titles “ส่ง…แล้ว” ถูกลบ boot ผ่าน db.php
 *
 * @see docs/SYSTEM_PRESENTATION_GUIDE.md
 */
// helpers: แจ้งเตือนผู้ใช้ + บันทึก admin พร้อมเชื่อมว่ามีการแจ้งเตือนไปที่ใคร

declare(strict_types=1);

require_once __DIR__ . '/admin_audit_migrate.php';

function drawdream_ensure_notifications_table(mysqli $conn): void
{
    @$conn->query(
        "CREATE TABLE IF NOT EXISTS notifications (
            notif_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(64) NOT NULL DEFAULT 'general',
            title VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT,
            link VARCHAR(512) DEFAULT '',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_read (user_id, is_read),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    drawdream_notifications_ensure_entity_key_column($conn);
}

/** เพิ่มคอลัมน์ entity_key สำหรับแทนที่แจ้งเตือนเดิม (ไม่สะสมซ้ำต่อคิว / ต่อเด็ก ฯลฯ) */
function drawdream_notifications_ensure_entity_key_column(mysqli $conn): void
{
    $c = @$conn->query("SHOW COLUMNS FROM `notifications` LIKE 'entity_key'");
    if ($c && $c->num_rows === 0) {
        @$conn->query("ALTER TABLE `notifications` ADD COLUMN entity_key VARCHAR(96) NULL DEFAULT NULL AFTER link");
        @$conn->query("ALTER TABLE `notifications` ADD INDEX idx_notif_user_entity (user_id, entity_key)");
    }
}

/**
 * เรียกจาก db.php ครั้งแรกที่ยังมีข้อมูลเก่า: เพิ่ม entity_key + ลบแจ้งเตือน “ส่ง…แล้ว” ที่ระบบไม่สร้างแล้ว
 * หลังลบครั้งแรก จะไม่มีแถวค้าง → ไม่มีผลต่อ performance รอบถัดไป
 */
function drawdream_notifications_migrate_legacy_on_boot(mysqli $conn): void
{
    drawdream_ensure_notifications_table($conn);
    $legacyTitles = [
        'ส่งรายการสิ่งของแล้ว',
        'ส่งโปรไฟล์เด็กแล้ว',
        'ส่งคำขอเสนอโครงการแล้ว',
    ];
    $inList = implode(',', array_map(static function (string $t) use ($conn): string {
        return "'" . $conn->real_escape_string($t) . "'";
    }, $legacyTitles));
    $chk = @$conn->query("SELECT 1 FROM notifications WHERE title IN ({$inList}) LIMIT 1");
    if (!$chk || $chk->num_rows === 0) {
        return;
    }
    @$conn->query("DELETE FROM notifications WHERE title IN ({$inList})");
}

/** ลบแจ้งเตือนทุกผู้รับที่ใช้ entity_key เดียวกัน (เช่น ล้าง “รออนุมัติ” ของแอดมินหลังตัดสินแล้ว) */
function drawdream_notifications_delete_by_entity_key(mysqli $conn, string $entityKey): void
{
    if ($entityKey === '') {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $st = $conn->prepare('DELETE FROM notifications WHERE entity_key = ?');
    if ($st) {
        $st->bind_param('s', $entityKey);
        @$st->execute();
    }
}

function drawdream_ensure_admin_notif_columns(mysqli $conn): void
{
    $c = @$conn->query("SHOW COLUMNS FROM `admin` WHERE Field = 'notif_recipient_user_id'");
    if ($c && $c->num_rows === 0) {
        @$conn->query("ALTER TABLE `admin` ADD COLUMN notif_recipient_user_id INT UNSIGNED NULL DEFAULT NULL AFTER remark");
    }
    $c2 = @$conn->query("SHOW COLUMNS FROM `admin` WHERE Field = 'notif_type'");
    if ($c2 && $c2->num_rows === 0) {
        @$conn->query("ALTER TABLE `admin` ADD COLUMN notif_type VARCHAR(64) NULL DEFAULT NULL AFTER notif_recipient_user_id");
    }
    // แถวเก่าที่บันทึกเป็นรหัสอังกฤษ — เก็บมาตรฐานเดียวกับ project_submitted / ประเภทรอดำเนินการ
    @$conn->query(
        "UPDATE `admin` SET notif_type = 'กำลังรอดำเนินการ' WHERE TRIM(COALESCE(notif_type,'')) IN ('child_submitted','project_submitted','need_submitted')"
    );
}

function drawdream_send_notification(
    mysqli $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    string $link = '',
    ?string $entityKey = null
): bool {
    if ($userId <= 0) {
        return false;
    }
    drawdream_ensure_notifications_table($conn);
    $typeTh = drawdream_normalize_notif_type_to_th($type);
    $key = ($entityKey !== null && $entityKey !== '') ? $entityKey : null;
    if ($key !== null) {
        $del = $conn->prepare('DELETE FROM notifications WHERE user_id = ? AND entity_key = ?');
        if ($del) {
            $del->bind_param('is', $userId, $key);
            $del->execute();
        }
        $stmt = $conn->prepare('INSERT INTO notifications (user_id, type, title, message, link, entity_key, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isssss', $userId, $typeTh, $title, $message, $link, $key);
        return $stmt->execute();
    }
    $stmt = $conn->prepare('INSERT INTO notifications (user_id, type, title, message, link, is_read) VALUES (?, ?, ?, ?, ?, 0)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('issss', $userId, $typeTh, $title, $message, $link);
    return $stmt->execute();
}

/** บันทึกลงตาราง admin ว่ามีโครงการถูกเสนอ (ไม่มีแอดมิน — admin_id เป็น NULL; ใช้ notif_type แทน action_type) */
function drawdream_record_foundation_submitted_project(
    mysqli $conn,
    int $foundationUserId,
    int $projectId,
    string $projectName
): void {
    drawdream_ensure_admin_notif_columns($conn);
    $entity = 'project';
    $remark = 'มูลนิธิ user_id ' . $foundationUserId . ' เสนอโครงการ: ' . $projectName;
    $notifType = drawdream_normalize_notif_type_to_th('project_submitted');
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (NULL, ?, ?, ?, NULL, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isss', $projectId, $entity, $remark, $notifType);
    @$stmt->execute();
}

/** มูลนิธิส่งโปรไฟล์เด็กใหม่ (บันทึกในตาราง admin สำหรับแอดมิน) */
function drawdream_record_foundation_submitted_child(
    mysqli $conn,
    int $foundationUserId,
    int $childId,
    string $childName
): void {
    drawdream_ensure_admin_notif_columns($conn);
    $entity = 'child';
    $remark = 'มูลนิธิ user_id ' . $foundationUserId . ' เสนอโปรไฟล์เด็ก: ' . $childName;
    $notifType = drawdream_normalize_notif_type_to_th('child_submitted');
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (NULL, ?, ?, ?, NULL, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isss', $childId, $entity, $remark, $notifType);
    @$stmt->execute();
}

/** แจ้งเตือนแอดมินทุกคนเมื่อมีโปรไฟล์เด็กรออนุมัติ */
function drawdream_notify_admins_child_submitted(
    mysqli $conn,
    int $childId,
    string $childName,
    string $foundationName
): void {
    if ($childId <= 0) {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $link = 'children_donate.php?id=' . $childId;
    foreach (drawdream_admin_user_ids($conn) as $adminUid) {
        if ($adminUid <= 0) {
            continue;
        }
        drawdream_send_notification(
            $conn,
            $adminUid,
            'child_submitted',
            'โปรไฟล์เด็กรออนุมัติ',
            'มูลนิธิ ' . $foundationName . ' เสนอโปรไฟล์เด็ก: ' . $childName,
            $link,
            'adm_pending_child:' . $childId
        );
    }
}

/** มูลนิธิเสนอรายการสิ่งของ — บันทึกตาราง admin (แอดมินดูคิว / ประวัติ) */
function drawdream_record_foundation_submitted_need(
    mysqli $conn,
    int $foundationUserId,
    int $itemId,
    string $itemSummary,
    float $goalAmount,
    string $foundationName,
    bool $isUrgent
): void {
    if ($itemId <= 0) {
        return;
    }
    drawdream_ensure_admin_notif_columns($conn);
    $entity = 'need';
    $urgentTag = $isUrgent ? ' [ต้องการด่วน]' : '';
    $fn = trim($foundationName) !== '' ? $foundationName : ('user_id ' . $foundationUserId);
    $remark = 'มูลนิธิ ' . $fn . ' (user_id ' . $foundationUserId . ') เสนอรายการสิ่งของ' . $urgentTag . ': ' . $itemSummary
        . ' | เป้าหมาย ' . number_format($goalAmount, 0) . ' บาท';
    $notifType = drawdream_normalize_notif_type_to_th('need_submitted');
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (NULL, ?, ?, ?, NULL, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isss', $itemId, $entity, $remark, $notifType);
    @$stmt->execute();
}

/** แจ้งเตือนแอดมินทุกคนเมื่อมีรายการสิ่งของรออนุมัติ */
function drawdream_notify_admins_need_submitted(
    mysqli $conn,
    int $itemId,
    string $itemSummary,
    string $foundationName,
    float $goalAmount,
    bool $isUrgent
): void {
    if ($itemId <= 0) {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $link = 'admin_approve_needlist.php';
    $urgentPart = $isUrgent ? ' (ต้องการด่วน)' : '';
    $fn = trim($foundationName) !== '' ? $foundationName : 'มูลนิธิ';
    $body = $fn . ' เสนอรายการสิ่งของ' . $urgentPart . ': ' . $itemSummary
        . ' — เป้าหมาย ' . number_format($goalAmount, 0) . ' บาท';
    foreach (drawdream_admin_user_ids($conn) as $adminUid) {
        if ($adminUid <= 0) {
            continue;
        }
        drawdream_send_notification(
            $conn,
            $adminUid,
            'need_submitted',
            'รายการสิ่งของรออนุมัติ',
            $body,
            $link,
            'adm_pending_need:' . $itemId
        );
    }
}

/** @return int[] */
function drawdream_admin_user_ids(mysqli $conn): array
{
    $r = @$conn->query("SELECT user_id FROM `user` WHERE LOWER(TRIM(COALESCE(role,''))) = 'admin'");
    if (!$r) {
        return [];
    }
    $out = [];
    while ($row = $r->fetch_assoc()) {
        $uid = (int)($row['user_id'] ?? 0);
        if ($uid > 0) {
            $out[] = $uid;
        }
    }
    return $out;
}

function drawdream_log_admin_action(
    mysqli $conn,
    int $adminId,
    string $actionType,
    int $targetId,
    string $remark,
    ?int $notifRecipientUserId = null,
    ?string $notifType = null
): bool {
    drawdream_ensure_admin_notif_columns($conn);
    $entity = drawdream_action_target_entity($actionType);
    if ($notifRecipientUserId !== null && $notifRecipientUserId > 0 && $notifType !== null && $notifType !== '') {
        // กรณีมีผู้รับการแจ้งเตือน + ประเภทแจ้งเตือน: ถือว่าเป็น action ต่อจากที่มูลนิธิเสนอเข้ามาแล้ว
        // พยายามอัปเดตแถวเดิมในตาราง admin (target เดียวกัน) แทนการสร้างแถวใหม่ซ้ำ
        $notifTypeTh = drawdream_normalize_notif_type_to_th($notifType);

        $existingId = 0;
        $existingRemark = '';
        $sel = $conn->prepare('SELECT id, remark FROM `admin` WHERE target_id = ? AND target_entity = ? ORDER BY id ASC LIMIT 1');
        if ($sel) {
            $sel->bind_param('is', $targetId, $entity);
            if ($sel->execute()) {
                $res = $sel->get_result();
                if ($row = $res->fetch_assoc()) {
                    $existingId = (int)($row['id'] ?? 0);
                    $existingRemark = (string)($row['remark'] ?? '');
                }
            }
        }

        if ($existingId > 0) {
            // รวมข้อความ remark เดิม + ใหม่ เพื่อเก็บเป็นเหตุการณ์เดียวกัน
            $mergedRemark = trim($existingRemark);
            $extra = trim($remark);
            if ($extra !== '') {
                $mergedRemark = $mergedRemark !== '' ? ($mergedRemark . ' | ' . $extra) : $extra;
            }

            $up = $conn->prepare(
                'UPDATE `admin` SET admin_id = ?, remark = ?, notif_recipient_user_id = ?, notif_type = ?, action_at = NOW() WHERE id = ?'
            );
            if (!$up) {
                return false;
            }
            $up->bind_param('isisi', $adminId, $mergedRemark, $notifRecipientUserId, $notifTypeTh, $existingId);
            return $up->execute();
        }

        // ถ้าไม่พบแถวเดิม (เช่น ข้อมูลเก่าหรือเคยลบไป) ค่อย fallback ไปสร้างแถวใหม่
        $stmt = $conn->prepare(
            'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iissis', $adminId, $targetId, $entity, $remark, $notifRecipientUserId, $notifTypeTh);
        return $stmt->execute();
    }
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (?, ?, ?, ?, NULL, NULL)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiss', $adminId, $targetId, $entity, $remark);
    return $stmt->execute();
}

function drawdream_foundation_user_id_by_name(mysqli $conn, string $foundationName): int
{
    $foundationName = trim($foundationName);
    if ($foundationName === '') {
        return 0;
    }
    $st = $conn->prepare('SELECT user_id FROM foundation_profile WHERE foundation_name = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $foundationName);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)($row['user_id'] ?? 0);
}

function drawdream_foundation_user_id_by_foundation_id(mysqli $conn, int $foundationId): int
{
    if ($foundationId <= 0) {
        return 0;
    }
    $st = $conn->prepare('SELECT user_id FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)($row['user_id'] ?? 0);
}

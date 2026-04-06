<?php
// includes/qr_payment_abandon.php — ล้าง session QR payment ค้าง
// ยกเลิก QR ที่ยังไม่ชำระ: ลบ payment_transaction (และแถว donation แบบ pending ถ้ามีจากเวอร์ชันเก่า)

declare(strict_types=1);

require_once __DIR__ . '/payment_transaction_schema.php';

/**
 * ลบค่า session ที่ผูกกับหน้าสแกน QR (โครงการ / เด็ก / มูลนิธิ-สิ่งของ)
 */
function drawdream_clear_pending_payment_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $sessionKeys = [
        'pending_charge_id',
        'pending_amount',
        'pending_donate_id',
        'qr_image',
        'pending_project',
        'pending_project_id',
        'pending_child_id',
        'pending_child_name',
        'pending_foundation',
        'pending_foundation_id',
    ];
    foreach ($sessionKeys as $k) {
        unset($_SESSION[$k]);
    }
}

/**
 * ยกเลิกรายการตาม Omise charge_id (ต้องเป็นของ donor คนนี้และยัง pending)
 *
 * @return int 1 ถ้าลบ/ยกเลิกสำเร็จ, 0 ถ้าไม่พบหรือไม่ใช่ของผู้ใช้
 */
function drawdream_abandon_pending_donation_by_charge(mysqli $conn, int $donorUserId, string $chargeId): int
{
    $chargeId = trim($chargeId);
    if ($donorUserId <= 0 || $chargeId === '') {
        return 0;
    }
    drawdream_payment_transaction_ensure_schema($conn);

    $st = $conn->prepare(
        'SELECT log_id, donate_id, pending_donor_user_id FROM payment_transaction
         WHERE omise_charge_id = ? AND transaction_status = ? LIMIT 1'
    );
    $pend = 'pending';
    $st->bind_param('ss', $chargeId, $pend);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return 0;
    }

    $logId = (int)$row['log_id'];
    $donateId = isset($row['donate_id']) && $row['donate_id'] !== null ? (int)$row['donate_id'] : 0;
    $pDonor = isset($row['pending_donor_user_id']) && $row['pending_donor_user_id'] !== null
        ? (int)$row['pending_donor_user_id'] : 0;

    if ($donateId <= 0) {
        if ($pDonor !== $donorUserId) {
            return 0;
        }
        $del = $conn->prepare('DELETE FROM payment_transaction WHERE log_id = ?');
        $del->bind_param('i', $logId);
        $del->execute();

        return $del->affected_rows > 0 ? 1 : 0;
    }

    $chk = $conn->prepare(
        'SELECT donate_id FROM donation WHERE donate_id = ? AND donor_id = ? AND payment_status = ? LIMIT 1'
    );
    $ps = 'pending';
    $chk->bind_param('iis', $donateId, $donorUserId, $ps);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) {
        return 0;
    }

    if (!$conn->begin_transaction()) {
        return 0;
    }
    try {
        $delPt = $conn->prepare('DELETE FROM payment_transaction WHERE log_id = ?');
        $delPt->bind_param('i', $logId);
        $delPt->execute();
        $delD = $conn->prepare('DELETE FROM donation WHERE donate_id = ? AND payment_status = ?');
        $delD->bind_param('is', $donateId, $ps);
        $delD->execute();
        $conn->commit();

        return 1;
    } catch (Throwable $e) {
        $conn->rollback();

        return 0;
    }
}

/**
 * ก่อนสร้าง QR ชุดใหม่: ปิดรายการ pending ทั้งหมดของผู้บริจาคคนนี้
 */
function drawdream_abandon_all_pending_qr_for_donor(mysqli $conn, int $donorUserId): int
{
    if ($donorUserId <= 0) {
        return 0;
    }
    drawdream_payment_transaction_ensure_schema($conn);

    $n = 0;

    $st1 = $conn->prepare(
        'DELETE FROM payment_transaction
         WHERE transaction_status = ? AND pending_donor_user_id = ? AND donate_id IS NULL'
    );
    $pend = 'pending';
    $st1->bind_param('si', $pend, $donorUserId);
    $st1->execute();
    $n += $st1->affected_rows;

    $st2 = $conn->prepare(
        'SELECT pt.log_id, pt.donate_id FROM payment_transaction pt
         INNER JOIN donation d ON d.donate_id = pt.donate_id
         WHERE pt.transaction_status = ? AND d.payment_status = ? AND d.donor_id = ?'
    );
    $st2->bind_param('ssi', $pend, $pend, $donorUserId);
    $st2->execute();
    $res = $st2->get_result();
    while ($r = $res->fetch_assoc()) {
        $lid = (int)$r['log_id'];
        $did = (int)$r['donate_id'];
        if (!$conn->begin_transaction()) {
            continue;
        }
        try {
            $dp = $conn->prepare('DELETE FROM payment_transaction WHERE log_id = ?');
            $dp->bind_param('i', $lid);
            $dp->execute();
            $dd = $conn->prepare('DELETE FROM donation WHERE donate_id = ? AND payment_status = ?');
            $dd->bind_param('is', $did, $pend);
            $dd->execute();
            $conn->commit();
            ++$n;
        } catch (Throwable $e) {
            $conn->rollback();
        }
    }

    return $n;
}

/**
 * คืน URL กลับหลังยกเลิก — อนุญาตเฉพาะ path ภายในไซต์ (กัน open redirect)
 */
function drawdream_safe_payment_return_url(string $raw, string $fallback): string
{
    $t = trim($raw);
    if ($t === '') {
        return $fallback;
    }
    if (preg_match('#^[a-z][a-z0-9+\-.]*:#i', $t) !== 0) {
        return $fallback;
    }
    if (strpbrk($t, "\r\n\t\x00") !== false) {
        return $fallback;
    }
    if (!preg_match('#^(?:\.\./)+[a-zA-Z0-9_./?=&\-#]+$#', $t) && !preg_match('#^[a-zA-Z0-9_./?=&\-#]+$#', $t)) {
        return $fallback;
    }

    return $t;
}

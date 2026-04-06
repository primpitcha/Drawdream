<?php
// payment/check_needlist_payment.php — ยืนยันการชำระรายการสิ่งของ

if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/donate_category_resolve.php';
require_once __DIR__ . '/../includes/needlist_donate_window.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$charge_id = $_GET['charge_id'] ?? '';
$fid       = (int)($_GET['fid'] ?? 0);

if (empty($charge_id)) {
    header("Location: ../foundation.php");
    exit();
}

// ตรวจสอบว่าเป็น mock charge (สร้างโดย _omise_local_mock เมื่อ API ไม่ตอบสนอง)
$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge  = [];

if ($is_mock) {
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => ($_SESSION['pending_amount'] ?? 0) * 100,
        'metadata' => ['foundation_id' => (int)($_SESSION['pending_foundation_id'] ?? $fid)],
    ];
} else {
    $ch = curl_init(OMISE_API_URL . '/charges/' . $charge_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    curl_close($ch);
    $charge = json_decode($response, true) ?? [];
}

// fallback fid จาก metadata/session หากไม่มีใน URL
if ($fid <= 0) {
    $fid = (int)($charge['metadata']['foundation_id'] ?? ($_SESSION['pending_foundation_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$amount          = 0;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);

$is_success = ($paid === true) || ($status === 'successful') || $is_mock;

if (!$is_mock && !$is_success && in_array($status, ['failed', 'expired'], true)) {
    drawdream_clear_pending_payment_session();
}

// กันบันทึกซ้ำ
$already_processed = false;
$dup = $conn->prepare("SELECT log_id FROM payment_transaction WHERE omise_charge_id = ? LIMIT 1");
$dup->bind_param("s", $charge_id);
$dup->execute();
$already_processed = (bool)$dup->get_result()->fetch_assoc();

if ($is_success && !$already_processed && $fid > 0) {
    $amount = ($charge['amount'] ?? 0) / 100;

    $tax_id = '';
    $stmt = $conn->prepare("SELECT tax_id FROM donor WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $donor  = $stmt->get_result()->fetch_assoc();
    $tax_id = $donor['tax_id'] ?? '';

    $category_id = drawdream_get_or_create_needitem_donate_category_id($conn);
    if ($category_id <= 0) {
        $is_success = false;
    }

    $donor_uid = (int)$_SESSION['user_id'];
    if ($is_success) {
    $stmt = $conn->prepare("
        INSERT INTO donation (category_id, target_id, donor_id, amount, service_fee, payment_status, transfer_datetime)
        VALUES (?, ?, ?, ?, 0, 'completed', NOW())
    ");
    $stmt->bind_param("iiid", $category_id, $fid, $donor_uid, $amount);
    $stmt->execute();
    $donate_id = $conn->insert_id;

    $stmt = $conn->prepare("
        INSERT INTO payment_transaction (donate_id, tax_id, omise_charge_id, transaction_status)
        VALUES (?, ?, ?, 'completed')
    ");
    $stmt->bind_param("iss", $donate_id, $tax_id, $charge_id);
    $stmt->execute();

    $needOpen = drawdream_needlist_sql_open_for_donation();
    $res = $conn->prepare("
        SELECT SUM(total_price) AS grand_total
        FROM foundation_needlist
        WHERE foundation_id = ? AND $needOpen
    ");
    $res->bind_param("i", $fid);
    $res->execute();
    $grand = $res->get_result()->fetch_assoc();
    $grand_total = (float)($grand['grand_total'] ?? 0);

    if ($grand_total > 0) {
        $items = $conn->prepare("
            SELECT item_id, total_price
            FROM foundation_needlist
            WHERE foundation_id = ? AND $needOpen
        ");
        $items->bind_param("i", $fid);
        $items->execute();
        $item_rows = $items->get_result();

        while ($item = $item_rows->fetch_assoc()) {
            $ratio       = (float)$item['total_price'] / $grand_total;
            $item_amount = round($amount * $ratio, 2);

            $upd = $conn->prepare("
                UPDATE foundation_needlist
                SET current_donate = current_donate + ?
                WHERE item_id = ?
            ");
            $upd->bind_param("di", $item_amount, $item['item_id']);
            $upd->execute();
        }
    }

    unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_foundation'], $_SESSION['pending_foundation_id']);
    }
}

// ถ้าเคยประมวลผลแล้ว ให้ดึงจำนวนเงินจาก charge
if ($already_processed) {
    $is_success = true;
    $amount     = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
if ($is_success && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลการชำระเงิน | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
</head>
<body>

<?php include '../navbar.php'; ?>

<div class="payment-container">
    <div class="result-box">

        <?php if ($is_success): ?>
            <div class="result-icon success">✓</div>
            <h2>ชำระเงินสำเร็จ!</h2>
            <p>ขอบคุณที่ร่วมบริจาครายการสิ่งของ</p>
            <p>จำนวน <strong><?= number_format($amount, 2) ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?= htmlspecialchars($charge_id) ?></p>
            <a href="../foundation.php" class="btn-pay" style="background:#CC583F; border:none; width:100%; max-width:400px; margin:32px auto 0 auto; display:block; font-size:1.3rem;">กลับหน้ามูลนิธิ</a>

        <?php elseif ($status === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>ยังไม่พบการโอนจากธนาคาร</h2>
            <p>ถ้าโอนแล้วให้รอสักครู่แล้วกด «เช็คอีกครั้ง» หาก<strong>ยังไม่ได้โอน</strong>กด «ยกเลิก» เพื่อล้าง QR และกลับไปหน้าบริจาคได้ใหม่</p>
            <?php if ($is_test_mode): ?>
                <p style="color:#a16207;">ระบบกำลังใช้ Omise Test Key (โหมดทดสอบ)</p>
            <?php endif; ?>
            <?php if (!empty($expires_at)): ?>
                <p>QR หมดอายุ: <?= htmlspecialchars($expires_at) ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?= htmlspecialchars($charge_id) ?> | Status: <?= htmlspecialchars($status) ?></p>
            <a href="check_needlist_payment.php?charge_id=<?= urlencode($charge_id) ?>&fid=<?= $fid ?>"
               class="btn-pay">เช็คอีกครั้ง</a>
            <form method="post" action="abandon_qr.php" style="margin:16px 0 0 0;">
                <input type="hidden" name="charge_id" value="">
                <input type="hidden" name="return_url" value="foundation_donate.php?fid=<?= (int)$fid ?>">
                <button type="submit" class="btn-back" style="width:100%;max-width:400px;border:1px solid #b91c1c;color:#b91c1c;background:#fff;cursor:pointer;padding:12px;border-radius:8px;font-weight:600;">
                    ยกเลิก (ยังไม่ได้โอน)
                </button>
            </form>
            <a href="../foundation.php" class="btn-back">กลับหน้ามูลนิธิ</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?= htmlspecialchars($status) ?></p>
            <?php if (!empty($failure_code)): ?>
                <p>รหัสข้อผิดพลาด: <?= htmlspecialchars($failure_code) ?></p>
                <p>รายละเอียด: <?= htmlspecialchars($failure_message) ?></p>
            <?php endif; ?>
            <button type="button" class="btn-pay" onclick="window.location.reload()">ลองใหม่</button>
            <a href="../foundation.php" class="btn-back">กลับหน้ามูลนิธิ</a>
        <?php endif; ?>

</div>
</div>

</body>
</html>
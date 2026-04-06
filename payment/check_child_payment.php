<?php
// payment/check_child_payment.php — ยืนยันการชำระบริจาคเด็ก

if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/pending_child_donation.php';
require_once dirname(__DIR__) . '/includes/qr_payment_abandon.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$charge_id = $_GET['charge_id'] ?? '';
$child_id  = (int)($_GET['child_id'] ?? 0);

if (empty($charge_id)) {
    header("Location: ../children_.php");
    exit();
}

// ตรวจสอบว่าเป็น mock charge (สร้างโดย _omise_local_mock เมื่อ local dev)
$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge  = [];

if ($is_mock) {
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => ($_SESSION['pending_amount'] ?? 0) * 100,
        'metadata' => ['child_id' => (int)($_SESSION['pending_child_id'] ?? $child_id)],
    ];
} else {
    $ch = curl_init(rtrim(OMISE_API_URL, '/') . '/charges/' . rawurlencode($charge_id) . '?expand[]=source');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $charge = json_decode($response, true) ?? [];
}

// fallback child_id จาก metadata/session หากไม่มีใน URL
if ($child_id <= 0) {
    $child_id = (int)($charge['metadata']['child_id'] ?? ($_SESSION['pending_child_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);

$is_success = ($paid === true) || ($status === 'successful') || $is_mock;
$amount     = 0;

$ptRow = null;
$dup = $conn->prepare('SELECT log_id, donate_id, transaction_status FROM payment_transaction WHERE omise_charge_id = ? LIMIT 1');
$dup->bind_param('s', $charge_id);
$dup->execute();
$ptRow = $dup->get_result()->fetch_assoc();
$already_completed = is_array($ptRow) && (($ptRow['transaction_status'] ?? '') === 'completed');
$has_pending       = is_array($ptRow) && (($ptRow['transaction_status'] ?? '') === 'pending');

$donor_uid = (int)$_SESSION['user_id'];

if (!$is_mock && $has_pending && !$already_completed && !$is_success
    && in_array($status, ['failed', 'expired'], true)) {
    drawdream_abandon_pending_donation_by_charge($conn, $donor_uid, $charge_id);
    drawdream_clear_pending_payment_session();
    $has_pending = false;
    if (is_array($ptRow)) {
        $ptRow['transaction_status'] = 'failed';
    }
}

$finalized_this_request = false;

if ($is_success && $has_pending && !$already_completed && $child_id > 0) {
    $amount = ($charge['amount'] ?? 0) / 100;
    if ($amount <= 0) {
        $amount = (float)($_SESSION['pending_amount'] ?? 0);
    }
    $donate_id_from_pt = (int)($ptRow['donate_id'] ?? 0);
    if (drawdream_finalize_child_donation($conn, $child_id, $donate_id_from_pt, $charge_id, (float)$amount, $donor_uid)) {
        $finalized_this_request = true;
        unset(
            $_SESSION['pending_charge_id'],
            $_SESSION['pending_amount'],
            $_SESSION['pending_child_id'],
            $_SESSION['pending_child_name'],
            $_SESSION['pending_donate_id'],
            $_SESSION['qr_image']
        );
    } else {
        $is_success = false;
        $failure_message = 'ชำระเงินสำเร็จแล้ว แต่ระบบบันทึกรายการไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบพร้อมอ้างอิง Charge';
    }
} elseif ($is_success && !$ptRow && $child_id > 0) {
    // เส้นทางเก่า: สร้าง charge ก่อนมีแถว pending ในฐานข้อมูล
    $amount = ($charge['amount'] ?? 0) / 100;
    if ($amount <= 0) {
        $amount = (float)($_SESSION['pending_amount'] ?? 0);
    }

    $tax_id = '';
    $stmt = $conn->prepare('SELECT tax_id FROM donor WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $donor_row = $stmt->get_result()->fetch_assoc();
    $tax_id = $donor_row['tax_id'] ?? '';

    $category_id = drawdream_get_or_create_child_donate_category_id($conn);
    $service_fee = 0.0;
    $donor_id = (int)$_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('
            INSERT INTO donation (category_id, target_id, donor_id, amount, service_fee, payment_status, transfer_datetime)
            VALUES (?, ?, ?, ?, ?, \'completed\', NOW())
        ');
        $stmt->bind_param('iiidd', $category_id, $child_id, $donor_id, $amount, $service_fee);
        $stmt->execute();
        $donate_id = (int)$conn->insert_id;

        $stmt = $conn->prepare('
            INSERT INTO payment_transaction (donate_id, tax_id, omise_charge_id, transaction_status)
            VALUES (?, ?, ?, \'completed\')
        ');
        $stmt->bind_param('iss', $donate_id, $tax_id, $charge_id);
        $stmt->execute();

        $conn->query(
            "CREATE TABLE IF NOT EXISTS child_donations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_id INT NOT NULL,
                donor_user_id INT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(child_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmt = $conn->prepare('
            INSERT INTO child_donations (child_id, donor_user_id, amount)
            VALUES (?, ?, ?)
        ');
        $stmt->bind_param('iid', $child_id, $_SESSION['user_id'], $amount);
        $stmt->execute();

        drawdream_child_sync_sponsorship_status($conn, $child_id);

        $conn->commit();
        $finalized_this_request = true;
        unset(
            $_SESSION['pending_charge_id'],
            $_SESSION['pending_amount'],
            $_SESSION['pending_child_id'],
            $_SESSION['pending_child_name'],
            $_SESSION['pending_donate_id'],
            $_SESSION['qr_image']
        );
    } catch (Exception $e) {
        $conn->rollback();
        $is_success = false;
        $failure_message = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาติดต่อผู้ดูแลระบบ';
    }
}

$already_processed_display = $finalized_this_request
    || $already_completed
    || ($is_success && is_array($ptRow) && ($ptRow['transaction_status'] ?? '') === 'completed');

if ($already_processed_display && !$finalized_this_request) {
    $is_success = true;
    $amount     = ($charge['amount'] ?? 0) / 100;
    if ($amount <= 0) {
        $amount = (float)($_SESSION['pending_amount'] ?? 0);
    }
    if ($amount <= 0 && is_array($ptRow)) {
        $did = (int)($ptRow['donate_id'] ?? 0);
        if ($did > 0) {
            $qa = $conn->prepare('SELECT amount FROM donation WHERE donate_id = ? LIMIT 1');
            $qa->bind_param('i', $did);
            $qa->execute();
            $ar = $qa->get_result()->fetch_assoc();
            if ($ar) {
                $amount = (float)($ar['amount'] ?? 0);
            }
        }
    }
}
if ($is_success && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}

// ดึงชื่อเด็กเพื่อแสดงผล
$child_name = $_SESSION['pending_child_name'] ?? '';
if (empty($child_name) && $child_id > 0) {
    $stmtN = $conn->prepare("SELECT child_name FROM foundation_children WHERE child_id = ? LIMIT 1");
    $stmtN->bind_param("i", $child_id);
    $stmtN->execute();
    $childRow = $stmtN->get_result()->fetch_assoc();
    $child_name = $childRow['child_name'] ?? '';
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
            <?php if (!empty($child_name)): ?>
                <p>ขอบคุณที่ร่วมบริจาคให้ <strong><?php echo htmlspecialchars($child_name); ?></strong></p>
            <?php else: ?>
                <p>ขอบคุณที่ร่วมบริจาคให้เด็กรายบุคคล</p>
            <?php endif; ?>
            <p>จำนวน <strong><?php echo number_format($amount, 2); ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?php echo htmlspecialchars($charge_id); ?></p>

            <!-- ปุ่มเดียว: กลับหน้าโปรไฟล์เด็ก (สี #CC583F) -->
            <a href="../children_.php" class="btn-pay" style="background:#CC583F; border:none; width:100%; max-width:400px; margin:32px auto 0 auto; display:block; font-size:1.3rem;">กลับหน้าโปรไฟล์เด็ก</a>

        <?php elseif ($status === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>ยังไม่พบการโอนจากธนาคาร</h2>
            <p>ถ้าคุณสแกนจ่ายแล้ว อาจต้องรอสักครู่แล้วกด «เช็คอีกครั้ง» หาก<strong>ยังไม่ได้โอนจริง</strong>กด «ยกเลิกรายการนี้» — ระบบจะไม่เก็บสถานะค้างรอ และคุณสามารถกดบริจาคใหม่ได้</p>
            <?php if ($is_test_mode): ?>
                <p style="color:#a16207;">ระบบกำลังใช้ Omise Test Key (โหมดทดสอบ) การสแกนจ่ายจริงอาจไม่เปลี่ยนสถานะเป็นสำเร็จ — ใน Dashboard ให้เปิดรายการนี้แล้วใช้ <strong>Mark as paid</strong></p>
                <?php $omise_charge_test_url = 'https://dashboard.omise.co/test/charges/' . rawurlencode($charge_id); ?>
                <p><a href="<?php echo htmlspecialchars($omise_charge_test_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">เปิด charge นี้ใน Omise Dashboard (test)</a></p>
            <?php endif; ?>
            <?php if (!empty($expires_at)): ?>
                <p>QR หมดอายุ: <?php echo htmlspecialchars($expires_at); ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?php echo htmlspecialchars($charge_id); ?> | Status: <?php echo htmlspecialchars($status); ?> | Paid: <?php echo $paid ? 'true' : 'false'; ?></p>
            <a href="check_child_payment.php?charge_id=<?php echo urlencode($charge_id); ?>&child_id=<?php echo $child_id; ?>"
               class="btn-pay">เช็คอีกครั้ง</a>
            <form method="post" action="abandon_qr.php" style="margin:16px 0 0 0;">
                <input type="hidden" name="charge_id" value="<?php echo htmlspecialchars($charge_id, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="return_url" value="../children_.php">
                <button type="submit" class="btn-back" style="width:100%;max-width:400px;border:1px solid #b91c1c;color:#b91c1c;background:#fff;cursor:pointer;padding:12px;border-radius:8px;font-weight:600;">
                    ยกเลิกรายการนี้ (ยังไม่ได้โอน)
                </button>
            </form>
            <a href="../children_.php" class="btn-back">กลับหน้ารายชื่อเด็ก</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?php echo htmlspecialchars($status); ?></p>
            <?php if (!empty($failure_code)): ?>
                <p>รหัสข้อผิดพลาด: <?php echo htmlspecialchars($failure_code); ?></p>
                <p>รายละเอียด: <?php echo htmlspecialchars($failure_message); ?></p>
            <?php elseif (!empty($failure_message)): ?>
                <p><?php echo htmlspecialchars($failure_message); ?></p>
            <?php endif; ?>
            <button type="button" class="btn-pay" onclick="window.location.reload()">ลองใหม่</button>
            <a href="../children_.php" class="btn-back">กลับหน้ารายชื่อเด็ก</a>
        <?php endif; ?>

    </div>
</div>

</body>
</html>

<?php
// payment/scan_qr.php — หน้าแสดง QR หลังสร้าง charge (ร่วมทุกประเภท)
/**
 * หน้าแสดง QR หลังสร้าง Omise charge (โครงการ / เด็ก / มูลนิธิ)
 *
 * ตรวจสอบ charge_id + session (pending_*) ให้ตรงกันก่อนแสดง — กันป้อน URL ข้ามคน
 * ภาพ QR ควรมาจาก Omise (session / GET charge) — ไม่ใช้ภาพจำลองในโหมดทดสอบยกเว้นชาร์จ mock (OMISE_ALLOW_LOCAL_MOCK)
 */
session_start();
include '../db.php';
include 'config.php';

$type = $_GET['type'] ?? 'project';
if (!in_array($type, ['project', 'child', 'foundation'], true)) {
    $type = 'project';
}

$charge_id = trim((string)($_GET['charge_id'] ?? ($_SESSION['pending_charge_id'] ?? '')));
$amount = (int)($_SESSION['pending_amount'] ?? 0);

$qr_fail = function (string $url) use ($type): void {
    if ($type === 'child') {
        header('Location: ../children_.php');
    } elseif ($type === 'foundation') {
        header('Location: ../foundation.php');
    } else {
        header('Location: ' . $url);
    }
    exit;
};

if ($charge_id === '' || $amount < 20) {
    $qr_fail('../project.php');
}
if (!isset($_SESSION['pending_charge_id']) || $_SESSION['pending_charge_id'] !== $charge_id) {
    $qr_fail('../project.php');
}

$project_id = 0;
$child_id = 0;
$fid = 0;
$project_name = '';
$child_name = '';
$foundation_name = '';
$return_payment_page = 'payment_project.php';
$receipt_target_label = '';
$receipt_target_value = '';
$subtitle_line = '';
$page_title = 'Scan QR Code เพื่อบริจาค';
$back_aria = 'กลับไปหน้าชำระเงิน';

if ($type === 'project') {
    $project_id = (int)($_GET['project_id'] ?? ($_SESSION['pending_project_id'] ?? 0));
    if ($project_id <= 0 || (int)($_SESSION['pending_project_id'] ?? 0) !== $project_id) {
        $qr_fail('../project.php');
    }
    $project_name = trim((string)($_SESSION['pending_project'] ?? ''));
    if ($project_name === '') {
        $st = $conn->prepare('SELECT project_name FROM foundation_project WHERE project_id = ? AND deleted_at IS NULL LIMIT 1');
        if ($st) {
            $st->bind_param('i', $project_id);
            $st->execute();
            $pn = $st->get_result()->fetch_assoc();
            if ($pn) {
                $project_name = (string)($pn['project_name'] ?? '');
            }
        }
    }
    $subtitle_line = 'บริจาคให้กับโครงการ ' . $project_name;
    $receipt_target_label = 'ชื่อโครงการ';
    $receipt_target_value = $project_name;
    $return_payment_page = 'payment_project.php?project_id=' . $project_id;
    $confirm_href = 'check_project_payment.php?charge_id=' . urlencode($charge_id) . '&project_id=' . $project_id;
    $back_aria = 'กลับไปหน้าชำระเงินโครงการ';
} elseif ($type === 'child') {
    $child_id = (int)($_GET['child_id'] ?? ($_SESSION['pending_child_id'] ?? 0));
    if ($child_id <= 0 || (int)($_SESSION['pending_child_id'] ?? 0) !== $child_id) {
        header('Location: ../children_.php');
        exit;
    }
    $child_name = trim((string)($_SESSION['pending_child_name'] ?? ''));
    if ($child_name === '') {
        $st = $conn->prepare('SELECT child_name FROM foundation_children WHERE child_id = ? AND deleted_at IS NULL LIMIT 1');
        if ($st) {
            $st->bind_param('i', $child_id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if ($row) {
                $child_name = (string)($row['child_name'] ?? '');
            }
        }
    }
    $subtitle_line = 'บริจาคให้เด็ก ' . $child_name;
    $receipt_target_label = 'ชื่อเด็ก';
    $receipt_target_value = $child_name;
    $return_payment_page = '../children_donate.php?id=' . $child_id;
    $confirm_href = 'check_child_payment.php?charge_id=' . urlencode($charge_id) . '&child_id=' . $child_id;
    $back_aria = 'กลับไปหน้าโปรไฟล์เด็ก';
} else {
    $fid = (int)($_GET['fid'] ?? ($_SESSION['pending_foundation_id'] ?? 0));
    if ($fid <= 0 || (int)($_SESSION['pending_foundation_id'] ?? 0) !== $fid) {
        header('Location: ../foundation.php');
        exit;
    }
    $foundation_name = trim((string)($_SESSION['pending_foundation'] ?? ''));
    if ($foundation_name === '') {
        $st = $conn->prepare('SELECT foundation_name FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $fid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if ($row) {
                $foundation_name = (string)($row['foundation_name'] ?? '');
            }
        }
    }
    $subtitle_line = 'บริจาครายการสิ่งของ — ' . $foundation_name;
    $receipt_target_label = 'มูลนิธิ';
    $receipt_target_value = $foundation_name;
    $return_payment_page = 'foundation_donate.php?fid=' . $fid;
    $confirm_href = 'check_needlist_payment.php?charge_id=' . urlencode($charge_id) . '&fid=' . $fid;
    $back_aria = 'กลับไปหน้าบริจาคมูลนิธิ';
}

$qr_image = '';
$qr_missing = false;
$is_test_mode = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);
if ($_SESSION['pending_charge_id'] === $charge_id) {
    $qr_image = trim((string)($_SESSION['qr_image'] ?? ''));
}
$is_mock_charge = (strpos($charge_id, 'chrg_mock_') === 0);
if ($qr_image === '' && !$is_mock_charge) {
    require_once __DIR__ . '/omise_helpers.php';
    $fetched = drawdream_omise_fetch_charge($charge_id);
    if ($fetched) {
        $qr_image = drawdream_omise_promptpay_qr_uri_from_charge($fetched);
        if ($qr_image !== '' && $_SESSION['pending_charge_id'] === $charge_id) {
            $_SESSION['qr_image'] = $qr_image;
        }
    }
}
$qr_missing = ($qr_image === '');

$receipt_no = strtoupper(substr($charge_id, -10));
$abandon_charge = ($type === 'foundation') ? '' : $charge_id;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="../css/payment.css">
    <style>
        body { background: #f7ecde; }
        .qr-main { max-width: 520px; margin: 36px auto; background: #fff; border-radius: 18px; box-shadow: 0 2px 16px 0 rgba(0,0,0,0.07); padding: 32px 28px 28px 28px; }
        .qr-header-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 18px; }
        .qr-header-spacer { width: 44px; height: 44px; flex-shrink: 0; }
        .qr-title { flex: 1; font-size: 2em; font-weight: 700; text-align: center; margin: 0; line-height: 1.2; }
        .qr-back-icon {
            flex-shrink: 0; width: 44px; height: 44px; border-radius: 50%; background: #f0f2fa; color: #3C5099;
            display: flex; align-items: center; justify-content: center; text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .qr-back-icon:hover { background: #e2e6f5; color: #2d4580; }
        .qr-back-icon svg { display: block; }
        .qr-amount-bar { background: #f0f2fa; border-radius: 10px; padding: 16px 0 12px 0; font-size: 1.5em; color: #3C5099; font-weight: 700; text-align: center; margin-bottom: 18px; }
        .qr-project { display: flex; align-items: center; gap: 16px; margin-bottom: 12px; }
        .qr-project-info { font-size: 1.1em; }
        .qr-section { text-align: center; margin: 24px 0 18px 0; }
        .qr-section img { max-width: 260px; width: 100%; background: #fff; padding: 16px; border-radius: 16px; box-shadow: 0 2px 12px 0 rgba(0,0,0,0.08); }
        .qr-receipt { background: #f7f7f7; border-radius: 12px; padding: 18px 18px 10px 18px; margin-top: 18px; font-size: 1.08em; }
        .qr-receipt-row { margin-bottom: 8px; }
        .qr-download-btn { margin: 18px auto 0 auto; display: block; background: #3C5099; color: #fff; font-size: 1.15em; font-weight: 700; border: none; border-radius: 10px; padding: 14px 0; width: 100%; max-width: none; box-sizing: border-box; cursor: pointer; transition: background 0.15s; }
        .qr-download-btn:hover { background: #2d4580; }
        .qr-abandon-wrap { margin-top: 14px; }
        .qr-abandon-btn {
            width: 100%; margin: 0; display: block; box-sizing: border-box;
            border: none; background: #CE573F; color: #fff;
            cursor: pointer; padding: 14px 0; border-radius: 10px; font-weight: 700; font-size: 1.18em;
            transition: filter 0.15s, background 0.15s;
        }
        .qr-abandon-btn:hover { filter: brightness(0.94); }
    </style>
</head>
<body>
    <div class="qr-main">
        <div class="qr-header-row">
            <a class="qr-back-icon"
               href="<?= htmlspecialchars($return_payment_page, ENT_QUOTES, 'UTF-8') ?>"
               title="กลับ"
               aria-label="<?= htmlspecialchars($back_aria, ENT_QUOTES, 'UTF-8') ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <div class="qr-title"><?= htmlspecialchars($page_title) ?></div>
            <div class="qr-header-spacer" aria-hidden="true"></div>
        </div>
        <div class="qr-amount-bar">ยอดบริจาค <?= number_format($amount) ?> บาท</div>
        <div class="qr-project">
            <span class="qr-project-info" style="font-size:1.1em;font-weight:600;display:inline-block;"><?= htmlspecialchars($subtitle_line) ?></span>
        </div>
        <div class="qr-section">
            <?php if ($qr_missing): ?>
                <p style="color:#b45309;text-align:left;line-height:1.5;padding:12px;background:#fffbeb;border-radius:12px;border:1px solid #fcd34d;">
                    ไม่สามารถโหลดภาพ QR จาก Omise ได้ (อาจเป็นเครือข่ายหรือคีย์ API)<br>
                    ลองกลับไปหน้าชำระเงินแล้วกดบริจาคใหม่ หรือเปิด
                    <a href="https://dashboard.omise.co/test/charges" target="_blank" rel="noopener">Omise Dashboard (test)</a>
                    ค้นหา charge <code style="word-break:break-all;"><?= htmlspecialchars($charge_id) ?></code>
                    แล้วใช้ «Mark as paid» หลังทดสอบ
                </p>
            <?php else: ?>
                <img src="<?= htmlspecialchars($qr_image, ENT_QUOTES, 'UTF-8') ?>" alt="PromptPay QR">
            <?php endif; ?>
        </div>
        <div class="qr-receipt">
            <div class="qr-receipt-row"><b>จำนวนเงิน</b> <?= number_format($amount, 2) ?> บาท</div>
            <div class="qr-receipt-row"><b>เลขที่รายการบริจาค</b> <?= htmlspecialchars($receipt_no) ?></div>
            <div class="qr-receipt-row"><b><?= htmlspecialchars($receipt_target_label) ?></b> <?= htmlspecialchars($receipt_target_value) ?></div>
            <div class="qr-receipt-row"><b>วันที่</b> <?= date('d/m/Y H:i') ?></div>
        </div>
        <a class="qr-download-btn"
           href="<?= htmlspecialchars($confirm_href, ENT_QUOTES, 'UTF-8') ?>"
           style="background:#F1CF54;color:#222;display:flex;align-items:center;justify-content:center;font-size:1.18em;text-decoration:none;">
            ยืนยันการชำระ
        </a>
        <form method="post" action="abandon_qr.php" class="qr-abandon-wrap">
            <input type="hidden" name="charge_id" value="<?= htmlspecialchars($abandon_charge, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="qr-abandon-btn">ยกเลิกการชำระ</button>
        </form>
    </div>
</body>
</html>

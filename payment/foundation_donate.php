<?php
// payment/foundation_donate.php — บริจาคมูลนิธิ (need list) + Omise
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/needlist_donate_window.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'])) { header("Location: ../foundation.php"); exit(); }

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) { header("Location: ../foundation.php"); exit(); }

$stmt = $conn->prepare("SELECT * FROM foundation_profile WHERE foundation_id = ? LIMIT 1");
$stmt->bind_param("i", $fid);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) { header("Location: ../foundation.php"); exit(); }

$needOpen = drawdream_needlist_sql_open_for_donation();
$stmt2 = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS goal FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
$stmt2->bind_param("i", $fid);
$stmt2->execute();
$goal = (float)($stmt2->get_result()->fetch_assoc()['goal'] ?? 0);

$stmt3 = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS current FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
$stmt3->bind_param("i", $fid);
$stmt3->execute();
$current = (float)($stmt3->get_result()->fetch_assoc()['current'] ?? 0);

$percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;

$items_stmt = $conn->prepare("SELECT * FROM foundation_needlist WHERE foundation_id = ? AND $needOpen ORDER BY urgent DESC, item_id DESC");
$items_stmt->bind_param("i", $fid);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$fdCoverNeedImage = '';
foreach ($items as $itCov) {
    $nfCov = trim((string)($itCov['need_foundation_image'] ?? ''));
    if ($nfCov !== '') {
        $fdCoverNeedImage = $nfCov;
        break;
    }
}

$error = ""; $qr_image = ""; $charge_id = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $amount = (int)($_POST['amount'] ?? 0);
    if ($goal <= 0 || count($items) === 0) {
        $error = "ขณะนี้ไม่มีรายการสิ่งของที่เปิดรับบริจาค (ครบระยะเวลาหรือปิดรับแล้ว)";
    } elseif ($amount < 20) {
        $error = "จำนวนเงินขั้นต่ำ 20 บาท";
    } else {
        drawdream_clear_pending_payment_session();
        $amount_satang = $amount * 100;
        $source_response = omise_request('POST', '/sources', ['type' => 'promptpay', 'amount' => $amount_satang, 'currency' => 'THB']);
        if (isset($source_response['error'])) {
            $error = "เกิดข้อผิดพลาด: " . $source_response['message'];
        } elseif (isset($source_response['object']) && $source_response['object'] === 'source') {
            $charge_response = omise_request('POST', '/charges', [
                'amount' => $amount_satang, 'currency' => 'THB',
                'source' => $source_response['id'],
                'description' => 'บริจาครายการสิ่งของ: ' . $foundation['foundation_name'],
                'metadata' => ['foundation_id' => $fid, 'donor_id' => $_SESSION['user_id'], 'type' => 'needlist'],
            ]);
            if (isset($charge_response['error'])) {
                $error = "เกิดข้อผิดพลาดในการสร้าง QR Code: " . $charge_response['message'];
            } elseif (isset($charge_response['id'])) {
                $charge_id = $charge_response['id'];
                $qr_image  = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';
                $_SESSION['pending_charge_id']    = $charge_id;
                $_SESSION['pending_amount']        = $amount;
                $_SESSION['pending_foundation']    = $foundation['foundation_name'];
                $_SESSION['pending_foundation_id'] = $fid;
                $_SESSION['qr_image']              = $qr_image;
                header('Location: scan_qr.php?type=foundation&charge_id=' . rawurlencode($charge_id) . '&fid=' . $fid);
                exit();
            } else { $error = "เกิดข้อผิดพลาดที่ไม่คาดคิด"; }
        } else { $error = "ไม่สามารถสร้าง PromptPay Source ได้: " . ($source_response['message'] ?? 'unknown error'); }
    }
}

function omise_request($method, $path, $data = []) {
    $ch = curl_init(OMISE_API_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
    $response = curl_exec($ch); $curl_error = curl_error($ch); curl_close($ch);
    if ($response === false || $response === '') {
        if (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0) return _omise_local_mock($path, $data);
        return ['error' => 'curl_error', 'message' => $curl_error];
    }
    $decoded = json_decode($response, true);
    return $decoded ?? ['error' => 'json_error', 'message' => 'Invalid JSON'];
}

function _omise_local_mock(string $path, array $data): array {
    if (strpos($path, '/sources') !== false) return ['object' => 'source', 'id' => 'src_mock_' . bin2hex(random_bytes(6)), 'type' => 'promptpay'];
    if (strpos($path, '/charges') !== false) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="420" viewBox="0 0 320 420">'
            . '<rect width="320" height="420" fill="#ffffff"/>'
            . '<rect x="40" y="22" width="240" height="60" rx="6" fill="#1f4f7c"/>'
            . '<text x="160" y="56" font-size="20" text-anchor="middle" font-family="Prompt,Arial,sans-serif" fill="#fff" font-weight="700">THAI QR</text>'
            . '<text x="160" y="76" font-size="14" text-anchor="middle" font-family="Prompt,Arial,sans-serif" fill="#d8e8f7">PROMPTPAY</text>'
            . '<rect x="36" y="96" width="248" height="248" rx="8" fill="#fff" stroke="#d7dce4"/>'
            . '<rect x="52" y="112" width="56" height="56" fill="#000"/><rect x="59" y="119" width="42" height="42" fill="#fff"/><rect x="66" y="126" width="28" height="28" fill="#000"/>'
            . '<rect x="212" y="112" width="56" height="56" fill="#000"/><rect x="219" y="119" width="42" height="42" fill="#fff"/><rect x="226" y="126" width="28" height="28" fill="#000"/>'
            . '<rect x="52" y="272" width="56" height="56" fill="#000"/><rect x="59" y="279" width="42" height="42" fill="#fff"/><rect x="66" y="286" width="28" height="28" fill="#000"/>'
            . '<g fill="#000">'
            . '<rect x="132" y="118" width="8" height="8"/><rect x="148" y="118" width="8" height="8"/><rect x="164" y="118" width="8" height="8"/><rect x="180" y="118" width="8" height="8"/>'
            . '<rect x="124" y="134" width="8" height="8"/><rect x="140" y="134" width="8" height="8"/><rect x="156" y="134" width="8" height="8"/><rect x="172" y="134" width="8" height="8"/><rect x="188" y="134" width="8" height="8"/>'
            . '<rect x="124" y="150" width="8" height="8"/><rect x="140" y="150" width="8" height="8"/><rect x="164" y="150" width="8" height="8"/><rect x="188" y="150" width="8" height="8"/>'
            . '<rect x="116" y="166" width="8" height="8"/><rect x="132" y="166" width="8" height="8"/><rect x="148" y="166" width="8" height="8"/><rect x="164" y="166" width="8" height="8"/><rect x="180" y="166" width="8" height="8"/><rect x="196" y="166" width="8" height="8"/>'
            . '<rect x="116" y="182" width="8" height="8"/><rect x="132" y="182" width="8" height="8"/><rect x="156" y="182" width="8" height="8"/><rect x="172" y="182" width="8" height="8"/><rect x="196" y="182" width="8" height="8"/>'
            . '<rect x="116" y="198" width="8" height="8"/><rect x="140" y="198" width="8" height="8"/><rect x="156" y="198" width="8" height="8"/><rect x="180" y="198" width="8" height="8"/><rect x="196" y="198" width="8" height="8"/>'
            . '<rect x="116" y="214" width="8" height="8"/><rect x="132" y="214" width="8" height="8"/><rect x="148" y="214" width="8" height="8"/><rect x="164" y="214" width="8" height="8"/><rect x="180" y="214" width="8" height="8"/><rect x="196" y="214" width="8" height="8"/>'
            . '<rect x="124" y="230" width="8" height="8"/><rect x="140" y="230" width="8" height="8"/><rect x="156" y="230" width="8" height="8"/><rect x="172" y="230" width="8" height="8"/><rect x="188" y="230" width="8" height="8"/>'
            . '<rect x="124" y="246" width="8" height="8"/><rect x="140" y="246" width="8" height="8"/><rect x="164" y="246" width="8" height="8"/><rect x="180" y="246" width="8" height="8"/>'
            . '<rect x="132" y="262" width="8" height="8"/><rect x="148" y="262" width="8" height="8"/><rect x="164" y="262" width="8" height="8"/><rect x="180" y="262" width="8" height="8"/>'
            . '<rect x="124" y="278" width="8" height="8"/><rect x="140" y="278" width="8" height="8"/><rect x="156" y="278" width="8" height="8"/><rect x="172" y="278" width="8" height="8"/><rect x="188" y="278" width="8" height="8"/>'
            . '<rect x="124" y="294" width="8" height="8"/><rect x="148" y="294" width="8" height="8"/><rect x="172" y="294" width="8" height="8"/><rect x="188" y="294" width="8" height="8"/>'
            . '<rect x="124" y="310" width="8" height="8"/><rect x="140" y="310" width="8" height="8"/><rect x="156" y="310" width="8" height="8"/><rect x="172" y="310" width="8" height="8"/><rect x="188" y="310" width="8" height="8"/>'
            . '</g>'
            . '</svg>';
        return ['object'=>'charge','id'=>'chrg_mock_'.bin2hex(random_bytes(8)),'status'=>'pending','paid'=>false,'amount'=>$data['amount']??0,'currency'=>'THB','source'=>['type'=>'promptpay','scannable_code'=>['image'=>['download_uri'=>'data:image/svg+xml;base64,'.base64_encode($svg)]]]];
    }
    return ['error' => 'mock_unknown', 'message' => 'Mock: unknown API path'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บริจาครายการสิ่งของ | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
    <link rel="stylesheet" href="../css/foundation.css?v=21">
</head>
<body class="foundation-donate-page">

<?php include '../navbar.php'; ?>

<div class="fd-wrapper">
    <div class="fd-layout">

        <!-- ==================== ฝั่งซ้าย ==================== -->
        <div class="fd-left">
            <?php if ($fdCoverNeedImage !== ''): ?>
                <img src="../uploads/needs/<?= htmlspecialchars($fdCoverNeedImage) ?>"
                     class="fd-cover" alt="ภาพประกอบรายการสิ่งของ">
            <?php elseif (!empty($foundation['foundation_image'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($foundation['foundation_image']) ?>"
                     class="fd-cover" alt="">
            <?php endif; ?>

            <h2 class="fd-name"><?= htmlspecialchars($foundation['foundation_name']) ?></h2>
            <?php if ($goal <= 0 || empty($items)): ?>
                <div class="fd-alert fd-alert-error" role="status">ขณะนี้ไม่มีรายการสิ่งของที่เปิดรับบริจาค (ครบระยะเวลาหรือยังไม่มีรายการที่อนุมัติ)</div>
            <?php endif; ?>
            <?php if (!empty($foundation['foundation_desc'])): ?>
                <p class="fd-foundation-desc"><?= nl2br(htmlspecialchars($foundation['foundation_desc'])) ?></p>
            <?php endif; ?>

            <div class="fd-progress">
                <div class="fd-bar">
                    <div style="width:<?= (int)$percent ?>%;min-width:<?= $percent > 0 ? '6px' : '0' ?>;"></div>
                </div>
                <div class="fd-progress-text">
                    ยอดบริจาค <strong><?= number_format($current, 0) ?></strong> / <?= number_format($goal, 0) ?> บาท
                </div>
            </div>

            <?php if (!empty($items)): ?>
            <div class="fd-items-wrap">
                <h3 class="fd-items-title">รายการสิ่งของที่ต้องการ</h3>
                <?php foreach ($items as $item):
                    if (!is_array($item)) continue;
                    $rawNeedImgs = foundation_needlist_item_filenames_from_row($item);
                    $imgsThree = array_pad(array_slice($rawNeedImgs, 0, 3), 3, '');
                    $total = number_format((float)($item['total_price'] ?? 0), 0);
                    $urgent = !empty($item['urgent']);
                ?>
                <div class="fd-item-row<?= $urgent ? ' fd-item-urgent' : '' ?>">
                    <div class="fd-item-thumbs">
                    <?php foreach ($imgsThree as $imn): ?>
                        <?php if ($imn !== ''): ?>
                        <img src="../uploads/needs/<?= htmlspecialchars($imn) ?>" class="fd-item-thumb" alt="">
                        <?php else: ?>
                        <div class="fd-item-noimg fd-item-noimg--small" title="ไม่มีรูป">—</div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </div>
                    <div class="fd-item-detail">
                        <div class="fd-item-name">
                            <?= htmlspecialchars($item['item_name'] ?? '') ?>
                            <?php if ($urgent): ?><span class="fd-urgent-badge">ด่วน</span><?php endif; ?>
                        </div>
                        <div class="fd-item-meta">
                            รวม <?= $total ?> บาท
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div><!-- /.fd-left -->

        <!-- ==================== ฝั่งขวา ==================== -->
        <div class="fd-right">
            <?php if ($error): ?>
                <div class="fd-alert fd-alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

                <h3 class="fd-donate-section-title">เลือกจำนวนเงินที่ต้องการบริจาค</h3>
                <p class="fd-form-sub">เงินจะรวมเป็นกองทุนเพื่อซื้อสิ่งของให้มูลนิธิ</p>

                <form method="POST" id="foundationDonateForm"<?= ($goal <= 0 || empty($items)) ? ' class="fd-form-disabled"' : '' ?>>
                    <div class="amount-presets-grid fd-amount-presets-grid">
                        <button type="button" class="preset-btn" data-amt="2000" onclick="fdSelectPreset(2000)">2,000 บาท</button>
                        <button type="button" class="preset-btn" data-amt="1000" onclick="fdSelectPreset(1000)">1,000 บาท</button>
                        <button type="button" class="preset-btn" data-amt="500" onclick="fdSelectPreset(500)">500 บาท</button>
                        <div class="preset-btn preset-input-btn fd-preset-custom-cell">
                            <label for="amountInput" class="fd-preset-custom-label">ระบุจำนวน</label>
                            <input type="number" name="amount" id="amountInput" min="20" step="1"
                                   placeholder="ขั้นต่ำ 20 บาท" inputmode="numeric"
                                   oninput="fdClearPresetHighlight()">
                        </div>
                    </div>
                    <div class="payment-method">
                        <div class="method-card active">
                            <img src="../img/qr-code.png" alt="PromptPay" class="method-icon">
                            <span>PromptPay QR</span>
                        </div>
                    </div>
                    <button type="submit" name="pay" class="btn-pay"<?= ($goal <= 0 || empty($items)) ? ' disabled' : '' ?>>บริจาค</button>
                </form>
        </div><!-- /.fd-right -->

    </div><!-- /.fd-layout -->
</div><!-- /.fd-wrapper -->

<script>
function fdClearPresetHighlight() {
    document.querySelectorAll('#foundationDonateForm .amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
        b.classList.remove('preset-selected');
    });
}
function fdSelectPreset(amt) {
    var inp = document.getElementById('amountInput');
    if (inp) {
        inp.value = String(amt);
    }
    document.querySelectorAll('#foundationDonateForm .amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
        var v = parseInt(b.getAttribute('data-amt'), 10);
        b.classList.toggle('preset-selected', v === amt);
    });
}
document.getElementById('foundationDonateForm').addEventListener('submit', function (e) {
    var inp = document.getElementById('amountInput');
    var n = inp ? parseInt(String(inp.value).trim(), 10) : 0;
    if (!n || n < 20) {
        e.preventDefault();
        alert('กรุณาเลือกหรือระบุจำนวนเงินอย่างน้อย 20 บาท');
    }
});
</script>
</body>
</html>
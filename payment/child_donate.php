<?php
// payment/child_donate.php — รับ POST สร้าง Omise charge แล้วไป scan_qr.php (PromptPay จริงจาก Omise test/live)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../db.php';
include 'config.php';
require_once __DIR__ . '/omise_helpers.php';
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/pending_child_donation.php';
require_once dirname(__DIR__) . '/includes/qr_payment_abandon.php';
drawdream_child_sponsorship_ensure_columns($conn);

$ih = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'is_hidden'");
if ($ih && $ih->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_children ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'], true)) {
    header('Location: ../children_.php');
    exit;
}

$child_id = (int)($_POST['child_id'] ?? 0);
if ($child_id <= 0) {
    header('Location: ../children_.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
    FROM foundation_children c
    LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
    WHERE c.child_id = ? AND c.approve_profile IN ('อนุมัติ', 'กำลังดำเนินการ') AND COALESCE(c.is_hidden, 0) = 0 AND c.deleted_at IS NULL
    LIMIT 1
");
$stmt->bind_param('i', $child_id);
$stmt->execute();
$child = $stmt->get_result()->fetch_assoc();

if (!$child) {
    header('Location: ../children_.php');
    exit;
}

if (!drawdream_child_can_receive_donation($conn, $child_id, $child)) {
    header('Location: ../children_donate.php?id=' . $child_id);
    exit;
}

if (!($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay']))) {
    header('Location: ../children_donate.php?id=' . $child_id);
    exit;
}

function omise_request(string $method, string $path, array $data = []): array {
    $ch = curl_init(OMISE_API_URL . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        if (defined('OMISE_ALLOW_LOCAL_MOCK') && OMISE_ALLOW_LOCAL_MOCK && strpos(OMISE_SECRET_KEY, 'skey_test_') === 0) {
            return _omise_local_mock_child($path, $data);
        }
        $msg = ($curl_error !== '') ? $curl_error : 'ไม่ได้รับตอบกลับจาก Omise (ตรวจสอบอินเทอร์เน็ต / PHP cURL / SSL)';
        return ['error' => 'curl_error', 'message' => $msg];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['error' => 'json_error', 'message' => 'Invalid JSON response'];
    }
    return $decoded;
}

function _omise_local_mock_child(string $path, array $data): array {
    if (strpos($path, '/sources') !== false) {
        return ['object' => 'source', 'id' => 'src_mock_' . bin2hex(random_bytes(6)), 'type' => 'promptpay'];
    }
    if (strpos($path, '/charges') !== false) {
        $cid = 'chrg_mock_' . bin2hex(random_bytes(8));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
            . '<rect width="200" height="200" fill="#fff"/>'
            . '<rect x="10" y="10" width="56" height="56" fill="#000"/>'
            . '<rect x="17" y="17" width="42" height="42" fill="#fff"/>'
            . '<rect x="24" y="24" width="28" height="28" fill="#000"/>'
            . '<rect x="134" y="10" width="56" height="56" fill="#000"/>'
            . '<rect x="141" y="17" width="42" height="42" fill="#fff"/>'
            . '<rect x="148" y="24" width="28" height="28" fill="#000"/>'
            . '<rect x="10" y="134" width="56" height="56" fill="#000"/>'
            . '<rect x="17" y="141" width="42" height="42" fill="#fff"/>'
            . '<rect x="24" y="148" width="28" height="28" fill="#000"/>'
            . '<text x="100" y="108" font-size="11" text-anchor="middle" font-family="Arial" fill="#555">TEST MODE</text>'
            . '<text x="100" y="122" font-size="9" text-anchor="middle" font-family="Arial" fill="#999">Mock PromptPay QR</text>'
            . '</svg>';
        return [
            'object'   => 'charge',
            'id'       => $cid,
            'status'   => 'pending',
            'paid'     => false,
            'amount'   => $data['amount'] ?? 0,
            'currency' => 'THB',
            'source'   => ['scannable_code' => ['image' => ['download_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg)]]],
        ];
    }
    return ['error' => 'mock_unknown', 'message' => 'Mock: unknown API path'];
}

$redirectBack = function (string $msg) use ($child_id): void {
    header('Location: ../children_donate.php?id=' . $child_id . '&msg=' . rawurlencode($msg));
    exit;
};

$rawAmt = (string)($_POST['amount'] ?? '');
$rawAmt = str_replace([',', ' ', "\xC2\xA0"], '', $rawAmt);
$amount = (int) max(0, round((float) $rawAmt));
if ($amount < 20) {
    $redirectBack('จำนวนเงินขั้นต่ำ 20 บาท');
}

drawdream_abandon_all_pending_qr_for_donor($conn, (int)$_SESSION['user_id']);
drawdream_clear_pending_payment_session();

$amount_satang = $amount * 100;
$source_response = omise_request('POST', '/sources', [
    'type'     => 'promptpay',
    'amount'   => $amount_satang,
    'currency' => 'THB',
]);

if (isset($source_response['error'])) {
    $redirectBack('เกิดข้อผิดพลาด: ' . ($source_response['message'] ?? ''));
}
if (!isset($source_response['object']) || $source_response['object'] !== 'source') {
    $redirectBack('ไม่สามารถสร้าง PromptPay Source ได้');
}

$source_id = $source_response['id'];
$charge_response = omise_request('POST', '/charges', [
    'amount'      => $amount_satang,
    'currency'    => 'THB',
    'source'      => $source_id,
    'description' => 'บริจาคให้เด็ก: ' . $child['child_name'],
    'metadata'    => [
        'child_id' => $child_id,
        'donor_id' => $_SESSION['user_id'],
        'type'     => 'child',
    ],
]);

if (isset($charge_response['error'])) {
    $redirectBack('เกิดข้อผิดพลาดในการสร้าง QR Code: ' . ($charge_response['message'] ?? ''));
}
if (!isset($charge_response['id'])) {
    $redirectBack('เกิดข้อผิดพลาดที่ไม่คาดคิด');
}

$charge_id = $charge_response['id'];
$qr_image = drawdream_omise_promptpay_qr_uri_from_charge($charge_response);
if ($qr_image === '' && strpos((string) $charge_id, 'chrg_mock_') !== 0) {
    $again = drawdream_omise_fetch_charge((string) $charge_id);
    if ($again) {
        $qr_image = drawdream_omise_promptpay_qr_uri_from_charge($again);
    }
}

$pendingDonateId = drawdream_insert_pending_child_donation(
    $conn,
    $child_id,
    (int)$_SESSION['user_id'],
    (float)$amount,
    $charge_id
);
if ($pendingDonateId <= 0) {
    $redirectBack('ไม่สามารถบันทึกรายการชำระเงินได้ กรุณาลองใหม่');
}

$_SESSION['pending_charge_id'] = $charge_id;
$_SESSION['pending_amount'] = $amount;
$_SESSION['pending_child_id'] = $child_id;
$_SESSION['pending_child_name'] = $child['child_name'];
$_SESSION['pending_donate_id'] = $pendingDonateId;
$_SESSION['qr_image'] = $qr_image;

header('Location: scan_qr.php?type=child&charge_id=' . rawurlencode($charge_id) . '&child_id=' . $child_id);
exit;

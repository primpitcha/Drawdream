<?php
// payment/omise_webhook.php — รับ event จาก Omise (เช่น charge.complete) บันทึก child_donations จากอุปการะรายงวด
/**
 * ตั้งค่า: Omise Dashboard (Test/Live) → Webhooks → ใส่ URL แบบ HTTPS ที่เข้าถึงได้จากอินเทอร์เน็ต
 * (บน localhost ใช้ ngrok ชี้มาที่ https://xxxx.ngrok.io/drawdream/payment/omise_webhook.php)
 * เลือก event ที่เกี่ยวกับ charge (อย่างน้อย charge.complete) เมื่อหักจาก Schedule สำเร็จ Omise ส่งมาที่นี่
 * ระบบจะ INSERT child_donations เมื่อ metadata.app = drawdream_child_subscription และ paid สำเร็จ
 */
declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    echo json_encode(['received' => false]);
    exit;
}

$key = (string)($payload['key'] ?? $payload['trigger'] ?? '');
if ($key !== 'charge.complete') {
    echo json_encode(['received' => true, 'ignored' => $key]);
    exit;
}

$data = $payload['data'] ?? [];
if (!is_array($data)) {
    echo json_encode(['received' => true]);
    exit;
}
if (isset($data['object']) && $data['object'] !== 'charge') {
    echo json_encode(['received' => true, 'skip' => 'not_charge_object']);
    exit;
}

$chargeId = (string)($data['id'] ?? '');
if ($chargeId === '' || strpos($chargeId, 'chrg_') !== 0) {
    echo json_encode(['received' => true]);
    exit;
}

$meta = $data['metadata'] ?? [];
if (!is_array($meta) || ($meta['app'] ?? '') !== 'drawdream_child_subscription') {
    echo json_encode(['received' => true, 'skip' => 'not_subscription']);
    exit;
}

$childId = (int)($meta['child_id'] ?? 0);
$donorUid = (int)($meta['donor_user_id'] ?? 0);
if ($childId <= 0 || $donorUid <= 0) {
    echo json_encode(['received' => true, 'skip' => 'no_ids']);
    exit;
}

$paid = ($data['paid'] ?? false) === true || (string)($data['status'] ?? '') === 'successful';
if (!$paid) {
    echo json_encode(['received' => true, 'skip' => 'not_paid']);
    exit;
}

drawdream_child_omise_subscription_ensure_schema($conn);
$dupChk = $conn->prepare('SELECT 1 FROM omise_webhook_charge WHERE charge_id = ? LIMIT 1');
$dupChk->bind_param('s', $chargeId);
$dupChk->execute();
if ($dupChk->get_result()->fetch_row()) {
    echo json_encode(['received' => true, 'duplicate' => true]);
    exit;
}

$amountSatang = (int)($data['amount'] ?? 0);
$rec = drawdream_child_persist_subscription_paid_charge(
    $conn,
    $chargeId,
    $amountSatang,
    $childId,
    $donorUid
);
if (!$rec) {
    echo json_encode(['received' => true, 'recorded' => false]);
    exit;
}

echo json_encode(['received' => true, 'recorded' => true, 'child_id' => $childId]);

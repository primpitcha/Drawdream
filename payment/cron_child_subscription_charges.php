<?php
// payment/cron_child_subscription_charges.php — หักบัตรงวดถัดไปเมื่อ billing_mode = server_cron
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    $sec = defined('DRAWDREAM_SUBSCRIPTION_CRON_SECRET') ? (string)DRAWDREAM_SUBSCRIPTION_CRON_SECRET : '';
    if ($sec === '' || !isset($_GET['secret']) || !hash_equals($sec, (string)$_GET['secret'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/omise_api_client.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';

drawdream_child_omise_subscription_ensure_schema($conn);
drawdream_child_sponsorship_ensure_columns($conn);

$tz = new DateTimeZone('Asia/Bangkok');
$nowSql = drawdream_subscription_now_bangkok_sql();
$st = $conn->prepare(
    'SELECT * FROM child_omise_subscription
     WHERE status = ? AND billing_mode = ? AND next_charge_at IS NOT NULL AND next_charge_at <= ?
     ORDER BY next_charge_at ASC
     LIMIT 50'
);
$stAct = 'active';
$stMode = 'server_cron';
$st->bind_param('sss', $stAct, $stMode, $nowSql);
$st->execute();
$res = $st->get_result();

$processed = 0;
$errors = [];

while ($row = $res->fetch_assoc()) {
    $subId = (int)($row['id'] ?? 0);
    $childId = (int)($row['child_id'] ?? 0);
    $donorUid = (int)($row['donor_user_id'] ?? 0);
    $custId = trim((string)($row['omise_customer_id'] ?? ''));
    $cardId = trim((string)($row['omise_card_id'] ?? ''));
    $billDay = (int)($row['bill_day'] ?? 1);
    if ($subId <= 0 || $childId <= 0 || $donorUid <= 0 || $custId === '') {
        continue;
    }
    if ($cardId === '') {
        $errors[] = 'sub ' . $subId . ': no card id';
        continue;
    }
    $planSpec = drawdream_child_subscription_plan((string)($row['plan_code'] ?? ''));
    if ($planSpec === null) {
        $errors[] = 'sub ' . $subId . ': bad plan';
        continue;
    }

    $stmtC = $conn->prepare(
        'SELECT child_name FROM foundation_children WHERE child_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmtC->bind_param('i', $childId);
    $stmtC->execute();
    $cRow = $stmtC->get_result()->fetch_assoc();
    $childName = (string)($cRow['child_name'] ?? '');
    $desc = 'อุปการะเด็ก ' . $childName . ' — ' . $planSpec['plan_code'] . ' (' . $planSpec['amount_thb'] . ' THB)';

    $ch = drawdream_omise_create_card_charge(
        $custId,
        $cardId,
        (int)$planSpec['amount_satang'],
        $desc,
        [
            'child_id' => (string)$childId,
            'donor_user_id' => (string)$donorUid,
            'plan_code' => $planSpec['plan_code'],
            'app' => 'drawdream_child_subscription',
        ]
    );
    if ($ch === null || ($ch['object'] ?? '') === 'error') {
        $errors[] = 'sub ' . $subId . ': ' . (string)($ch['message'] ?? 'charge failed');
        continue;
    }
    $paid = ($ch['paid'] ?? false) === true || (string)($ch['status'] ?? '') === 'successful';
    if (!$paid) {
        $errors[] = 'sub ' . $subId . ': not paid status=' . (string)($ch['status'] ?? '');
        continue;
    }
    $chId = (string)($ch['id'] ?? '');
    $amtSat = (int)($ch['amount'] ?? $planSpec['amount_satang']);
    $rec = drawdream_child_persist_subscription_paid_charge($conn, $chId, $amtSat, $childId, $donorUid);
    if (!$rec) {
        $dup = $conn->prepare('SELECT 1 FROM omise_webhook_charge WHERE charge_id = ? LIMIT 1');
        $dup->bind_param('s', $chId);
        $dup->execute();
        if (!$dup->get_result()->fetch_row()) {
            $errors[] = 'sub ' . $subId . ': persist donation failed';
            continue;
        }
    }

    $dueStr = trim((string)($row['next_charge_at'] ?? ''));
    $anchor = $dueStr !== ''
        ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dueStr, $tz)
        : new DateTimeImmutable('now', $tz);
    if ($anchor === false) {
        $anchor = new DateTimeImmutable('now', $tz);
    }
    $nextAt = drawdream_subscription_next_charge_at($anchor, $planSpec, $billDay);
    $nextSql = $nextAt->format('Y-m-d H:i:s');
    $upd = $conn->prepare(
        'UPDATE child_omise_subscription SET last_charge_at = ?, next_charge_at = ? WHERE id = ?'
    );
    $upd->bind_param('ssi', $nowSql, $nextSql, $subId);
    $upd->execute();
    ++$processed;
}

$out = ['ok' => true, 'processed' => $processed, 'errors' => $errors];
if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
} else {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

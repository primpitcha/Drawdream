<?php
// payment/child_subscription_create.php — Omise Token→Customer→Charge Schedule (อุปการะเด็กรายงวด)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/omise_api_client.php';
require_once dirname(__DIR__) . '/includes/omise_user_messages.php';

/**
 * Omise ต้องการ on[days_of_month][]=N ไม่ใช่ on[days_of_month][0]=N
 *
 * @param array<string, string|int|float> $chargeFlat แบบ charge[customer], charge[amount], charge[metadata][k]
 */
function drawdream_omise_build_schedule_body(
    int $every,
    string $period,
    string $startDate,
    string $endDate,
    int $billDay,
    array $chargeFlat
): string {
    $pairs = [
        'every=' . (int)$every,
        'period=' . rawurlencode($period),
        'start_date=' . rawurlencode($startDate),
        'end_date=' . rawurlencode($endDate),
        'on[days_of_month][]=' . (int)$billDay,
    ];
    foreach ($chargeFlat as $k => $v) {
        $pairs[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
    }
    return implode('&', $pairs);
}

/** @param array<string, mixed> $charge */
function drawdream_omise_post_schedule(
    int $every,
    string $period,
    string $startDate,
    string $endDate,
    int $billDay,
    array $charge
): ?array {
    $flat = [
        'charge[customer]' => (string)($charge['customer'] ?? ''),
        'charge[amount]' => (string)(int)($charge['amount'] ?? 0),
        'charge[currency]' => strtolower((string)($charge['currency'] ?? 'thb')),
        'charge[description]' => (string)($charge['description'] ?? ''),
    ];
    $cardRef = trim((string)($charge['card'] ?? ''));
    if ($cardRef !== '') {
        $flat['charge[card]'] = $cardRef;
    }
    $meta = $charge['metadata'] ?? [];
    if (is_array($meta)) {
        foreach ($meta as $mk => $mv) {
            $flat['charge[metadata][' . $mk . ']'] = (string)$mv;
        }
    }
    $body = drawdream_omise_build_schedule_body($every, $period, $startDate, $endDate, $billDay, $flat);
    $url = rtrim(OMISE_API_URL, '/') . '/schedules';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $response === '') {
        $m = $curlErr !== '' ? $curlErr : ('เชื่อมต่อ Omise ไม่ได้ (HTTP ' . $httpCode . ')');
        return ['object' => 'error', 'code' => 'curl', 'message' => $m];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'object' => 'error',
            'code' => 'invalid_json',
            'message' => 'คำตอบ Omise ไม่ใช่ JSON: ' . substr((string)$response, 0, 160),
        ];
    }
    return $decoded;
}
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';

function child_subscription_redirect(string $msg, bool $ok, int $childId): void
{
    $q = 'id=' . $childId . '&sub_msg=' . rawurlencode($msg) . '&sub_ok=' . ($ok ? '1' : '0');
    header('Location: ../children_donate.php?' . $q);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'], true)) {
    header('Location: ../children_.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../children_.php');
    exit;
}

$donorUid = (int)$_SESSION['user_id'];
$childId = (int)($_POST['child_id'] ?? 0);
$planRaw = (string)($_POST['plan'] ?? '');
$token = trim((string)($_POST['omiseToken'] ?? ''));

if ($childId <= 0) {
    child_subscription_redirect('ข้อมูลเด็กไม่ถูกต้อง', false, 0);
}
if ($token === '' || strpos($token, 'tokn_') !== 0) {
    child_subscription_redirect('ไม่ได้รับโทเค็นบัตรจาก Omise กรุณาลองใหม่', false, $childId);
}

$planSpec = drawdream_child_subscription_plan($planRaw);
if ($planSpec === null) {
    child_subscription_redirect('แพ็กเกอุปการะไม่ถูกต้อง', false, $childId);
}

drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

$stmt = $conn->prepare(
    'SELECT c.*, COALESCE(NULLIF(c.foundation_name, \'\'), fp.foundation_name) AS display_foundation_name
     FROM foundation_children c
     LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
     WHERE c.child_id = ? AND c.deleted_at IS NULL LIMIT 1'
);
$stmt->bind_param('i', $childId);
$stmt->execute();
$child = $stmt->get_result()->fetch_assoc();
if (!$child) {
    child_subscription_redirect('ไม่พบข้อมูลเด็ก', false, $childId);
}

if (!drawdream_child_can_start_omise_subscription($conn, $childId, $child, $donorUid)) {
    child_subscription_redirect('ไม่สามารถสมัครอุปการะซ้ำกับเด็กคนนี้ได้ หรือโปรไฟล์ยังไม่พร้อม', false, $childId);
}

$stMail = $conn->prepare('SELECT email FROM `user` WHERE user_id = ? LIMIT 1');
$stMail->bind_param('i', $donorUid);
$stMail->execute();
$ur = $stMail->get_result()->fetch_assoc();
$email = trim((string)($ur['email'] ?? 'donor-' . $donorUid . '@drawdream.local'));

$stDn = $conn->prepare('SELECT donor_id, omise_customer_id FROM donor WHERE user_id = ? LIMIT 1');
$stDn->bind_param('i', $donorUid);
$stDn->execute();
$dnRow = $stDn->get_result()->fetch_assoc();
if (!$dnRow) {
    child_subscription_redirect('ไม่พบโปรไฟล์ผู้บริจาค', false, $childId);
}

$custId = trim((string)($dnRow['omise_customer_id'] ?? ''));
$cardId = '';


$create_omise_customer_with_card = function (string $tok) use ($email, $donorUid): array {
    $cres = drawdream_omise_post_form('/customers', [
        'email' => $email,
        'description' => 'DrawDream donor user_id=' . $donorUid,
        'card' => $tok,
    ]);
    if ($cres === null) {
        return ['ok' => false, 'msg' => 'เชื่อมต่อ Omise ไม่ได้', 'cust' => '', 'card' => ''];
    }
    if (($cres['object'] ?? '') === 'error') {
        $m = drawdream_omise_error_message_for_user($cres, 'สร้างลูกค้า Omise ไม่สำเร็จ');
        return ['ok' => false, 'msg' => $m, 'cust' => '', 'card' => ''];
    }
    $cid = (string)($cres['id'] ?? '');
    $def = $cres['default_card'] ?? null;
    $cr = '';
    if (is_array($def)) {
        $cr = (string)($def['id'] ?? '');
    } elseif (is_string($def) && $def !== '') {
        $cr = $def;
    }
    if ($cid === '' || $cr === '') {
        return ['ok' => false, 'msg' => 'Omise ไม่คืน customer/card', 'cust' => '', 'card' => ''];
    }
    return ['ok' => true, 'msg' => '', 'cust' => $cid, 'card' => $cr];
};

if ($custId === '') {
    $r = $create_omise_customer_with_card($token);
    if (!$r['ok']) {
        child_subscription_redirect($r['msg'], false, $childId);
    }
    $custId = $r['cust'];
    $cardId = $r['card'];
    $upd = $conn->prepare('UPDATE donor SET omise_customer_id = ? WHERE user_id = ?');
    $upd->bind_param('si', $custId, $donorUid);
    $upd->execute();
} else {
    $cres = drawdream_omise_post_form('/customers/' . rawurlencode($custId) . '/cards', [
        'card' => $token,
    ]);
    if ($cres === null || ($cres['object'] ?? '') === 'error') {
        if (drawdream_omise_is_not_found_error($cres)) {
            $clr = $conn->prepare('UPDATE donor SET omise_customer_id = NULL WHERE user_id = ?');
            $clr->bind_param('i', $donorUid);
            $clr->execute();
            $r = $create_omise_customer_with_card($token);
            if (!$r['ok']) {
                child_subscription_redirect(
                    'รหัสลูกค้า Omise ในฐานข้อมูลไม่ตรงกับบัญชีปัจจุบัน ระบบสร้างลูกค้าใหม่แล้วแต่ยังไม่สำเร็จ: ' . $r['msg'],
                    false,
                    $childId
                );
            }
            $custId = $r['cust'];
            $cardId = $r['card'];
            $upd = $conn->prepare('UPDATE donor SET omise_customer_id = ? WHERE user_id = ?');
            $upd->bind_param('si', $custId, $donorUid);
            $upd->execute();
        } else {
            $m = drawdream_omise_error_message_for_user($cres, 'ผูกบัตรไม่สำเร็จ');
            child_subscription_redirect($m, false, $childId);
        }
    } else {
        $cardId = (string)($cres['id'] ?? '');
        if ($cardId === '') {
            child_subscription_redirect('Omise ไม่คืน card id', false, $childId);
        }
    }
}

$tz = new DateTimeZone('Asia/Bangkok');
$now = new DateTimeImmutable('now', $tz);
$startDate = $now->format('Y-m-d');
$endDate = $now->modify('+10 years')->format('Y-m-d');
$billDay = drawdream_subscription_safe_bill_day($now);

$childName = (string)($child['child_name'] ?? '');
$desc = 'อุปการะเด็ก ' . $childName . ' — ' . $planSpec['plan_code'] . ' (' . $planSpec['amount_thb'] . ' THB)';

$chargePayload = [
    'customer' => $custId,
    'card' => $cardId,
    'amount' => $planSpec['amount_satang'],
    'currency' => 'thb',
    'description' => $desc,
    'metadata' => [
        'child_id' => (string)$childId,
        'donor_user_id' => (string)$donorUid,
        'plan_code' => $planSpec['plan_code'],
        'app' => 'drawdream_child_subscription',
    ],
];
$sres = drawdream_omise_post_schedule(
    $planSpec['every'],
    $planSpec['period'],
    $startDate,
    $endDate,
    $billDay,
    $chargePayload
);
if (($sres['object'] ?? '') === 'error' && drawdream_omise_is_not_found_error($sres) && $cardId !== '') {
    $chargeNoCard = $chargePayload;
    unset($chargeNoCard['card']);
    $sres = drawdream_omise_post_schedule(
        $planSpec['every'],
        $planSpec['period'],
        $startDate,
        $endDate,
        $billDay,
        $chargeNoCard
    );
}
if (($sres['object'] ?? '') === 'error') {
    if (drawdream_omise_is_not_found_error($sres)) {
        $clrSch = $conn->prepare('UPDATE donor SET omise_customer_id = NULL WHERE user_id = ?');
        $clrSch->bind_param('i', $donorUid);
        $clrSch->execute();
        $m = drawdream_omise_error_message_for_user($sres, 'สร้างตารางหักเงินไม่สำเร็จ');
        child_subscription_redirect($m, false, $childId);
    }

    $metaCharge = [
        'child_id' => (string)$childId,
        'donor_user_id' => (string)$donorUid,
        'plan_code' => $planSpec['plan_code'],
        'app' => 'drawdream_child_subscription',
    ];
    $fcharge = drawdream_omise_create_card_charge(
        $custId,
        $cardId,
        (int)$planSpec['amount_satang'],
        $desc,
        $metaCharge
    );
    if ($fcharge === null || ($fcharge['object'] ?? '') === 'error') {
        $m = drawdream_omise_error_message_for_user(
            $fcharge,
            'หักเงินงวดแรกไม่สำเร็จ (โหมดสำรองเมื่อ Omise ไม่ให้สร้าง Charge Schedule)'
        );
        child_subscription_redirect($m, false, $childId);
    }
    $firstChId = (string)($fcharge['id'] ?? '');
    $firstPaid = ($fcharge['paid'] ?? false) === true || (string)($fcharge['status'] ?? '') === 'successful';
    $authUri = trim((string)($fcharge['authorize_uri'] ?? ''));
    if (!$firstPaid) {
        if ($authUri !== '') {
            header('Location: ' . $authUri);
            exit;
        }
        child_subscription_redirect(
            'การชำระงวดแรกยังไม่สำเร็จ (สถานะ: ' . (string)($fcharge['status'] ?? '') . ') กรุณาลองอีกครั้งหรือใช้บัตรอื่น',
            false,
            $childId
        );
    }
    $amtSat = (int)($fcharge['amount'] ?? $planSpec['amount_satang']);
    drawdream_child_persist_subscription_paid_charge($conn, $firstChId, $amtSat, $childId, $donorUid);

    $localSchId = 'local_cron_' . bin2hex(random_bytes(12));
    $nextAt = drawdream_subscription_next_charge_at($now, $planSpec, $billDay);
    $nextSql = $nextAt->format('Y-m-d H:i:s');
    $lastSql = drawdream_subscription_now_bangkok_sql();
    $billingMode = 'server_cron';
    $ins = $conn->prepare(
        'INSERT INTO child_omise_subscription (
            child_id, donor_user_id, omise_schedule_id, omise_customer_id, omise_card_id,
            plan_code, every_n, period_unit, amount_thb, bill_day, status,
            billing_mode, next_charge_at, last_charge_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $status = 'active';
    $planCode = $planSpec['plan_code'];
    $everyN = $planSpec['every'];
    $periodU = $planSpec['period'];
    $amt = $planSpec['amount_thb'];
    $ins->bind_param(
        'iissssisdissss',
        $childId,
        $donorUid,
        $localSchId,
        $custId,
        $cardId,
        $planCode,
        $everyN,
        $periodU,
        $amt,
        $billDay,
        $status,
        $billingMode,
        $nextSql,
        $lastSql
    );
    if (!$ins->execute()) {
        child_subscription_redirect(
            'หักเงินงวดแรกสำเร็จแล้ว แต่บันทึกแผนในระบบไม่สำเร็จ — กรุณาติดต่อผู้ดูแล (รหัส charge: ' . $firstChId . ')',
            false,
            $childId
        );
    }

    $cronHint = '';
    if (!defined('DRAWDREAM_SUBSCRIPTION_CRON_SECRET') || DRAWDREAM_SUBSCRIPTION_CRON_SECRET === '') {
        $cronHint = ' ตั้งค่า DRAWDREAM_SUBSCRIPTION_CRON_SECRET ใน payment/config.php แล้วให้ Windows Task Scheduler / cron เรียก '
            . 'payment/cron_child_subscription_charges.php เป็นประจำ (เช่น ทุกวัน) ไม่งั้นงวดถัดไปจะไม่ถูกหักอัตโนมัติ';
    } else {
        $cronHint = ' ให้ตั้งเวลาเรียก payment/cron_child_subscription_charges.php?secret=… เป็นประจำ (เช่น ทุกวัน)';
    }
    $nextThai = $nextAt->format('d/m/Y') . ' เวลา 08:00 น. (เวลาไทย)';
    child_subscription_redirect(
        'สมัครอุปการะสำเร็จ — บัญชี Omise ยังไม่ให้สร้าง Charge Schedule ระบบใช้โหมดหักบนเซิร์ฟเวอร์แทน '
        . '(งวดแรกหักแล้ว) งวดถัดไปประมาณ ' . $nextThai . ' '
        . 'การชำระอัตโนมัติแต่ละงวดจะบันทึก child_donations เมื่อ Omise ส่ง Webhook charge.complete' . $cronHint,
        true,
        $childId
    );
}

$schId = (string)($sres['id'] ?? '');
if ($schId === '') {
    child_subscription_redirect('Omise ไม่คืน schedule id', false, $childId);
}

$ins = $conn->prepare(
    'INSERT INTO child_omise_subscription (
        child_id, donor_user_id, omise_schedule_id, omise_customer_id, omise_card_id,
        plan_code, every_n, period_unit, amount_thb, bill_day, status,
        billing_mode, next_charge_at, last_charge_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$status = 'active';
$planCode = $planSpec['plan_code'];
$everyN = $planSpec['every'];
$periodU = $planSpec['period'];
$amt = $planSpec['amount_thb'];
$modeSch = 'omise_schedule';
$nxNull = null;
$laNull = null;
$ins->bind_param(
    'iissssisdissss',
    $childId,
    $donorUid,
    $schId,
    $custId,
    $cardId,
    $planCode,
    $everyN,
    $periodU,
    $amt,
    $billDay,
    $status,
    $modeSch,
    $nxNull,
    $laNull
);
if (!$ins->execute()) {
    child_subscription_redirect('บันทึกฐานข้อมูลไม่สำเร็จ รหัส Schedule: ' . $schId . ' (ตรวจใน Omise Dashboard)', false, $childId);
}

child_subscription_redirect(
    'สมัครอุปการะสำเร็จ — Omise Charge Schedule รหัส ' . $schId
        . ' แต่ละงวดที่หักสำเร็จ Omise จะส่ง Webhook charge.complete มาที่ payment/omise_webhook.php '
        . 'เพื่อบันทึก child_donations (ต้องตั้ง URL ให้เข้าถึงจากอินเทอร์เน็ตได้ เช่น ผ่าน ngrok)',
    true,
    $childId
);

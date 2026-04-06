<?php
// includes/child_omise_subscription.php — ตาราง Omise subscription ผูกเด็ก x ผู้บริจาค + คอลัมน์ donor.omise_customer_id
declare(strict_types=1);

function drawdream_child_omise_subscription_ensure_schema(mysqli $conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM donor LIKE 'omise_customer_id'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE donor ADD COLUMN omise_customer_id VARCHAR(64) NULL DEFAULT NULL');
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS child_omise_subscription (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            child_id INT NOT NULL,
            donor_user_id INT NOT NULL,
            omise_schedule_id VARCHAR(64) NOT NULL,
            omise_customer_id VARCHAR(64) NOT NULL,
            omise_card_id VARCHAR(64) NULL,
            plan_code VARCHAR(24) NOT NULL,
            every_n INT NOT NULL,
            period_unit VARCHAR(12) NOT NULL,
            amount_thb DECIMAL(10,2) NOT NULL,
            bill_day TINYINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            billing_mode VARCHAR(24) NOT NULL DEFAULT 'omise_schedule',
            next_charge_at DATETIME NULL,
            last_charge_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schedule (omise_schedule_id),
            KEY idx_child_donor (child_id, donor_user_id),
            KEY idx_donor (donor_user_id),
            KEY idx_status (status),
            KEY idx_billing_next (billing_mode, status, next_charge_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $c1 = $conn->query("SHOW COLUMNS FROM child_omise_subscription LIKE 'billing_mode'");
    if ($c1 && $c1->num_rows === 0) {
        $conn->query("ALTER TABLE child_omise_subscription ADD COLUMN billing_mode VARCHAR(24) NOT NULL DEFAULT 'omise_schedule' AFTER status");
    }
    $c2 = $conn->query("SHOW COLUMNS FROM child_omise_subscription LIKE 'next_charge_at'");
    if ($c2 && $c2->num_rows === 0) {
        $conn->query('ALTER TABLE child_omise_subscription ADD COLUMN next_charge_at DATETIME NULL DEFAULT NULL AFTER billing_mode');
    }
    $c3 = $conn->query("SHOW COLUMNS FROM child_omise_subscription LIKE 'last_charge_at'");
    if ($c3 && $c3->num_rows === 0) {
        $conn->query('ALTER TABLE child_omise_subscription ADD COLUMN last_charge_at DATETIME NULL DEFAULT NULL AFTER next_charge_at');
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS omise_webhook_charge (
            charge_id VARCHAR(80) NOT NULL PRIMARY KEY,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** เวลาปัจจุบันใน Asia/Bangkok รูปแบบ SQL (ไม่มี timezone ใน DB) */
function drawdream_subscription_now_bangkok_sql(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
}

/**
 * คำนวณวันที่หักงวดถัดไป หลังจุดอ้างอิง (Bangkok 08:00)
 *
 * @param array{every:int, period:string} $planSpec
 */
function drawdream_subscription_next_charge_at(
    DateTimeImmutable $afterBangkok,
    array $planSpec,
    int $billDay
): DateTimeImmutable {
    $tz = $afterBangkok->getTimezone();
    $every = max(1, (int)($planSpec['every'] ?? 1));
    $anchor = $afterBangkok->modify('+' . $every . ' months');
    $y = (int)$anchor->format('Y');
    $m = (int)$anchor->format('n');
    $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $tz);
    $lastDom = (int)$firstOfMonth->format('t');
    $d = min(max(1, $billDay), $lastDom);

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d 08:00:00', $y, $m, $d), $tz);
}

/**
 * บันทึก charge ที่ชำระแล้วเข้า child_donations + donation + payment_transaction
 * (dedupe ด้วย omise_webhook_charge) — ให้ประวัติผู้บริจาคใน profile.php เห็นรายการตามเด็ก
 *
 * ใช้ร่วมกับ omise_webhook.php / งวดแรกแบบ server_cron / cron
 */
function drawdream_child_persist_subscription_paid_charge(
    mysqli $conn,
    string $chargeId,
    int $amountSatang,
    int $childId,
    int $donorUserId
): bool {
    if ($chargeId === '' || strpos($chargeId, 'chrg_') !== 0) {
        return false;
    }
    if ($childId <= 0 || $donorUserId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);

    $chk = $conn->prepare('SELECT 1 FROM omise_webhook_charge WHERE charge_id = ? LIMIT 1');
    $chk->bind_param('s', $chargeId);
    $chk->execute();
    if ($chk->get_result()->fetch_row()) {
        return false;
    }

    $amountBaht = $amountSatang / 100.0;

    require_once __DIR__ . '/donate_category_resolve.php';
    require_once __DIR__ . '/payment_transaction_schema.php';
    drawdream_payment_transaction_ensure_schema($conn);

    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($categoryId <= 0) {
        return false;
    }

    $taxId = '';
    $stTax = $conn->prepare('SELECT tax_id FROM donor WHERE user_id = ? LIMIT 1');
    if ($stTax) {
        $stTax->bind_param('i', $donorUserId);
        $stTax->execute();
        $rowT = $stTax->get_result()->fetch_assoc();
        if ($rowT) {
            $taxId = (string)($rowT['tax_id'] ?? '');
        }
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS child_donations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            child_id INT NOT NULL,
            donor_user_id INT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX(child_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!$conn->begin_transaction()) {
        return false;
    }
    try {
        $ins = $conn->prepare('INSERT INTO omise_webhook_charge (charge_id) VALUES (?)');
        if (!$ins) {
            throw new RuntimeException('prepare webhook charge');
        }
        $ins->bind_param('s', $chargeId);
        if (!$ins->execute()) {
            throw new RuntimeException('insert webhook charge');
        }

        $insD = $conn->prepare(
            'INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, ?, ?)'
        );
        if (!$insD) {
            throw new RuntimeException('prepare child_donations');
        }
        $insD->bind_param('iid', $childId, $donorUserId, $amountBaht);
        if (!$insD->execute()) {
            throw new RuntimeException('insert child_donations');
        }

        $serviceFee = 0.0;
        $insDon = $conn->prepare(
            'INSERT INTO donation (category_id, target_id, donor_id, amount, service_fee, payment_status, transfer_datetime)
             VALUES (?, ?, ?, ?, ?, \'completed\', NOW())'
        );
        if (!$insDon) {
            throw new RuntimeException('prepare donation');
        }
        $insDon->bind_param('iiidd', $categoryId, $childId, $donorUserId, $amountBaht, $serviceFee);
        if (!$insDon->execute()) {
            throw new RuntimeException('insert donation');
        }
        $donateId = (int)$conn->insert_id;
        if ($donateId <= 0) {
            throw new RuntimeException('donate_id');
        }

        $insPt = $conn->prepare(
            'INSERT INTO payment_transaction (donate_id, tax_id, omise_charge_id, transaction_status)
             VALUES (?, ?, ?, \'completed\')'
        );
        if (!$insPt) {
            throw new RuntimeException('prepare payment_transaction');
        }
        $insPt->bind_param('iss', $donateId, $taxId, $chargeId);
        if (!$insPt->execute()) {
            throw new RuntimeException('insert payment_transaction');
        }

        if (!function_exists('drawdream_child_sync_sponsorship_status')) {
            require_once __DIR__ . '/child_sponsorship.php';
        }
        if (function_exists('drawdream_child_sync_sponsorship_status')) {
            drawdream_child_sync_sponsorship_status($conn, $childId);
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();

        return false;
    }
}

function drawdream_child_has_active_omise_subscription(mysqli $conn, int $childId, int $donorUserId): bool
{
    if ($childId <= 0 || $donorUserId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $st = $conn->prepare(
        'SELECT id FROM child_omise_subscription
         WHERE child_id = ? AND donor_user_id = ? AND status = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $active = 'active';
    $st->bind_param('iis', $childId, $donorUserId, $active);
    $st->execute();
    return (bool)$st->get_result()->fetch_assoc();
}

/** มีผู้สมัครอุปการะรายเดือน/รายปี (หรืองวด Omise) กับเด็กคนนี้อย่างน้อย 1 รายการที่ยัง active */
function drawdream_child_has_any_active_subscription(mysqli $conn, int $childId): bool
{
    if ($childId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $st = $conn->prepare(
        'SELECT 1 FROM child_omise_subscription WHERE child_id = ? AND status = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $active = 'active';
    $st->bind_param('is', $childId, $active);
    $st->execute();
    return (bool)$st->get_result()->fetch_row();
}

/**
 * @param list<int> $childIds
 * @return array<int, true> child_id ที่มี subscription active
 */
function drawdream_child_ids_with_active_plan_sponsorship(mysqli $conn, array $childIds): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($x) => (int)$x, $childIds), static fn ($x) => $x > 0)));
    if ($ids === []) {
        return [];
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT DISTINCT child_id FROM child_omise_subscription WHERE status = ? AND child_id IN ($ph)";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $active = 'active';
    $bindTypes = 's' . $types;
    $st->bind_param($bindTypes, $active, ...$ids);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['child_id']] = true;
    }
    return $out;
}

/** Omise รองรับ days_of_month เฉพาะ 1–28 สำหรับรายเดือน */
function drawdream_subscription_safe_bill_day(DateTimeImmutable $bangkokNow): int
{
    $d = (int)$bangkokNow->format('j');
    return min(28, max(1, $d));
}

/**
 * @return array{every:int, period:string, amount_thb:float, amount_satang:int, plan_code:string}|null
 */
function drawdream_child_subscription_plan(string $plan): ?array
{
    $plan = strtolower(trim($plan));
    if ($plan === 'monthly') {
        return ['every' => 1, 'period' => 'month', 'amount_thb' => 700.0, 'amount_satang' => 70000, 'plan_code' => 'monthly'];
    }
    if ($plan === 'semiannual') {
        return ['every' => 6, 'period' => 'month', 'amount_thb' => 4200.0, 'amount_satang' => 420000, 'plan_code' => 'semiannual'];
    }
    if ($plan === 'yearly') {
        return ['every' => 12, 'period' => 'month', 'amount_thb' => 8400.0, 'amount_satang' => 840000, 'plan_code' => 'yearly'];
    }
    return null;
}

function drawdream_child_can_start_omise_subscription(mysqli $conn, int $childId, array $childRow, int $donorUserId): bool
{
    if ($donorUserId <= 0) {
        return false;
    }
    if (!empty($childRow['deleted_at'])) {
        return false;
    }
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return false;
    }
    if (!empty($childRow['is_hidden'])) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    if (drawdream_child_has_any_active_subscription($conn, $childId)) {
        return false;
    }
    return !drawdream_child_has_active_omise_subscription($conn, $childId, $donorUserId);
}

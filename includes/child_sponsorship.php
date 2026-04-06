<?php
// includes/child_sponsorship.php — อุปการะเด็กรายเดือน + child_donations
/**
 * อุปการะเด็กรายเดือน (ปฏิทิน Asia/Bangkok)
 *
 * - sum(child_donations) ในช่วง [max(วันที่ 1 เดือนนี้, first_approved_at), เดือนถัดไป)
 * - >= DRAWDREAM_CHILD_MONTH_SPONSOR_THRESHOLD (20_000 บ.) = อุปการะครบในรอบเดือน
 * - ไม่นับยอดก่อน first_approved_at / anchor
 * - drawdream_child_can_receive_donation() หยุดรับเมื่อครบ threshold ในรอบนั้น
 *
 * @see docs/SYSTEM_PRESENTATION_GUIDE.md
 */

const DRAWDREAM_CHILD_MONTH_SPONSOR_THRESHOLD = 20000.0;

function drawdream_child_sponsorship_ensure_columns(mysqli $conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'first_approved_at'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE foundation_children ADD COLUMN first_approved_at DATETIME NULL AFTER reviewed_at");
    }
    $conn->query("
        UPDATE foundation_children
        SET first_approved_at = reviewed_at
        WHERE first_approved_at IS NULL
          AND reviewed_at IS NOT NULL
          AND COALESCE(approve_profile, '') IN ('อนุมัติ', 'กำลังดำเนินการ')
    ");
}

/**
 * เดือนปฏิทินปัจจุบัน (Asia/Bangkok): วันที่ 1 00:00 ถึงก่อนวันที่ 1 เดือนถัดไป
 * คืน [effectiveStart, monthEnd) โดย effectiveStart = วันที่เริ่มนับยอด (ไม่ก่อน anchor)
 */
function drawdream_child_current_cycle_bounds(?string $anchorSql): ?array
{
    if ($anchorSql === null || trim($anchorSql) === '') {
        return null;
    }
    $tz = new DateTimeZone('Asia/Bangkok');
    try {
        $anchor = new DateTimeImmutable($anchorSql, $tz);
    } catch (Exception $e) {
        return null;
    }
    $now = new DateTimeImmutable('now', $tz);
    if ($anchor > $now) {
        return null;
    }

    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd = $monthStart->modify('+1 month');

    if ($anchor >= $monthEnd) {
        return null;
    }

    $effectiveStart = ($anchor > $monthStart) ? $anchor : $monthStart;

    return [$effectiveStart, $monthEnd];
}

function drawdream_child_anchor_datetime(array $childRow): ?string
{
    $fa = trim((string)($childRow['first_approved_at'] ?? ''));
    if ($fa !== '') {
        return $fa;
    }
    $rv = trim((string)($childRow['reviewed_at'] ?? ''));
    return $rv !== '' ? $rv : null;
}

/** ยอดบริจาคในเดือนปฏิทินปัจจุบัน (หลัง anchor ตาม effectiveStart) */
function drawdream_child_cycle_total(mysqli $conn, int $childId, array $childRow): float
{
    drawdream_child_sponsorship_ensure_columns($conn);
    $anchor = drawdream_child_anchor_datetime($childRow);
    if ($anchor === null) {
        return 0.0;
    }
    $bounds = drawdream_child_current_cycle_bounds($anchor);
    if ($bounds === null) {
        return 0.0;
    }
    [$effectiveStart, $monthEnd] = $bounds;
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
    $startStr = $effectiveStart->format('Y-m-d H:i:s');
    $endStr = $monthEnd->format('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS t FROM child_donations
         WHERE child_id = ? AND donated_at >= ? AND donated_at < ?'
    );
    $stmt->bind_param('iss', $childId, $startStr, $endStr);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return (float)($r['t'] ?? 0);
}

/**
 * @param list<array<string,mixed>> $rows แต่ละแถวต้องมี child_id (+ first_approved_at / reviewed_at)
 * @return array<int,float> child_id => ยอดในรอบปัจจุบัน
 */
function drawdream_child_cycle_totals_batch(mysqli $conn, array $rows): array
{
    drawdream_child_sponsorship_ensure_columns($conn);
    if ($rows === []) {
        return [];
    }
    $childIds = [];
    foreach ($rows as $row) {
        $id = (int)($row['child_id'] ?? 0);
        if ($id > 0) {
            $childIds[] = $id;
        }
    }
    $childIds = array_values(array_unique($childIds));
    if ($childIds === []) {
        return [];
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
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $types = str_repeat('i', count($childIds));
    $sql = "SELECT child_id, amount, donated_at FROM child_donations WHERE child_id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$childIds);
    $stmt->execute();
    $res = $stmt->get_result();

    $byChild = [];
    while ($r = $res->fetch_assoc()) {
        $cid = (int)$r['child_id'];
        if (!isset($byChild[$cid])) {
            $byChild[$cid] = [];
        }
        $byChild[$cid][] = $r;
    }

    $out = [];
    foreach ($rows as $row) {
        $cid = (int)($row['child_id'] ?? 0);
        if ($cid <= 0 || array_key_exists($cid, $out)) {
            continue;
        }
        $anchor = drawdream_child_anchor_datetime($row);
        $bounds = $anchor !== null ? drawdream_child_current_cycle_bounds($anchor) : null;
        $sum = 0.0;
        if ($bounds !== null && isset($byChild[$cid])) {
            [$effectiveStart, $monthEnd] = $bounds;
            foreach ($byChild[$cid] as $d) {
                try {
                    $dt = new DateTimeImmutable((string)$d['donated_at'], new DateTimeZone('Asia/Bangkok'));
                } catch (Exception $e) {
                    continue;
                }
                if ($dt >= $effectiveStart && $dt < $monthEnd) {
                    $sum += (float)$d['amount'];
                }
            }
        }
        $out[$cid] = $sum;
    }
    return $out;
}

function drawdream_child_is_cycle_sponsored(mysqli $conn, int $childId, array $childRow): bool
{
    return drawdream_child_cycle_total($conn, $childId, $childRow) >= DRAWDREAM_CHILD_MONTH_SPONSOR_THRESHOLD;
}

/** @deprecated ใช้ drawdream_child_is_cycle_sponsored */
function drawdream_child_is_month_sponsored(mysqli $conn, int $childId, array $childRow = []): bool
{
    if ($childRow === []) {
        $st = $conn->prepare('SELECT * FROM foundation_children WHERE child_id = ? LIMIT 1');
        $st->bind_param('i', $childId);
        $st->execute();
        $childRow = $st->get_result()->fetch_assoc() ?: [];
    }
    return drawdream_child_is_cycle_sponsored($conn, $childId, $childRow);
}

function drawdream_child_can_receive_donation(mysqli $conn, int $childId, array $childRow): bool
{
    if (!empty($childRow['deleted_at'])) {
        return false;
    }
    $ap = $childRow['approve_profile'] ?? '';
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return false;
    }
    if (!empty($childRow['is_hidden'])) {
        return false;
    }
    require_once __DIR__ . '/child_omise_subscription.php';
    if (drawdream_child_has_any_active_subscription($conn, $childId)) {
        return false;
    }
    return !drawdream_child_is_cycle_sponsored($conn, $childId, $childRow);
}

/** อุปการะครบยอดในเดือนปฏิทินปัจจุบัน (โปรไฟล์อนุมัติหรือกำลังดำเนินการ + ยอดรอบเดือน >= threshold) */
function drawdream_child_is_monthly_fully_sponsored(mysqli $conn, int $childId, array $childRow): bool
{
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return false;
    }
    return drawdream_child_is_cycle_sponsored($conn, $childId, $childRow);
}

/**
 * เด็กอยู่ในโซน "มีผู้อุปการะ" สาธารณะ: ครบยอดรอบเดือน หรือมีสมาชิกอุปการะแบบรายงวด (Omise) อย่างน้อย 1 ราย
 *
 * @param array<int, true> $planSponsoredMap จาก drawdream_child_ids_with_active_plan_sponsorship()
 */
function drawdream_child_is_showcase_sponsored(
    mysqli $conn,
    int $childId,
    array $childRow,
    float $cycleAmountInMonth,
    array $planSponsoredMap
): bool {
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return false;
    }
    if (!empty($planSponsoredMap[$childId])) {
        return true;
    }
    return $cycleAmountInMonth >= DRAWDREAM_CHILD_MONTH_SPONSOR_THRESHOLD;
}

function drawdream_child_outcome_ensure_columns(mysqli $conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'sponsor_outcome_text'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_children ADD COLUMN sponsor_outcome_text LONGTEXT NULL');
    }
    $chk2 = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'sponsor_outcome_updated_at'");
    if ($chk2 && $chk2->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_children ADD COLUMN sponsor_outcome_updated_at DATETIME NULL');
    }
    $chk3 = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'sponsor_outcome_images'");
    if ($chk3 && $chk3->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_children ADD COLUMN sponsor_outcome_images LONGTEXT NULL');
    }
}

/**
 * @return list<string> ชื่อไฟล์ใน uploads/Children/outcomes/ (basename เท่านั้น)
 */
function drawdream_child_outcome_images_parse(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return [];
    }
    $out = [];
    foreach ($j as $x) {
        $b = basename((string)$x);
        if ($b !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $b) === 1) {
            $out[] = $b;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param list<string> $basenames
 */
function drawdream_child_outcome_images_json(array $basenames): string
{
    $clean = [];
    foreach ($basenames as $x) {
        $b = basename((string)$x);
        if ($b !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $b) === 1) {
            $clean[] = $b;
        }
    }
    $clean = array_values(array_unique($clean));

    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

/**
 * HTML แกลเลอรีรูปผลลัพธ์ (path สัมพันธ์จากรากเว็บ)
 *
 * @param list<string> $basenames
 */
function drawdream_child_outcome_images_html(array $basenames): string
{
    if ($basenames === []) {
        return '';
    }
    $html = '<div class="child-outcome-public__gallery">';
    foreach ($basenames as $fn) {
        $safe = htmlspecialchars($fn, ENT_QUOTES, 'UTF-8');
        $html .= '<a class="child-outcome-public__gallery-item" href="uploads/Children/outcomes/' . $safe . '" target="_blank" rel="noopener">';
        $html .= '<img src="uploads/Children/outcomes/' . $safe . '" alt="" loading="lazy" decoding="async">';
        $html .= '</a>';
    }
    $html .= '</div>';

    return $html;
}

function drawdream_child_total_donations(mysqli $conn, int $childId): float
{
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
    $stmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS t FROM child_donations WHERE child_id = ?');
    $stmt->bind_param('i', $childId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['t'] ?? 0);
}

/**
 * ยอดรวม child_donations หลาย child_id (ใช้หน้ารายการมูลนิธิ)
 *
 * @param list<int> $childIds
 * @return array<int,float>
 */
function drawdream_child_donation_totals_batch(mysqli $conn, array $childIds): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($x) => (int)$x, $childIds), static fn ($x) => $x > 0)));
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = 0.0;
    }
    if ($ids === []) {
        return $out;
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
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT child_id, COALESCE(SUM(amount), 0) AS t FROM child_donations WHERE child_id IN ($ph) GROUP BY child_id"
    );
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['child_id']] = (float)($row['t'] ?? 0);
    }
    return $out;
}

function drawdream_child_sync_sponsorship_status(mysqli $conn, int $childId): void
{
    drawdream_child_sponsorship_ensure_columns($conn);
    $st = $conn->prepare('SELECT * FROM foundation_children WHERE child_id = ? LIMIT 1');
    $st->bind_param('i', $childId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return;
    }
    if (!empty($row['deleted_at'])) {
        return;
    }
    $cycle = drawdream_child_cycle_total($conn, $childId, $row);
    $status = $cycle >= DRAWDREAM_CHILD_MONTH_SPONSOR_THRESHOLD ? 'อุปการะแล้ว' : 'รออุปการะ';
    $stmt = $conn->prepare('UPDATE foundation_children SET status = ? WHERE child_id = ?');
    $stmt->bind_param('si', $status, $childId);
    $stmt->execute();
}

/**
 * ลบข้อมูลที่อ้างอิง child_id ก่อนลบแถว foundation_children
 * (child_donations, donation+payment_transaction ทุกหมวดที่ child_donate ไม่ว่าง, admin audit, notifications ที่ลิงก์ถึงเด็ก)
 *
 * หมายเหตุ: AUTO_INCREMENT ของแต่ละตารางใน MySQL จะนับต่อจากค่าสูงสุดที่เคยมี — ไม่มีการรีใบเลขย้อนเติมช่องว่าง (เป็นมาตรฐานที่ปลอดภัย)
 */
function drawdream_purge_child_related_data(mysqli $conn, int $childId): void
{
    if ($childId <= 0) {
        return;
    }

    $stmtCd = $conn->prepare('DELETE FROM child_donations WHERE child_id = ?');
    if ($stmtCd) {
        $stmtCd->bind_param('i', $childId);
        $stmtCd->execute();
    }

    $dq = $conn->prepare(
        'SELECT d.donate_id FROM donation d
         INNER JOIN donate_category dc ON dc.category_id = d.category_id
         WHERE d.target_id = ? AND TRIM(COALESCE(dc.child_donate, \'\')) NOT IN (\'\', \'-\')'
    );
    if ($dq) {
        $dq->bind_param('i', $childId);
        $dq->execute();
        $dres = $dq->get_result();
        while ($dr = $dres->fetch_assoc()) {
            $did = (int)($dr['donate_id'] ?? 0);
            if ($did <= 0) {
                continue;
            }
            $pt = $conn->prepare('DELETE FROM payment_transaction WHERE donate_id = ?');
            if ($pt) {
                $pt->bind_param('i', $did);
                $pt->execute();
            }
            $dd = $conn->prepare('DELETE FROM donation WHERE donate_id = ?');
            if ($dd) {
                $dd->bind_param('i', $did);
                $dd->execute();
            }
        }
    }

    $adm = $conn->prepare(
        'DELETE FROM `admin`
         WHERE target_id = ?
           AND LOWER(TRIM(COALESCE(target_entity, \'\'))) = \'child\''
    );
    if ($adm) {
        $adm->bind_param('i', $childId);
        $adm->execute();
    }

    $idStr = (string)(int)$childId;
    $notifExact = [
        'children_donate.php?id=' . $idStr,
        'payment/child_donate.php?child_id=' . $idStr,
        'payment.php?child_id=' . $idStr,
    ];
    foreach ($notifExact as $link) {
        $nf = $conn->prepare('DELETE FROM notifications WHERE link = ?');
        if ($nf) {
            $nf->bind_param('s', $link);
            $nf->execute();
        }
    }
    // อย่าใช้ %child_id=4% ลอยๆ — จะไปจับ child_id=40 / id=40
    $notifLike = [
        'children_donate.php?id=' . $idStr . '&%',
        '%/children_donate.php?id=' . $idStr . '&%',
        '%/children_donate.php?id=' . $idStr,
        'payment/child_donate.php?child_id=' . $idStr . '&%',
        '../payment/child_donate.php?child_id=' . $idStr . '%',
        '%/payment/child_donate.php?child_id=' . $idStr . '&%',
        '%/payment/child_donate.php?child_id=' . $idStr,
        '%check_child_payment.php%&child_id=' . $idStr . '&%',
        '%check_child_payment.php%&child_id=' . $idStr,
        '%check_child_payment.php%?child_id=' . $idStr . '&%',
        'payment.php?child_id=' . $idStr . '&%',
        '%/payment.php?child_id=' . $idStr . '&%',
        '%/payment.php?child_id=' . $idStr,
    ];
    foreach ($notifLike as $pat) {
        $nf = $conn->prepare('DELETE FROM notifications WHERE link LIKE ?');
        if ($nf) {
            $nf->bind_param('s', $pat);
            $nf->execute();
        }
    }
}

/** ลบไฟล์รูปใน uploads/Children/ หลังลบโปรไฟล์ (ชื่อไฟล์จากฐานข้อมูล) */
function drawdream_delete_child_upload_files(?string $photoChild, ?string $qrImage): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'Children' . DIRECTORY_SEPARATOR;
    foreach ([$photoChild, $qrImage] as $raw) {
        $fn = basename(trim((string)$raw));
        if ($fn === '' || $fn === '.' || $fn === '..') {
            continue;
        }
        $path = $dir . $fn;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

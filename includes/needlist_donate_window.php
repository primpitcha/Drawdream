<?php

// includes/needlist_donate_window.php — ระยะเวลารับบริจาครายการสิ่งของ (นับจากเวลาแอดมินอนุมัติ)
declare(strict_types=1);

/**
 * แยกข้อความระยะเวลาจากบรรทัดแรกของ note (รูปแบบ "ระยะเวลา: …")
 */
function drawdream_needlist_period_label_from_note(string $note): string
{
    $lines = preg_split('/\R/u', $note, 2);
    $first = (string)($lines[0] ?? '');
    if (preg_match('/^ระยะเวลา:\s*(.+)$/u', $first, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * คำนวณเวลาปิดรับบริจาคจากข้อความระยะเวลาที่มูลนิธิเลือก
 * นับจาก $approvalMoment (เช่น reviewed_at ตอนอนุมัติ)
 *
 * @return string|null datetime 'Y-m-d H:i:s' หรือ null = ไม่ปิดตามเวลา (ครั้งเดียว / ไม่รู้จัก)
 */
function drawdream_needlist_compute_donate_window_end(string $periodLabel, DateTimeImmutable $approvalMoment): ?string
{
    $p = trim($periodLabel);
    if ($p === '' || $p === 'ครั้งเดียว (ไม่ซ้ำ)') {
        return null;
    }

    try {
        if ($p === 'ต่อสัปดาห์') {
            return $approvalMoment->modify('+1 week')->format('Y-m-d H:i:s');
        }
        if ($p === 'ต่อเดือน') {
            return $approvalMoment->modify('+1 month')->format('Y-m-d H:i:s');
        }
        if ($p === 'ต่อ 6 เดือน') {
            return $approvalMoment->modify('+6 months')->format('Y-m-d H:i:s');
        }
        if ($p === 'ต่อปี') {
            return $approvalMoment->modify('+1 year')->format('Y-m-d H:i:s');
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

/**
 * เงื่อนไข SQL: รายการที่ยังเปิดรับบริจาคได้ (อนุมัติแล้ว และยังไม่ถึงเวลาปิด)
 *
 * @param non-empty-string $alias prefix เช่น '' หรือ 'nl.'
 */
function drawdream_needlist_sql_open_for_donation(string $alias = ''): string
{
    $a = $alias;
    return "({$a}approve_item = 'approved' AND ({$a}donate_window_end_at IS NULL OR {$a}donate_window_end_at > NOW()))";
}

/**
 * Backfill donate_window_end_at สำหรับรายการอนุมัติก่อนติดตั้งคอลัมน์
 */
function drawdream_needlist_backfill_donate_window_ends(mysqli $conn): void
{
    $sql = "SELECT item_id, note, reviewed_at FROM foundation_needlist
            WHERE approve_item = 'approved'
              AND (donate_window_end_at IS NULL)
              AND reviewed_at IS NOT NULL";
    $res = @$conn->query($sql);
    if (!$res) {
        return;
    }
    while ($row = $res->fetch_assoc()) {
        $rid = (int)($row['item_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $rv = trim((string)($row['reviewed_at'] ?? ''));
        if ($rv === '' || str_starts_with($rv, '0000-00-00')) {
            continue;
        }
        $period = drawdream_needlist_period_label_from_note((string)($row['note'] ?? ''));
        try {
            $from = new DateTimeImmutable($rv);
        } catch (Throwable $e) {
            continue;
        }
        $end = drawdream_needlist_compute_donate_window_end($period, $from);
        if ($end === null) {
            @$conn->query('UPDATE foundation_needlist SET donate_window_end_at = NULL WHERE item_id = ' . $rid);
        } else {
            $st = $conn->prepare('UPDATE foundation_needlist SET donate_window_end_at = ? WHERE item_id = ?');
            if ($st) {
                $st->bind_param('si', $end, $rid);
                $st->execute();
            }
        }
    }
}

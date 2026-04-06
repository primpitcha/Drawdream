<?php

// includes/project_donation_dates.php — วันที่และข้อมูลบริจาคต่อโครงการ
/**
 * วันเริ่มต้นสำหรับแสดงช่วงระดมทุนใน UI (อิงวันที่เสนอ/เริ่มโครงการในระบบ)
 */
function drawdream_project_effective_donation_start(array $p): ?string
{
    $st = trim((string)($p['start_date'] ?? ''));
    if ($st !== '') {
        try {
            return (new DateTimeImmutable(substr($st, 0, 10), new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

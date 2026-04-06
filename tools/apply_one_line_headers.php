<?php
// tools/apply_one_line_headers.php — CLI — แทรกมาตรฐานหัวไฟล์ // path —
/**
 * รันครั้งเดียว: C:\xampp\php\php.exe tools\apply_one_line_headers.php
 * มาตรฐานหัวไฟล์เป็น // <relative/path> — <คำอธิบาย>
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$map = [
    'about.php' => 'เกี่ยวกับเรา / FAQ',
    'account.php' => 'หน้าบัญชีผู้ใช้',
    'admin_approve_children.php' => 'แอดมินอนุมัติ/ปฏิเสธโปรไฟล์เด็ก',
    'admin_approve_foundation.php' => 'แอดมินอนุมัติ/ปฏิเสธคำขอสมัครมูลนิธิ',
    'admin_approve_needlist.php' => 'แอดมินอนุมัติรายการสิ่งของมูลนิธิ',
    'admin_approve_projects.php' => 'แอดมินอนุมัติโครงการ',
    'admin_children.php' => 'แอดมินจัดการโปรไฟล์เด็ก',
    'admin_children_overview.php' => 'ภาพรวมเด็กทั้งระบบ (แอดมิน)',
    'admin_dashboard.php' => 'แดชบอร์ดแอดมิน',
    'admin_donors.php' => 'ภาพรวมผู้บริจาค',
    'admin_escrow.php' => 'จัดการเงินค้ำ / escrow',
    'admin_foundations_overview.php' => 'ภาพรวมมูลนิธิ',
    'admin_notifications.php' => 'ศูนย์รวมงานรออนุมัติและลิงก์คิว',
    'admin_projects.php' => 'แอดมินจัดการโครงการ (อนุมัติ/ปฏิเสธ)',
    'children_.php' => 'รายชื่อเด็ก (สาธารณะ / มุมมองมูลนิธิ)',
    'children_donate.php' => 'หน้าบริจาคเด็ก → payment / Omise',
    'db.php' => 'เชื่อมต่อ MySQL + bootstrap migration',
    'detail_alin.php' => 'หน้าสตอรี่/รายละเอียดตัวอย่าง (alin)',
    'detail_pin.php' => 'หน้าสตอรี่/รายละเอียดตัวอย่าง (pin)',
    'detail_san.php' => 'หน้าสตอรี่/รายละเอียดตัวอย่าง (san)',
    'donor_update_profile.php' => 'แก้ไขโปรไฟล์ผู้บริจาค',
    'foundation.php' => 'หน้ามูลนิธิ + รายการสิ่งของ (สาธารณะ/จัดการ)',
    'foundation_add_children.php' => 'มูลนิธิเพิ่มโปรไฟล์เด็ก',
    'foundation_add_need.php' => 'มูลนิธิเสนอรายการสิ่งของ',
    'foundation_add_project.php' => 'มูลนิธิเสนอ/แก้ไขโครงการ',
    'foundation_child_outcome.php' => 'บันทึกผลลัพธ์/ผลกระทบเด็ก',
    'foundation_edit_child.php' => 'มูลนิธิแก้ไขโปรไฟล์เด็ก',
    'foundation_edit_profile.php' => 'มูลนิธิแก้ไขโปรไฟล์องค์กร',
    'foundation_merge_project.php' => 'รวม/จัดการโครงการ',
    'foundation_notifications.php' => 'กล่องแจ้งเตือนมูลนิธิ',
    'foundation_post_update.php' => 'โพสต์อัปเดตความคืบหน้าโครงการ',
    'foundation_public_profile.php' => 'โปรไฟล์มูลนิธิแบบสาธารณะ',
    'homepage.php' => 'หน้าแรก',
    'login.php' => 'เข้าสู่ระบบ / เลือกบทบาท',
    'logout.php' => 'ออกจากระบบ',
    'mark_notif_read.php' => 'ทำเครื่องหมายแจ้งเตือนอ่านแล้ว',
    'navbar.php' => 'แถบนำทางร่วมทุกหน้า',
    'payment.php' => 'หน้าชำระเงิน (เช่น QR ธนาคารบริจาคเด็ก)',
    'policy_consent.php' => 'หน้าความยินยอมและนโยบาย',
    'profile.php' => 'โปรไฟล์ผู้ใช้และประวัติบริจาค',
    'project.php' => 'รายการโครงการ',
    'project_result.php' => 'แสดงผลลัพธ์โครงการที่เสร็จสิ้น',
    'update_profile.php' => 'อัปเดตโปรไฟล์ (ทั่วไป)',
    'updateprofile.php' => 'legacy URL → 301 ไป update_profile.php',
    'welcome.php' => 'หน้าต้อนรับหลัง login (แอนิเมชัน + redirect)',
    'payment/config.php' => 'คีย์ Omise + endpoint (Test/Live)',
    'payment/payment_project.php' => 'หน้าชำระเงินโครงการ + Omise PromptPay',
    'payment/foundation_donate.php' => 'บริจาคมูลนิธิ (need list) + Omise',
    'payment/child_donate.php' => 'POST สร้าง Omise charge เด็ก → สแกน QR',
    'payment/scan_qr.php' => 'หน้าแสดง QR หลังสร้าง charge (ร่วมทุกประเภท)',
    'payment/donate_qr.php' => 'หน้า/เส้นทาง QR บริจาค',
    'payment/check_project_payment.php' => 'ยืนยันการชำระโครงการ (หลัง Omise)',
    'payment/check_child_payment.php' => 'ยืนยันการชำระบริจาคเด็ก',
    'payment/check_needlist_payment.php' => 'ยืนยันการชำระรายการสิ่งของ',
    'payment/abandon_qr.php' => 'ยกเลิกสถานะ QR / payment ค้าง',
    'includes/notification_audit.php' => 'แจ้งเตือน notifications + audit แอดมิน',
    'includes/admin_audit_migrate.php' => 'Migration ตาราง audit แอดมิน + helpers',
    'includes/drawdream_project_status.php' => 'มาตรฐานสถานะโครงการ + normalize DB',
    'includes/drawdream_needlist_schema.php' => 'Schema/migration รายการสิ่งของ',
    'includes/drawdream_soft_delete.php' => 'คอลัมน์ soft delete เด็ก/โครงการ',
    'includes/child_sponsorship.php' => 'อุปการะเด็กรายเดือน + child_donations',
    'includes/pending_child_donation.php' => 'ความช่วยเหลือการบริจาคเด็กค้างชั่วคราว',
    'includes/project_donation_dates.php' => 'วันที่และข้อมูลบริจาคต่อโครงการ',
    'includes/qr_payment_abandon.php' => 'ล้าง session QR payment ค้าง',
    'includes/site_footer.php' => 'HTML footer เว็บไซต์',
    'includes/thai_address_fields.php' => 'Partial select จังหวัด→ตำบล',
    'includes/address_helpers.php' => 'แปลง/รวมข้อความที่อยู่ไทยจาก POST',
    'includes/foundation_banks.php' => 'ข้อมูลบัญชีธนาคารมูลนิธิ (helper)',
    'includes/policy_consent_content.php' => 'เนื้อหานโยบาย (ฝังในหน้า consent)',
    'tools/apply_one_line_headers.php' => 'CLI — แทรกมาตรฐานหัวไฟล์ // path —',
];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($rii as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $abs = $fileInfo->getPathname();
    $rel = str_replace('\\', '/', substr($abs, strlen($root) + 1));
    if (!isset($map[$rel])) {
        fwrite(STDERR, "SKIP (no map): {$rel}\n");
        continue;
    }
    $desc = $map[$rel];
    $targetLine = '// ' . $rel . ' — ' . $desc . "\n";

    $raw = file_get_contents($abs);
    if ($raw === false || !preg_match('/^<\?php(\s*)/', $raw, $m)) {
        continue;
    }
    $indent = $m[1];
    $pos = strlen($m[0]);
    $rest = substr($raw, $pos);

    if (preg_match('/^([\r\n]+\s*)\/\*\*/s', $rest, $mm)) {
        if (str_contains(substr($rest, 0, 400), $rel . ' —')) {
            echo "OK (already): {$rel}\n";
            continue;
        }
        $new = substr($raw, 0, $pos) . $targetLine . $rest;
        $new = preg_replace(
            '/\R\/\/\s*ไฟล์นี้:[^\r\n]*\R\/\/\s*หน้าที่:[^\r\n]*(?:\R\/\/[^\r\n]*)?/u',
            '',
            $new,
            1
        );
        file_put_contents($abs, $new);
        echo "INSERT before docblock: {$rel}\n";
        continue;
    }

    // รองรับ <?php แล้วขึ้นบรรทัดใหม่ทันที (\A หลังตัด <?php แล้วมักขึ้นต้นด้วย \n)
    if (preg_match(
        '/\A\s*\/\/\s*ไฟล์นี้:\s*[^\r\n]+[\r\n]+\/\/\s*หน้าที่:\s*[^\r\n]+/u',
        $rest,
        $fm,
        PREG_OFFSET_CAPTURE
    )) {
        $start = (int)$fm[0][1];
        $len = strlen($fm[0][0]);
        $newRest = substr($rest, 0, $start) . $targetLine . substr($rest, $start + $len);
        file_put_contents($abs, substr($raw, 0, $pos) . $newRest);
        echo "REPLACE ไฟล์นี้/หน้าที่: {$rel}\n";
        continue;
    }

    if (preg_match('/^[\r\n\s]*\/\/\s*' . preg_quote($rel, '/') . '\s*—/u', $rest)
        || preg_match('/^[\r\n\s]*\/\/\s*[^\r\n]+\.php\s*—/u', $rest)) {
        $lines = preg_split("/\r\n|\n|\r/", $rest, 3);
        $first = trim($lines[0] ?? '');
        $second = trim($lines[1] ?? '');
        if (preg_match('/^\/\/\s*.+\s*—/u', $first) || preg_match('/^\/\/\s*.+\s*—/u', $second)) {
            echo "OK (one-line): {$rel}\n";
            continue;
        }
    }

    if (preg_match('/^[\r\n]*\/\/\s*([a-zA-Z0-9_\-]+\.php)\s*$/m', $rest, $wm)) {
        $newRest = preg_replace(
            '/^[\r\n]*\/\/\s*' . preg_quote($wm[1], '/') . '\s*$/m',
            trim($targetLine),
            $rest,
            1
        );
        if ($newRest !== $rest) {
            file_put_contents($abs, substr($raw, 0, $pos) . $newRest);
            echo "UPGRADE short comment: {$rel}\n";
            continue;
        }
    }

    $new = substr($raw, 0, $pos) . $targetLine . $rest;
    file_put_contents($abs, $new);
    echo "INSERT: {$rel}\n";
}

echo "Done.\n";

<?php
// payment/config.php — คีย์ Omise + endpoint (Test/Live)
/**
 * Omise — คีย์ API และ endpoint
 *
 * - คีย์ที่ขึ้นต้น pkey_test_ / skey_test_ = โหมดทดสอบ (ไม่ตัดเงินจริง)
 * - Public กับ Secret ต้องเป็นคู่จากบัญชี Omise **เดียวกัน** (Dashboard เดียวกัน) — ถ้าเปลี่ยนชุดคีย์ให้ลบหรือรีเซ็ตคอลัมน์ donor.omise_customer_id
 *   เพราะรหัสลูกค้าเก่า (cus_xxx) ของบัญชีอื่นจะได้ข้อความ Omise ว่า Resource was not found
 * - Charge Schedule (POST /schedules): Omise กำหนดให้ **ยืนยันอีเมลบัญชีก่อน** มิฉะนั้นจะได้ข้อความว่า Email verification is required…
 *   ให้ไปที่ Dashboard (Test) → บัญชี/อีเมล → ยืนยันจากลิงก์ในกล่องจดหมาย
 * - โค้ดใน payment_project.php / foundation_donate.php / child_donate.php เรียก HTTPS api.omise.co
 *
 * - OMISE_ALLOW_LOCAL_MOCK (ค่าเริ่มต้น false): ถ้า true และใช้ skey_test_* เมื่อ curl ล้มจะใช้ mock QR ในเครื่อง
 *   ค่าเริ่มต้น false = บังคับใช้ Omise test API เพื่อได้ QR จริงจาก Omise
 * - Production: เปลี่ยนเป็นคีย์ Live และเก็บนอก Git (env / config server)
 *
 * Security: ห้าม commit คีย์ production สาธารณะ — เก็บเป็น env/config server
 *
 * อุปการะแบบ server_cron (เมื่อ Omise ไม่ให้สร้าง Charge Schedule):
 * - DRAWDREAM_SUBSCRIPTION_CRON_SECRET ต้องไม่ว่างถ้าเรียก cron ผ่าน HTTP (?secret=…)
 * - Task Scheduler / cron: วันละครั้งหรือทุก 1–6 ชม. ให้ครอบเวลา 08:00 เวลาไทย (งวดถัดไปคำนวณที่ 08:00 Asia/Bangkok)
 *   • เบราว์เซอร์/curl: https://โดเมนของคุณ/drawdream/payment/cron_child_subscription_charges.php?secret=ค่าด้านล่าง
 *   • CLI: php payment/cron_child_subscription_charges.php หรือรัน payment/run_subscription_cron.bat
 * - Webhook charge.complete ยังต้องตั้งไว้ — ทุกงวดที่หักสำเร็จ Omise ส่งมาเพื่อบันทึก child_donations
 * - ถ้า Omise คืนแค่ not_found (ลูกค้า/บัตรไม่ตรงหรือโทเค็นบัตรหมดอายุ) ระบบไม่เข้าโหมดสำรองนี้ และจะเคลียร์ donor.omise_customer_id — ให้สมัครใหม่ด้วยโทเค็นบัตรใหม่
 *
 * @see docs/SYSTEM_PRESENTATION_GUIDE.md หัวข้อ Omise
 */

define('OMISE_PUBLIC_KEY', 'pkey_test_672j5iz6trht7azp83c');
define('OMISE_SECRET_KEY', 'skey_test_672j5jmwvta3f87nmpg');
define('OMISE_API_URL', 'https://api.omise.co');

if (!defined('OMISE_ALLOW_LOCAL_MOCK')) {
    define('OMISE_ALLOW_LOCAL_MOCK', false);
}

/** Secret สำหรับเรียก payment/cron_child_subscription_charges.php?secret=... (โหมดหักบนเซิร์ฟเวอร์แทน Omise Schedule) */
if (!defined('DRAWDREAM_SUBSCRIPTION_CRON_SECRET')) {
    define(
        'DRAWDREAM_SUBSCRIPTION_CRON_SECRET',
        '081a9e4b9232645eea0dbdaaa1d1cd1c6db6139a71aaa7ab93f6244a620d5aef'
    );
}
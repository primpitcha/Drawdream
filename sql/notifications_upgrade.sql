-- รันครั้งเดียวใน phpMyAdmin ถ้าต้องการอัปเดตมือ (ระบบจะทำอัตโนมัติผ่าน db.php อยู่แล้ว)
-- 1) คอลัมน์ entity_key (ถ้ามีแล้วจะ error — ข้ามบรรทัดนี้)
ALTER TABLE `notifications`
  ADD COLUMN `entity_key` VARCHAR(96) NULL DEFAULT NULL AFTER `link`,
  ADD INDEX `idx_notif_user_entity` (`user_id`, `entity_key`);

-- 2) ลบแจ้งเตือนแบบเก่าที่ระบบไม่สร้างแล้ว (มูลนิธิ “ส่งแล้ว” คู่กับแถวอนุมัติ)
DELETE FROM `notifications` WHERE `title` IN (
  'ส่งรายการสิ่งของแล้ว',
  'ส่งโปรไฟล์เด็กแล้ว',
  'ส่งคำขอเสนอโครงการแล้ว'
);

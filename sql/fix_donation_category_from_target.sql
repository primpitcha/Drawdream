-- แก้ donation.category_id ให้ตรงกับ donate_category (ภาพหมวด: เด็ก / โครงการ / สิ่งของ)
--
-- สำคัญ: «รีเฟรช» หน้า phpMyAdmin อย่างเดียวไม่เปลี่ยนข้อมูล — ต้องคลิกแท็บ SQL วางคำสั่ง UPDATE นี้แล้วกด «Go»
-- การแก้โค้ด PHP มีผลเฉพาะรายการบริจาค «ใหม่» แถวเก่าในตาราง donation ต้องรันไฟล์นี้ (หรือไฟล์ project ก่อนเด็ก) เอง
--
-- target_id คืออะไร: คีย์อ้างอิงแบบ polymorphic — ขึ้นกับ category_id
--   category หมวดเด็ก     → target_id = foundation_children.child_id
--   category หมวดโครงการ → target_id = foundation_project.project_id
--   category หมวดสิ่งของ → target_id = foundation_profile.foundation_id (บริจาครวมรายการสิ่งของของมูลนิธิ ไม่ใช่ item_id)
--
-- ลำดับในไฟล์นี้: เด็ก → โครงการ → มูลนิธิ
-- ถ้ามีทั้ง child_id และ project_id เป็นเลขเดียวกัน (เช่น 1 กับ 1) แถวนั้นจะได้หมวด «เด็ก» เสมอ
-- ถ้ารันแล้วยังเป็น category_id = 1 ทุกแถวที่ target เป็น 1,2 แต่จริงๆ เป็นโครงการ — รันแทนไฟล์
--   sql/fix_donation_category_project_before_child.sql
--
-- รันใน phpMyAdmin หรือ: Get-Content sql/fix_donation_category_from_target.sql | mysql -u root drawdream_db
--
-- ถ้า phpMyAdmin ขึ้น Error 1175 (safe update): บรรทัดถัดไปปิดโหมดนี้ชั่วคราว หรือปิดใน Preferences > SQL

SET SESSION sql_safe_updates = 0;

UPDATE donation d
SET d.category_id = (
    CASE
        WHEN EXISTS (
            SELECT 1 FROM foundation_children c
            WHERE c.child_id = d.target_id AND c.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.child_donate IS NOT NULL
              AND TRIM(dc.child_donate) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_project p
            WHERE p.project_id = d.target_id AND p.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.project_donate IS NOT NULL
              AND TRIM(dc.project_donate) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_profile fp
            WHERE fp.foundation_id = d.target_id
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.needitem_donate IS NOT NULL
              AND TRIM(dc.needitem_donate) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        ELSE d.category_id
    END
)
WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed';

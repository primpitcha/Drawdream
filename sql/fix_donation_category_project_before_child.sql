-- แก้ category_id เมื่อเลข target_id «ชน» ทั้งเด็กและโครงการ (มีทั้ง child_id=N และ project_id=N)
-- ใช้เมื่อรัน fix_donation_category_from_target.sql แล้วยังได้หมวดเด็กหมด แต่รายการจริงเป็นโครงการ
-- ลำดับ: โครงการ → เด็ก → มูลนิธิ (สิ่งของ)
-- ข้อควรระวัง: ถ้ามีบริจาคให้เด็กจริงๆ ที่ child_id ชนกับ project_id แถวนั้นจะถูกจัดเป็นโครงการ — ต้องแก้มือใน phpMyAdmin
--
-- รันใน phpMyAdmin (แท็บ SQL) หรือ: Get-Content sql/fix_donation_category_project_before_child.sql | mysql -u root drawdream_db
--
-- ถ้า Error 1175 (safe update): รันบรรทัด SET ด้านล่างก่อน UPDATE

SET SESSION sql_safe_updates = 0;

UPDATE donation d
SET d.category_id = (
    CASE
        WHEN EXISTS (
            SELECT 1 FROM foundation_project p
            WHERE p.project_id = d.target_id AND p.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.project_donate IS NOT NULL
              AND TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_children c
            WHERE c.child_id = d.target_id AND c.deleted_at IS NULL
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.child_donate IS NOT NULL
              AND TRIM(COALESCE(dc.child_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        WHEN EXISTS (
            SELECT 1 FROM foundation_profile fp
            WHERE fp.foundation_id = d.target_id
        ) THEN COALESCE((
            SELECT dc.category_id FROM donate_category dc
            WHERE dc.needitem_donate IS NOT NULL
              AND TRIM(COALESCE(dc.needitem_donate, '')) NOT IN ('', '-')
            LIMIT 1
        ), d.category_id)
        ELSE d.category_id
    END
)
WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed';

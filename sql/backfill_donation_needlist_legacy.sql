-- backfill_donation_needlist_legacy.sql
-- แก้รายการ donation หมวด "รายการสิ่งของ" ที่บันทึกแบบเก่า (ไม่มี donor_id / target_id)
-- รันใน phpMyAdmin / MySQL client หลังสำรองข้อมูล
--
-- แนะนำ: รัน SELECT ด้านล่างก่อน ดูจำนวนแถวที่เสียก่อน UPDATE
-- วิธีที่สมบูรต์: ใช้ tools/backfill_needlist_donations_omise.php (ดึง metadata จาก Omise)

-- ===== ตรวจสอบรายการที่น่าจะเป็นแถวเก่า (need item + ไม่มีผู้บริจาคหรือไม่มีมูลนิธิ) =====
SELECT d.donate_id,
       d.donor_id,
       d.target_id,
       d.amount,
       d.transfer_datetime,
       pt.omise_charge_id,
       pt.tax_id,
       dc.needitem_donate
FROM donation d
INNER JOIN payment_transaction pt ON pt.donate_id = d.donate_id
INNER JOIN donate_category dc ON dc.category_id = d.category_id AND dc.needitem_donate IS NOT NULL
WHERE LOWER(TRIM(d.payment_status)) = 'completed'
  AND (
    d.donor_id IS NULL OR d.donor_id = 0
    OR d.target_id IS NULL OR d.target_id = 0
  );

-- ===== แก้ donor_id จาก payment_transaction.tax_id (ใช้ได้เมื่อ tax_id ไม่ว่างและชี้ถึง donor คนเดียว) =====
UPDATE donation d
INNER JOIN payment_transaction pt ON pt.donate_id = d.donate_id
INNER JOIN donate_category dc ON dc.category_id = d.category_id AND dc.needitem_donate IS NOT NULL
INNER JOIN (
    SELECT TRIM(tax_id) AS tax_key, MIN(user_id) AS user_id, COUNT(*) AS cnt
    FROM donor
    WHERE TRIM(COALESCE(tax_id, '')) <> ''
    GROUP BY TRIM(tax_id)
    HAVING cnt = 1
) u ON TRIM(pt.tax_id) = u.tax_key AND TRIM(COALESCE(pt.tax_id, '')) <> ''
SET d.donor_id = u.user_id
WHERE LOWER(TRIM(d.payment_status)) = 'completed'
  AND (d.donor_id IS NULL OR d.donor_id = 0);

-- หมายเหตุ: target_id (foundation_id) แบบเก่าไม่มีใน DB — ต้องรัน Omise backfill หรือแก้มือ

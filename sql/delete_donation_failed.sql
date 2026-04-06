-- ลบประวัติบริจาคที่ไม่สำเร็จ — ตาราง donation เก็บเฉพาะ payment_status = completed
-- รันใน phpMyAdmin หรือ: mysql -u ... drawdream_db < sql/delete_donation_failed.sql

DELETE pt FROM payment_transaction pt
INNER JOIN donation d ON d.donate_id = pt.donate_id
WHERE LOWER(TRIM(d.payment_status)) = 'failed';

DELETE FROM donation WHERE LOWER(TRIM(payment_status)) = 'failed';

-- แถว payment_transaction ที่ค้างสถานะ failed โดยไม่มี donation (ถ้ามี)
DELETE FROM payment_transaction WHERE LOWER(TRIM(transaction_status)) = 'failed';

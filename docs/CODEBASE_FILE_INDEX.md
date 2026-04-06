# ดัชนีไฟล์โค้ด DrawDream (PHP)

แต่ละแถวสรุป **บทบาทหนึ่งบรรทัด** ใช้ไล่โค้ดหรืออ้างอิงตอนพรีเซนต์คู่กับ `SYSTEM_PRESENTATION_GUIDE.md`

> **รายการเส้นทางแบบรวมเป็นชุดเดียว (ไม่ซ้ำ):** ดู **`SYSTEM_PRESENTATION_GUIDE.md` → หัวข้อ ก) ข้อ 6 — รายการไฟล์อ้างอิงครบถ้วน**

## รากโปรเจกต์
| ไฟล์ | ทำอะไร |
|------|--------|
| `db.php` | เชื่อมต่อ MySQL + bootstrap migration เบื้องต้น (โครงการ, needlist, soft delete, notifications legacy) |
| `login.php` | เข้าสู่ระบบ / เลือกบทบาทหลังล็อกอิน |
| `logout.php` | ออกจากระบบ |
| `welcome.php` | ต้อนรับหลัง login ทุก role รวม admin (`show_welcome` จาก `login.php`) |
| `homepage.php` | หน้าแรกหลัก |
| `navbar.php` | แถบนำทางร่วม |
| `about.php` | เกี่ยวกับเรา |
| `policy_consent.php` | หน้าความยินยอม/นโยบาย |
| `mark_notif_read.php` | API/หน้า mark แจ้งเตือนอ่านแล้ว |

## ผู้บริจาค / โปรไฟล์
| ไฟล์ | ทำอะไร |
|------|--------|
| `profile.php` | โปรไฟล์ผู้ใช้ / ประวัติบริจาค (donor: ดึงจาก `donation` — บริจาคเด็กรายครั้งและ **อุปการะงวด** แสดงเป็น “บริจาคให้เด็ก — ชื่อเด็ก” + อ้างอิง Omise เมื่อมีแถว `donation` + `payment_transaction`) |
| `update_profile.php` | อัปเดตโปรไฟล์ (ทั่วไป) |
| `donor_update_profile.php` | แก้โปรไฟล์ผู้บริจาคโดยเฉพาะ |
| `updateprofile.php` | legacy URL — redirect 301 ไป `update_profile.php` |
| `children_.php` | รายชื่อเด็ก (มุมมองสาธารณะ/ฝั่งมูลนิธิ) |
| `children_donate.php` | หน้าบริจาคเด็ก — QR รายครั้ง / อุปการะบัตร → `payment/child_donate.php`, `payment/child_subscription_create.php` |
| `project.php` | รายการโครงการ |
| `project_result.php` | ผล/รายละเอียดโครงการ |
| `foundation.php` | หน้ามูลนิธิ + รายการสิ่งของ (มุมมองมูลนิธิ vs สาธารณะ) |
| `foundation_public_profile.php` | โปรไฟล์มูลนิธิสาธารณะ |
| `payment.php` | หน้าชำระเงิน (เช่น QR ธนาคารเด็ก) |

## มูลนิธิ — จัดการข้อมูล
| ไฟล์ | ทำอะไร |
|------|--------|
| `foundation_add_children.php` | เพิ่มโปรไฟล์เด็ก |
| `foundation_edit_child.php` | แก้ไขเด็ก |
| `foundation_add_project.php` | เสนอ/แก้ไขโครงการ |
| `foundation_merge_project.php` | รวม/จัดการโครงการ |
| `foundation_add_need.php` | เสนอรายการสิ่งของ |
| `foundation_post_update.php` | โพสต์อัปเดตโครงการ |
| `foundation_edit_profile.php` | แก้ไขโปรไฟล์มูลนิธิ |
| `foundation_child_outcome.php` | บันทึกผลลัพธ์/ผลกระทบเด็ก |
| `foundation_notifications.php` | กล่องแจ้งเตือนมูลนิธิ |

## แอดมิน
| ไฟล์ | ทำอะไร |
|------|--------|
| `admin_dashboard.php` | แดชบอร์ด + กราฟ + ลิงก์ไปรายละเอียด |
| `admin_notifications.php` | รวมงานรออนุมัติ/ลิงก์ไปแต่ละคิว |
| `admin_approve_foundation.php` | อนุมัติ/ปฏิเสธสมัครมูลนิธิ |
| `admin_approve_children.php` | อนุมัติโปรไฟล์เด็ก |
| `admin_children.php` | จัดการเด็กฝั่งแอดมิน |
| `admin_children_overview.php` | ภาพรวมเด็ก |
| `admin_approve_projects.php` | อนุมัติโครงการ |
| `admin_projects.php` | จัดการโครงการแอดมิน |
| `admin_approve_needlist.php` | อนุมัติรายการสิ่งของ |
| `admin_foundations_overview.php` | ภาพรวมมูลนิธิ |
| `admin_donors.php` | ภาพรวมผู้บริจาค |
| `admin_escrow.php` | เงินค้ำ/escrow |

## Payment / Omise
| ไฟล์ | ทำอะไร |
|------|--------|
| `payment/config.php` | คีย์ Omise + endpoint (Test/Live), mock flag, secret cron |
| `payment/omise_helpers.php` | ฟังก์ชันเรียก Omise ร่วมกับโครงการ |
| `payment/payment_project.php` | ชำระเงินโครงการ + สร้าง charge PromptPay |
| `payment/foundation_donate.php` | บริจาคมูลนิธิ (need list) + กรองรายการเปิดรับ |
| `payment/child_donate.php` | POST สร้าง charge เด็ก (QR ครั้งเดียว) → สแกน |
| `payment/child_subscription_create.php` | อุปการะงวด: token บัตร + Schedule / fallback |
| `payment/omise_webhook.php` | Webhook Omise (เช่น charge.complete) |
| `payment/cron_child_subscription_charges.php` | Cron หักงวดแทน Schedule (HTTP/CLI) |
| `payment/run_subscription_cron.bat` | ตัวช่วยรัน cron บน Windows |
| `payment/scan_qr.php` | หน้า QR ร่วมหลังสร้าง charge |
| `payment/donate_qr.php` | หน้า/เส้นทาง QR บริจาค |
| `payment/check_project_payment.php` | ยืนยัน charge โครงการ |
| `payment/check_child_payment.php` | ยืนยัน charge เด็ก |
| `payment/check_needlist_payment.php` | ยืนยัน need list + แบ่งยอดรายการ |
| `payment/abandon_qr.php` | ยกเลิก QR / ทิ้งสถานะค้าง |

## includes/ — ไลบรารีภายใน
| ไฟล์ | ทำอะไร |
|------|--------|
| `donate_category_resolve.php` | หมวดบริจาค / target (เด็ก โครงการ สิ่งของ) |
| `notification_audit.php` | สร้างตาราง notifications, ส่งแจ้งเตือน, entity_key, legacy cleanup hook |
| `admin_audit_migrate.php` | ตาราง audit แอดมิน + ชนิดเหตุการณ์ |
| `drawdream_project_status.php` | มาตรฐานสถานะโครงการ + normalize ใน DB |
| `drawdream_needlist_schema.php` | schema/migration รายการสิ่งของ (รวม donate_window_end_at) |
| `drawdream_project_updates_schema.php` | สร้างตาราง `project_updates` (ผลลัพธ์โครงการ) ถ้ายังไม่มี |
| `needlist_donate_window.php` | ระยะเวลารับบริจาคสิ่งของ + SQL เปิดรับ |
| `drawdream_soft_delete.php` | คอลัมน์ soft delete เด็ก/โครงการ |
| `child_sponsorship.php` | อุปการะรายเดือน / `child_donations` / threshold |
| `child_omise_subscription.php` | schema + logic subscription Omise ฝั่งเด็ก; **`drawdream_child_persist_subscription_paid_charge()`** บันทึก `child_donations` + **`donation`** + **`payment_transaction`** (ให้ประวัติใน `profile.php`) |
| `pending_child_donation.php` | การบริจาคเด็กค้าง/ชั่วคราว |
| `payment_transaction_schema.php` | ตาราง/คอลัมน์ payment_transaction |
| `project_donation_dates.php` | วันที่เกี่ยวกับบริจาคโครงการ |
| `qr_payment_abandon.php` | ช่วยล้างสถานะ QR ค้าง |
| `omise_api_client.php` | คลายเรียก Omise API (ถ้ามีการใช้) |
| `omise_user_messages.php` | ข้อความผู้ใช้เกี่ยวกับ Omise / subscription |
| `site_footer.php` | footer เว็บไซต์ |
| `thai_address_fields.php` | partial HTML เลือกที่อยู่ไทย |
| `address_helpers.php` | แปลง/รวมที่อยู่จาก POST |
| `foundation_banks.php` | ข้อมูลบัญชีธนาคารมูลนิธิ (helper) |
| `policy_consent_content.php` | เนื้อหานโยบาย (ส่วนแยก) |

## หน้าอื่น / สาธิต
| ไฟล์ | ทำอะไร |
|------|--------|
| `account.php` | บัญชีผู้ใช้ (redirect ตาม role) |
| `detail_pin.php`, `detail_san.php`, `detail_alin.php` | หน้ารายละเอียดตัวอย่าง/สตอรี่ |

---

## สิ่งที่ไม่ใช่ PHP แต่สำคัญต่อการอธิบาย UI
| ไฟล์ | ทำอะไร |
|------|--------|
| `css/welcome.css` | แอนิเมชันหน้า welcome (`@keyframes`) |
| `css/navbar.css` | แถบนำทางร่วม |
| `css/admin_dashboard.css`, `css/admin_escrow.css`, `css/admin_directory.css`, `css/admin_foundation.css` | สไตล์แอดมิน |
| `css/foundation.css`, `css/payment.css`, `css/project.css`, `css/children.css` | สไตล์มูลนิธิ / ชำระเงิน / โครงการ / เด็ก |
| `js/thai_address_select.js` | โหลด JSON จังหวัดและ cascade select |

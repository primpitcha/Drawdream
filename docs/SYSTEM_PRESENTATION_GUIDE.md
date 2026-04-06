# คู่มือนำเสนอระบบ DrawDream — สำหรับกรรมการ / ทีมพัฒนา (ฉบับอัปเดตล่าสุด)

เอกสารนี้มี **สองส่วน**: (ก) สรุปไฟล์โค้ดและโมดูลที่เกี่ยวข้องกับฟีเจอร์หลัก (ข) **บทพรีเซนต์** แบบอ่านต่อเนื่องได้ — ใช้ภาษาเข้าใจง่ายแต่มีความลึกทางเทคนิคพอให้กรรมการเห็นภาพการทำงานของระบบ

> **หมายเหตุความปลอดภัย:** อย่าอ่าน **Secret Key** ของ Omise ออกเสียงในที่ประชุม — อธิบายว่า “เก็บใน `payment/config.php` / บนเซิร์ฟเวอร์” เพียงพอ

---

## ก) สรุปไฟล์โค้ดและโมดูล (อัปเดตจากโครงสร้างปัจจุบัน)

### 1) ชั้นเชื่อมต่อและฐานข้อมูล
| พื้นที่ | ไฟล์ / โฟลเดอร์ | บทบาทโดยสรุป |
|--------|------------------|----------------|
| เชื่อม DB + migration เบื้องต้น | `db.php` | โหลดการเชื่อมต่อ MySQL, เรียก schema helpers (needlist, notifications, ฯลฯ) |
| หมวดบริจาค / polymorphic target | `includes/donate_category_resolve.php` | จับคู่ `donation` กับช่องทาง (เด็ก / โครงการ / สิ่งของ) ให้ถูกต้อง |
| รายการสิ่งของ — โครงสร้างตาราง | `includes/drawdream_needlist_schema.php` | ขยายคอลัมน์รูปหลายไฟล์, **`donate_window_end_at`** (ปิดรับบริจาตามระยะเวลา) |
| ระยะเวลารับบริจาคสิ่งของ | `includes/needlist_donate_window.php` | แปลงข้อความ “ระยะเวลา:” ใน `note` → วันปิดรับ, SQL เงื่อนไข “ยังเปิดรับ” |
| แจ้งเตือน + audit | `includes/notification_audit.php`, `includes/admin_audit_migrate.php` | ตาราง `notifications` + `entity_key` ลดซ้ำ, บันทึกการกระทำแอดมิน |
| อุปการะเดือนเด็ก | `includes/child_sponsorship.php`, `includes/child_omise_subscription.php` | เกณฑ์ยอดรายเดือน, ตาราง subscription Omise; **`drawdream_child_persist_subscription_paid_charge()`** บันทึก **`child_donations` + `donation` + `payment_transaction`** ให้ประวัติผู้บริจาคใน **`profile.php`** เห็นรายการตามเด็กทีละงวด |
| Soft delete | `includes/drawdream_soft_delete.php` (ถ้ามีการเรียก) | เด็ก/โครงการใช้ `deleted_at` แทนลบถาวร |
| โครงการ — สถานะ | `includes/drawdream_project_status.php` | มาตรฐานคำว่า “รออนุมัติ” ในหลายภาษา/รูปแบบในฐานข้อมูล |

### 2) การชำระเงิน Omise (โฟลเดอร์ `payment/`)
| ไฟล์ | บทบาท |
|------|--------|
| `payment/config.php` | **Public/Secret key**, `OMISE_API_URL`, `OMISE_ALLOW_LOCAL_MOCK`, secret สำหรับ cron subscription |
| `payment/omise_helpers.php` | ฟังก์ชันช่วยเรียก API ร่วมกับโครงการ |
| `payment/payment_project.php` | โครงการ: สร้าง PromptPay source + charge → `scan_qr.php`; บันทึก pending ใน `payment_transaction` |
| `payment/foundation_donate.php` | มูลนิธิ (สิ่งของ): charge รวมมูลนิธิ; กรองเฉพาะรายการที่ **ยังเปิดรับ** ตาม `donate_window_end_at` |
| `payment/check_needlist_payment.php` | หลังจ่ายสำเร็จ: แบ่งยอดเข้า `foundation_needlist.current_donate` เฉพาะรายการที่ยังเปิดรับ |
| `payment/child_donate.php` | POST สร้าง charge แบบครั้งเดียว (QR) สำหรับเด็ก |
| `payment/child_subscription_create.php` | สมัครอุปการะแบบงวด: บัตร + Omise Charge Schedule (หรือ fallback cron) |
| `payment/omise_webhook.php` | รับ webhook จาก Omise (เช่น charge.complete) เพื่อบันทึกยอด subscription ผ่าน **`drawdream_child_persist_subscription_paid_charge()`** (รวมแถว **`donation`** สำหรับประวัติผู้บริจาค) |
| `payment/cron_child_subscription_charges.php` | ทางเลือกเมื่อ Schedule สร้างไม่ได้ — หักบนเซิร์ฟเวอร์ด้วย secret |
| `payment/scan_qr.php` | หน้า QR กลาง; ตรวจ `charge_id` / session ให้ตรงประเภท (project / child / foundation) |
| `payment/check_project_payment.php`, `payment/check_child_payment.php` | ยืนยัน charge แล้ว finalize ลง `donation` + อัปเดตยอดโครงการ/เด็ก |

### 3) มูลนิธิ — เด็ก / โครงการ / สิ่งของ
| ฟีเจอร์ | ไฟล์หลัก |
|---------|-----------|
| เพิ่มเด็ก | `foundation_add_children.php` |
| แก้ไขเด็ก | `foundation_edit_child.php` |
| ผลลัพธ์เด็ก (มุมมูลนิธิ) | `foundation_child_outcome.php` |
| เพิ่ม/แก้ไขโครงการ | `foundation_add_project.php` |
| รวมโครงการ | `foundation_merge_project.php` |
| เสนอ/แก้ไขสิ่งของ | `foundation_add_need.php` |
| หน้ามูลนิธิ + รายการของตัวเอง | `foundation.php` |
| แก้โปรไฟล์มูลนิธิ | `foundation_edit_profile.php` (ถ้ามีในโปรเจกต์) |

### 4) แอดมิน
| หน้า | ไฟล์ |
|------|------|
| แดชบอร์ด | `admin_dashboard.php` |
| Escrow / จัดซื้อ | `admin_escrow.php` |
| อนุมัติมูลนิธิ | `admin_approve_foundation.php` |
| อนุมัติเด็ก | `admin_approve_children.php` |
| อนุมัติโครงการ | `admin_approve_projects.php` |
| อนุมัติสิ่งของ | `admin_approve_needlist.php` |
| แจ้งเตือนแอดมิน | `admin_notifications.php` |
| ภาพรวมมูลนิธิ / เด็ก / donor | `admin_foundations_overview.php`, `admin_children_overview.php`, `admin_donors.php` |

### 5) สไตล์ที่เกี่ยวข้องกับหน้าที่กล่าวถึงในบทพรี
- `css/admin_dashboard.css`, `css/admin_escrow.css`, `css/admin_foundation.css`, `css/foundation.css`, `css/payment.css`, `css/project.css`, `css/children.css`

### 6) รายการไฟล์อ้างอิงครบถ้วน — เก็บไว้ทั้งหมดที่สรุปในเอกสารนี้

รายการด้านล่างรวม **ทุกเส้นทางที่ปรากฏในส่วน “ก)” และในบทพรี “ข)”** รวมถึงไฟล์ที่อ้างโดยอ้อม (เช่น หน้าบริจาคเด็ก) เพื่อใช้เป็น **ดัชนีมาสเตอร์** ตอนอ้างอิง repo — ไม่ต้องไล่หาจากหลายตาราง

#### เอกสาร / ดัชนี
- `docs/SYSTEM_PRESENTATION_GUIDE.md` (เอกสารนี้)
- `docs/CODEBASE_FILE_INDEX.md` (ดัชนีไฟล์ PHP + CSS/JS หลัก)
- `docs/PRESENTATION_CODE_WALKTHROUGH_SCRIPT_TH.md` (บทพูดพรีเซนต์โค้ดแบบละเอียด)

#### รากโปรเจกต์ (ล็อกอิน / หน้าหลัก / นำทาง)
- `db.php`
- `login.php`, `logout.php`, `welcome.php`, `homepage.php`
- `navbar.php`, `about.php`, `policy_consent.php`, `mark_notif_read.php`
- `account.php`

#### ผู้บริจาค / สาธารณะ / โปรไฟล์
- `profile.php`, `update_profile.php`, `donor_update_profile.php`, `updateprofile.php`
- `children_.php`, `children_donate.php`
- `project.php`, `project_result.php`
- `foundation.php`, `foundation_public_profile.php`
- `payment.php`

#### มูลนิธิ — จัดการข้อมูล
- `foundation_add_children.php`, `foundation_edit_child.php`, `foundation_child_outcome.php`
- `foundation_add_project.php`, `foundation_merge_project.php`, `foundation_post_update.php`
- `foundation_add_need.php`, `foundation_edit_profile.php`, `foundation_notifications.php`

#### แอดมิน
- `admin_dashboard.php`, `admin_escrow.php`, `admin_notifications.php`
- `admin_approve_foundation.php`, `admin_approve_children.php`, `admin_approve_projects.php`, `admin_approve_needlist.php`
- `admin_children.php`, `admin_children_overview.php`, `admin_projects.php`
- `admin_foundations_overview.php`, `admin_donors.php`

#### โฟลเดอร์ `payment/` (Omise + ยืนยันการชำระ)
- `payment/config.php`
- `payment/omise_helpers.php`
- `payment/payment_project.php`, `payment/foundation_donate.php`, `payment/child_donate.php`
- `payment/child_subscription_create.php`, `payment/omise_webhook.php`
- `payment/cron_child_subscription_charges.php`, `payment/run_subscription_cron.bat`
- `payment/scan_qr.php`, `payment/donate_qr.php`, `payment/abandon_qr.php`
- `payment/check_project_payment.php`, `payment/check_child_payment.php`, `payment/check_needlist_payment.php`

#### โฟลเดอร์ `includes/`
- `includes/donate_category_resolve.php`
- `includes/drawdream_needlist_schema.php`, `includes/needlist_donate_window.php`, `includes/drawdream_project_updates_schema.php`
- `includes/drawdream_project_status.php`, `includes/drawdream_soft_delete.php`
- `includes/notification_audit.php`, `includes/admin_audit_migrate.php`
- `includes/child_sponsorship.php`, `includes/child_omise_subscription.php`
- `includes/pending_child_donation.php`, `includes/payment_transaction_schema.php`
- `includes/qr_payment_abandon.php`, `includes/project_donation_dates.php`
- `includes/omise_api_client.php`, `includes/omise_user_messages.php`
- `includes/thai_address_fields.php`, `includes/address_helpers.php`
- `includes/foundation_banks.php`, `includes/policy_consent_content.php`, `includes/site_footer.php`

#### สไตล์และสคริปต์ที่เกี่ยวข้องกับบทพรี / ดัชนี
- `css/admin_dashboard.css`, `css/admin_escrow.css`, `css/admin_directory.css`, `css/admin_foundation.css`
- `css/foundation.css`, `css/payment.css`, `css/project.css`, `css/children.css`, `css/welcome.css`, `css/navbar.css`
- `js/thai_address_select.js`

#### หน้าอื่นที่มีใน `CODEBASE_FILE_INDEX.md` (เก็บในระบบดัชนีเดียวกัน)
- `detail_pin.php`, `detail_san.php`, `detail_alin.php`

> **หมายเหตุ:** โฟลเดอร์ `sql/` และ `tools/` มีสคริปต์ migration/แก้ข้อมูลย้อนหลัง — ไม่ได้บรรยายทีละไฟล์ในบทพรี แต่ใช้ตอนดูแล production / แก้ category บริจาค ฯลฯ

---

## ข) บทพรีเซนต์สำหรับกรรมการ (อ่านตามลำดับ)

สวัสดีครับ/ค่ะ วันนี้ขอสรุป **DrawDream** ในฐานะแพลตฟอร์มที่เชื่อม **ผู้บริจาค — แอดมิน — มูลนิธิ — เด็ก — โครงการ — รายการสิ่งของ** โดยเน้นว่าเราทำอะไรในโค้ด ใช้ภาษาและเทคนิคอะไร และมี **เงื่อนไขธุรกิจ** อย่างไรให้โปร่งใสและตรวจสอบได้

---

### หัวข้อที่ 1 — การชำระเงินผ่าน Omise โหมดทดสอบ: QR (PromptPay) และบัตร

**ภาพรวมทางเทคนิค**  
ระบบเขียนด้วย **PHP** ฝั่งเซิร์ฟเวอร์ เรียก **Omise REST API** ด้วย **cURL** โดยส่ง **Secret Key** แบบ HTTP Basic (`CURLOPT_USERPWD`) ไปที่ `https://api.omise.co` ค่าที่กำหนดอยู่ใน `payment/config.php` ถ้าคีย์ขึ้นต้นด้วย `pkey_test_` และ `skey_test_` แปลว่าเป็น **โหมดทดสอบ** — **ไม่มีการตัดเงินจริง** เหมาะกับการสาธิตให้กรรมการหรือผู้สนับสนุนเห็น flow ครบ

**ขั้นตอนแบบ QR (ใช้กับโครงการ / บริจาคเด็กครั้งเดียว / บริจาคมูลนิธิแบบสิ่งของ)**  
1. ผู้บริจาคเลือกจำนวนเงินในหน้าเว็บ แล้วกดชำระ  
2. เซิร์ฟเวอร์แปลงจำนวนเป็น **สตางค์** (บาท × 100) ตามที่ Omise กำหนด  
3. สร้าง **Source** ประเภท `promptpay` แล้วสร้าง **Charge** ผูกกับ source นั้น  
4. ได้ `charge_id` และ URI ของ QR — เก็บสถานะชั่วคราวใน **session** แล้วพาไปหน้า **`payment/scan_qr.php`** เพื่อแสดง QR และปุ่มยืนยันหลังโอน  
5. หน้า **`check_*_payment.php`** ที่สอดคล้องกับประเภทการบริจาคจะไป **ดึงสถานะ charge จาก Omise** อีกครั้ง; ถ้าสำเร็จจึง **บันทึกลงฐานข้อมูล** (เช่น `donation` สถานะ `completed`, อัปเดตยอดโครงการหรือเด็ก หรือแบ่งยอดสิ่งของ)

**เทคนิคพิเศษสำหรับการพัฒนา**  
- ถ้าเปิด **`OMISE_ALLOW_LOCAL_MOCK`** เป็น true และใช้คีย์ test — เมื่อ cURL ล้มเหลว ระบบจะใช้ **QR จำลองในเครื่อง** เพื่อทดสอบ flow โดยไม่ต้องมีเน็ต  
- ค่าเริ่มต้นในโปรเจกต์มักตั้งเป็น **false** เพื่อให้ได้ **QR จริงจาก Omise Test** ใกล้เคียงการใช้งานจริงที่สุด  

**ขั้นตอนแบบบัตร — อุปการะเด็กแบบรายงวด**  
1. หน้า **`children_donate.php`** โหลด **Omise.js** จาก CDN แล้วตั้ง **Public Key** ฝั่งเบราว์เซอร์  
2. เมื่อผู้บริจาคเลือกแผนรายเดือน / 6 เดือน / ปี แล้วกดปุ่ม — JavaScript เรียก **`OmiseCard.open`** เพื่อให้ผู้ใช้กรอกบัตรบนฟอร์มที่ Omise จัดการ (ลดความเสี่ยง PCI — เซิร์ฟเวอร์เราไม่เก็บเลขบัตรดิบ)  
3. ได้ **token ชั่วคราว** ส่งมาที่ **`payment/child_subscription_create.php`** ด้วย POST  
4. ฝั่งเซิร์ฟเวอร์สร้างหรือผูก **Customer** ที่ Omise แล้วพยายามสร้าง **Charge Schedule** ตามงวดที่เลือก  
5. ถ้า Omise ต้องการเงื่อนไขเพิ่ม (เช่น **ยืนยันอีเมลบัญชี Omise**) หรือ Schedule สร้างไม่ได้ ระบบมี **แผนสำรอง**: เรียก **`payment/cron_child_subscription_charges.php`** ตามเวลาที่ตั้งบนเซิร์ฟเวอร์ พร้อม **secret** ที่ไม่เปิดเผย  
6. ทุกครั้งที่มีการหักเงินสำเร็จ ควรตั้ง **Webhook** ชี้มาที่ **`payment/omise_webhook.php`** เพื่อให้ยอดถูกบันทึกสอดคล้องกับ Omise — แต่ละงวดที่สำเร็จจะถูกบันทึกผ่าน **`includes/child_omise_subscription.php` → `drawdream_child_persist_subscription_paid_charge()`** ไม่ใช่แค่ **`child_donations`** แต่สร้างแถว **`donation`** (หมวดเด็ก, `target_id` = `child_id`) และ **`payment_transaction`** ด้วย เพื่อให้ผู้บริจาคเห็นใน **ประวัติการบริจาค** ที่ **`profile.php`** เป็นข้อความ **บริจาคให้เด็ก — ชื่อเด็ก** พร้อม **อ้างอิง `chrg_...`** ทีละงวด (เช่นเดียวกับ QR ครั้งเดียวที่ผ่าน **`payment/check_child_payment.php`**)  

**สิ่งที่ควรบอกกรรมการ**  
- เราแยก **ช่องทางชำระ** ชัดเจน: QR สำหรับครั้งเดียว / บัตรสำหรับงวด  
- โหมดทดสอบช่วยให้ **สาธิตครบวงจร** โดยไม่กระทบบัญชีจริง  
- ความปลอดภัย: **Secret Key อยู่ฝั่งเซิร์ฟเวอร์เท่านั้น**; บัตรผ่าน token ของ Omise  

---

### หัวข้อที่ 2 — ฟีเจอร์มูลนิธิ: เสนอโปรไฟล์เด็ก แก้ไข ลบ (Soft delete)

**ภาษาและเทคนิค**  
- **PHP + MySQL** ด้วย **Prepared Statement** ก SQL injection  
- สถานะหลักอยู่ที่คอลัมน์ **`approve_profile`** (เช่น รอดำเนินการ, อนุมัติ, ไม่อนุมัติ, กำลังดำเนินการ) และ **`deleted_at`** สำหรับการ “ลบ” แบบไม่ทำลายข้อมูล  
- การแก้ไขหลังอนุมัติอาจใช้ **`pending_edit_json`** เก็บชุดข้อมูลรอแอดมินรับรองก่อนแสดงแทนของเดิม  

**ลำดับการทำงาน — เสนอเด็ก**  
1. มูลนิธิล็อกอิน role foundation  
2. กรอกฟอร์มใน **`foundation_add_children.php`** — ข้อมูลเช่น ชื่อ, วันเกิด, อายุ, การศึกษา, ความฝัน, ของที่อยากได้, ธนาคาร, รูปเด็ก, QR บัญชี (ถ้ามี)  
3. ระบบตรวจเงื่อนไขเชิงฟอร์ม (เช่น ฟิลด์บังคับ, ชนิดไฟล์รูป, ขนาดไฟล์)  
4. บันทึกลง **`foundation_children`** มักเริ่มที่สถานะรอตรวจ  
5. เรียก **`includes/notification_audit.php`** เพื่อแจ้งแอดมินและมูลนิธิ — ใช้ **`entity_key`** ลดการซ้ำของแจ้งเตือน  

**แก้ไข**  
1. เปิด **`foundation_edit_child.php`** — ดึงเฉพาะแถวที่ `foundation_id` ตรงกับมูลนิธิและ **`deleted_at IS NULL`**  
2. ถ้าเป็นการแก้ไขครั้งใหญ่หลังอนุมัติ ระบบอาจบันทึกเป็น **JSON รออนุมัติ** แทนการทับข้อมูลสาธารณันที  

**ลบ**  
- ไม่ใช่ `DELETE` จากตารางทันที แต่เป็น **Soft delete**: ตั้ง **`deleted_at`** (และอาจมีเหตุผล) เพื่อให้ audit และประวัติยังตรวจสอบได้ — สอดคล้องกับหลักธรรมาภิบาลข้อมูล  

**เงื่อนไขที่เกี่ยวกับการบริจาค**  
- หน้าบริจาคจะดึงเด็กที่ **`approve_profile` อยู่ในกลุ่มที่อนุญาต**, **ไม่ถูกซ่อน**, **ไม่ถูกลบ**  
- ฟังก์ชัน **`drawdream_child_can_receive_donation()`** ใน `includes/child_sponsorship.php` ใช้ตรวจว่า **เดือนนี้ยังไม่ “อุปการะครบเกณฑ์”** และเด็กคนนั้นยัง **ไม่มี subscription Omise แบบ active** (ถ้ามีแล้วจะไม่เปิดรับ QR ครั้งเดียว) — เกณฑ์ยอดรายเดือนนิยามเป็นค่าคงที่ในโค้ดและคำนวณด้วย **timezone Asia/Bangkok**  
- **ประวัติผู้บริจาค (รายบุคคล):** role donor ที่ **`profile.php`** ดึงจากตาราง **`donation`** join **`donate_category`** และ **`foundation_children`** — การอุปการะแบบงวดแต่ละครั้งที่บันทึกผ่าน **`drawdream_child_persist_subscription_paid_charge()`** จึงแสดงเป็นทีละรายการตามเด็ก (รายการเก่าที่มีเฉพาะ **`child_donations`** โดยไม่มีแถว **`donation`** อาจไม่ขึ้นในประวัติจนกว่าจะมีงวดใหม่หลังอัปเดตโค้ด)  

---

### หัวข้อที่ 3 — ฟีเจอร์มูลนิธิ: เสนอโครงการ แก้ไข ลบ

**เทคนิค**  
- ข้อมูลหลักใน **`foundation_project`**: ชื่อโครงการ, มูลนิธิ, เป้าเงิน, วันเริ่ม–จบ, รูป, คำอธิบาย, SDG หมวด, **`project_status`**, **`deleted_at`**  
- สถานะ “รออนุมัติ” รองรับหลายรูปแบบในฐานข้อมูลเดิมผ่าน **`includes/drawdream_project_status.php`**  

**ลำดับ — เสนอโครงการ**  
1. มูลนิธิกรอกใน **`foundation_add_project.php`**  
2. ตรวจสอบฟิลด์บังคับ เช่น ชื่อโครงการ, เป้าหมายเงิน, ช่วงวันที่, รูป (ชนิด/ขนาด)  
3. บันทึกด้วยสถานะ **รอแอดมิน** (`pending` ในรูปแบบที่ระบบรองรับ)  
4. แจ้งเตือนไปยังแอดมิน  

**แก้ไข**  
- โหมดแก้ไขในไฟล์เดียวกัน ตรวจสอบว่าโครงการเป็นของมูลนิธินั้นจริง และ **`deleted_at IS NULL`**  
- บางฟิลด์อาจล็อกเมื่อสถานะเปลี่ยนแล้ว (เช่น หลังระดมทุนเสร็จ) — ตาม query ในไฟล์  

**ลบ**  
- **Soft delete** ด้วย **`deleted_at`** — รายการไม่หายจากระบบ แต่ถูกกรองออกจากหน้าสาธารณะและ workflow หลัก  

**เงื่อนไขการบริจาคโครงการ**  
- หน้า **`payment/payment_project.php`** อนุญาตเฉพาะโครงการที่สถานะอนุญาตระดมทุน และ **ยังไม่เลย `end_date`** ตามเวลาไทย  

**ฟีเจอร์เสริม**  
- **`foundation_merge_project.php`**: รวมยอด/ความสัมพันธ์ระหว่างโครงการภายใต้เงื่อนไขในโค้ด (เช่น โครงการปลายทางต้อง `approved` และยังไม่ถูก merge)  

---

### หัวข้อที่ 4 — ฟีเจอร์มูลนิธิ: เสนอสิ่งของ แก้ไข และระยะเวลารับบริจาค

**เทคนิค**  
- ตาราง **`foundation_needlist`**: รายการ, รูปได้ถึงสามไฟล์, รูปมูลนิธิประกอบ, ยอดเป้า **`total_price`**, ยอดสะสม **`current_donate`**, สถานะ **`approve_item`**, ฟิลด์ **`note`** (บรรทัดแรกเก็บข้อความ `ระยะเวลา: …`), และ **`donate_window_end_at`**  

**ลำดับ — เสนอสิ่งของ**  
1. มูลนิธิต้อง **ผ่านการยืนยันบัญชี** (`account_verified`) ตามที่ UI กำหนด  
2. กรอกหมวดสิ่งของ, รายการย่อย, เป้าหมายเงิน, ความเร่งด่วน, รูป, **เลือกระยะเวลา** จาก dropdown  
3. ระบบบังคับ **เลือกระยะเวลา** และผูกเป็นข้อความใน `note`  
4. บันทึกด้วย **`approve_item = 'pending'`** แล้วแจ้งแอดมิน  

**หลังแอดมินอนุมัติ**  
- **`admin_approve_needlist.php`** อ่านระยะเวลาจาก `note` แล้วตั้ง **`donate_window_end_at`** จากเวลาอนุมัติ เช่น สัปดาห์ = +7 วัน, เดือน = +1 เดือน, ฯลฯ — **“ครั้งเดียว (ไม่ซ้ำ)” ไม่ตั้งวันปิดตามเวลา**  

**แก้ไข**  
- **`foundation_add_need.php`**: ถ้ารายการ **อนุมัติแล้ว** และมูลนิธิแก้ระยะเวลา ระบบจะ **คำนวณ `donate_window_end_at` ใหม่** โดยยึด **`reviewed_at`** เดิมเป็นจุดเริ่ม — สอดคล้องกับนโยบาย “นับจากตอนอนุมัติ”  

**การรับบริจาค**  
- **`payment/foundation_donate.php`** และ **`payment/check_needlist_payment.php`** นับเฉพาะรายการที่ **`approve_item = 'approved'`** และ **ยังไม่ถึงเวลาปิด**  
- มูลนิธิเห็นป้าย **ปิดรับบริจาคแล้ว** บน **`foundation.php`** เมื่อเลยกำหนด  

---

### หัวข้อที่ 5 — ส่วนแอดมิน: การแจ้งเตือน

**แนวคิด**  
- ตาราง **`notifications`**: ผู้รับ, หัวข้อ, ข้อความ, ลิงก์, อ่าน/ยังไม่อ่าน, เวลา  
- เพิ่ม **`entity_key`** เช่น `adm_pending_need:123` เพื่อ **แทนที่แจ้งเตือนเดิม** แทนการสะสมซ้ำเป็นสิบแถวของคิวเดียวกัน  

**ลำดับการทำงาน**  
1. เหตุการณ์สำคัญ (ส่งคำขออนุมัติ, อนุมัติ, ปฏิเสธ, โครงการครบยอด, สิ่งของจัดซื้อเสร็จ) เรียก **`drawdream_send_notification()`**  
2. ฟังก์ชันจะ **ลบแจ้งเตือนเก่าที่ entity_key เดียวกัน** แล้ว insert แถวใหม่  
3. แอดมินอ่านได้ที่ **`admin_notifications.php`**; มูลนิธิ/ผู้ใช้อ่านได้ที่หน้าแจ้งเตือนของแต่ละ role  
4. เมื่อตัดสินใจคิวอนุมัติแล้ว เรียก **`drawdream_notifications_delete_by_entity_key`** เพื่อเคลียร์คิว “รออนุมัติ”  

**สิ่งที่กรรมการได้ยิน**  
- ระบบลด noise และทำให้ **นิทรรศการการตัดสินใจ** ชัด — เห็นว่าแต่ละคิวมีหนึ่งแจ้งเตือนที่อัปเดตได้  

---

### หัวข้อที่ 6 — การอนุมัติ / ไม่อนุมัติ ทุกฟีเจอร์

**มูลนิธิ — `admin_approve_foundation.php`**  
- อนุมัติ: ตั้งบัญชีพร้อมใช้งาน (เช่น `account_verified`)  
- ไม่อนุมัติ: มักมีผลต่อการลบหรือปิดบัญชีตาม logic ในไฟล์ — **แอดมินกรอกเหตุผล** ถ้าบังคับ  

**เด็ก — `admin_approve_children.php`**  
- **อนุมัติครั้งแรก**: ตั้ง `อนุมัติ`, บันทึก `reviewed_at`, ตั้ง `first_approved_at` ถ้ายังว่าง  
- **อนุมัติการแก้ไข**: นำ JSON จาก `pending_edit_json` ไป merge ลงคอลัมน์จริง แล้วล้าง JSON  
- **ไม่อนุมัติ**: **บังคับเหตุผล**; ถ้าเป็นการปฏิเสธการแก้ไข ข้อมูลสาธารณะยังเป็นชุดเดิม  

**โครงการ — `admin_approve_projects.php`**  
- อนุมัติ/ปฏิเสธเฉพาะแถวที่สถานะ **ยังเป็น pending** และ **ไม่ถูก soft delete**  
- ส่งแจ้งเตือนมูลนิธิพร้อมลิงก์ที่เกี่ยวข้อง (เช่น หน้าชำระเงินโครงการเมื่ออนุมัติ)  
- บันทึก **`drawdream_log_admin_action`**  

**สิ่งของ — `admin_approve_needlist.php`**  
- อนุมัติ: `approved` + `reviewed_at` + คำนวณ **`donate_window_end_at`**  
- ปฏิเสธ: **บังคับเหตุผลใน note** ตาม validation  

---

### หัวข้อที่ 7 — หน้าแดชบอร์ดแอดมิน และ Escrow

**`admin_dashboard.php` — ภาษา PHP + Chart.js**  
- รวมยอดบริจาคทั้งหมด / วันนี้ จากตาราง **`donation`** เฉพาะ `payment_status = 'completed'`  
- นับจำนวนผู้บริจาค, มูลนิธิ, เด็กที่ยังไม่ถูกลบ  
- การ์ด **รออนุมัติ**: มูลนิธิ (`account_verified=0`), โครงการ pending, สิ่งของ pending  
- การ์ด **เงินใน Escrow** ดึงจาก **`escrow_funds`** สถานะ `holding`  
- กราฟ 30 วัน: aggregate รายวันจาก `donation`  
- ตารางบริจาคล่าสุด: join **`donate_category`** และ **`payment_transaction`** เพื่อเห็นช่องทางและ `omise_charge_id`  

**`admin_escrow.php` — ธุรกิจระหว่าง “เงินถึงเป้า” กับ “โอน/จัดซื้อจริง”**  
- **โครงการ**: แสดงโครงการที่ยอดครบหรืออยู่ในขั้น **`completed` / `purchasing`**; แอดมินกดยืนยันโอน → อัปเดตสถานะเป็น **`purchasing`** และแจ้งมูลนิธิให้ไปอัปเดตความคืบหน้า  
- **สิ่งของ**:  
  - รายการที่ **อนุมัติแล้วแต่ยอดยังไม่ครบ** อยู่ในกลุ่มติดตาม  
  - เมื่อ **`current_donate >= total_price`** แสดงในกลุ่มพร้อมจัดซื้อ  
  - แอดมินกด **เริ่มจัดซื้อ** → `approve_item = 'purchasing'`  
  - อัปโหลดหลักฐาน → บันทึก **`evidence`**, ตั้งสถานะ **`done`**, แจ้งมูลนิธิ  

**สิ่งที่กรรมการได้ความมั่นใจ**  
- เงินและสถานะโครงการ/สิ่งของ **ไม่ใช่แค่ตัวเลขบนหน้าเว็บ** แต่มี **workflow หลังครบยอด** แยกจากการระดมทุนเปิดหน้า  

---

### ปิดท้าย (30 วินาที)

DrawDream ใช้ **PHP + MySQL** ฝั่งเซิร์ฟเวอร์, **Omise** สำหรับ QR และบัตรในโหมดทดสอบ, มี **workflow อนุมัติ**, **แจ้งเตือนแบบไม่ซ้ำ**, **soft delete**, **ระยะเวลารับบริจาคสิ่งของ**, และ **แดชบอร์ด + Escrow** เพื่อให้กระบวนการมูลนิธิโปร่งใสและตรวจสอบย้อนหลังได้ครับ/ค่ะ  

---

*อัปเดตให้สอดคล้องโค้ดใน repo — รายการไฟล์ครบถ้วนสำหรับการอ้างอิงอยู่ที่ **หัวข้อ ก) ข้อ 6** ด้านบน, `docs/CODEBASE_FILE_INDEX.md` และบทพูดละเอียดที่ `docs/PRESENTATION_CODE_WALKTHROUGH_SCRIPT_TH.md`*  

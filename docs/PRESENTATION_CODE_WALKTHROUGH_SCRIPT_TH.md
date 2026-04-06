# บทพูดพรีเซนต์โค้ด DrawDream (เชิงลึก + ขั้นตอนสำคัญ)

เอกสารนี้ใช้คู่กับ `SYSTEM_PRESENTATION_GUIDE.md` และ `CODEBASE_FILE_INDEX.md` — **อ่านเป็นบทพูดได้ตรงๆ** ปรับคำเรียกขาน/ย่อยาวตามเวลาที่มี

> **กฎความปลอดภัยตอนพรี:** อย่าอ่าน **Secret Key** ของ Omise ออกเสียงหรือโชว์บนสไลด์ — บอกแค่ว่าเก็บใน `payment/config.php` บนเซิร์ฟเวอร์ และแยก Test/Live

---

## เปิดเรื่อง (ประมาณ 1 นาที)

สวัสดีครับ/ค่ะ วันนี้จะพาไล่ **DrawDream** ในมุม **โค้ดจริงกับงานจริง** ว่าระบบเชื่อม **ผู้บริจาค — Omise — ฐานข้อมูล — มูลนิธิ — แอดมิน** อย่างไร

โครงสร้างหลักคือ **PHP ฝั่งเซิร์ฟเวอร์ + MySQL** ชำระเงินผ่าน **Omise** ได้สองแบบหลักคือ **สแกน QR PromptPay ครั้งเดียว** กับ **บัตรแบบงวด** สำหรับอุปการะเด็ก เราใช้ **โหมดทดสอบ** ของ Omise ได้เต็ม flow โดย **ไม่ตัดเงินจริง**

ถ้าเห็นคีย์ในโค้ดขึ้นต้น `pkey_test_` กับ `skey_test_` แปลว่าเป็น **Test mode** — เหมาะกับการสาธิตให้กรรมการหรือทีมเห็นภาพครบ

---

## บล็อกที่ 1 — Omise Test mode ตั้งค่าอยู่ที่ไหน (ประมาณ 2 นาที)

จุดศูนย์กลางคือไฟล์ **`payment/config.php`**

ตรงนี้กำหนด **Public Key** ให้ฝั่งเบราว์เซอร์ เช่นเปิดฟอร์มบัตรผ่าน Omise.js และกำหนด **Secret Key** ให้เซิร์ฟเวอร์เรียก API ด้วย cURL แบบ HTTP Basic ไปที่ **`OMISE_API_URL`** ปกติคือ `https://api.omise.co`

มีตัวแปร **`OMISE_ALLOW_LOCAL_MOCK`** — ถ้าเป็น true และใช้คีย์ test ตอน cURL ล้ม ระบบจะใช้ QR จำลองในเครื่องได้ แต่ในโปรเจกต์เรามักตั้ง **false** เพื่อให้ได้ **QR จริงจาก Omise Test** ใกล้ production ที่สุด

**สิ่งที่ควรพูดต่อกรรมการ:** Secret อยู่ฝั่งเซิร์ฟเวอร์เท่านั้น ไม่ส่งไปฝั่ง client; บัตรผ่าน **token** ของ Omise เราไม่เก็บเลขบัตรดิบ

**โชว์บนจอได้:** บรรทัด `define('OMISE_API_URL'...)` และคอมเมนต์อธิบาย Test/Live — **เบลอหรือตัดบรรทัดคีย์**

---

## บล็อกที่ 2 — QR PromptPay: ขั้นตอนในโค้ด (ประมาณ 4–5 นาที)

### ภาพรวมที่พูด

ผู้ใช้เลือกจำนวนเงินแล้วกดชำระ เซิร์ฟเวอร์แปลงเงินเป็นสตางค์ สร้าง **Source** ประเภท PromptPay แล้วสร้าง **Charge** ได้ `charge_id` กับ QR จาก Omise จากนั้นพาไปหน้า **`payment/scan_qr.php`** ให้สแกนและกดยืนยันหลังโอน

### เส้นทางไฟล์ที่ควรพูดทีละขั้น

1. **โครงการ** — มักเริ่มที่ **`payment/payment_project.php`**: สร้าง source + charge บันทึกสถานะค้างใน **`payment_transaction`** / session ตามที่โค้ดกำหนด แล้ว redirect ไปสแกน QR  
2. **เด็ก แบบครั้งเดียว** — **`payment/child_donate.php`**: รับ POST จำนวนเงิน ตรวจว่าเด็กยังรับบริจาคได้ด้วย **`drawdream_child_can_receive_donation()`** ใน **`includes/child_sponsorship.php`** จากนั้นสร้าง charge แบบเดียวกับโครงการในแนวคิด Omise  
3. **มูลนิธิ / สิ่งของ** — **`payment/foundation_donate.php`**: รวมยอดหลายรายการที่ **ยังเปิดรับ** ตามระยะเวลา — เกี่ยวกับ **`donate_window_end_at`** และ helper ใน **`includes/needlist_donate_window.php`**

หลังผู้ใช้สแกนแล้ว หน้า **`payment/check_project_payment.php`**, **`payment/check_child_payment.php`**, หรือ **`payment/check_needlist_payment.php`** จะไป **ดึงสถานะ charge จาก Omise อีกครั้ง** ถ้าสำเร็จจึง **insert ตาราง `donation`** สถานะ completed และอัปเดตยอดปลายทาง — เช่นโครงการหรือเด็ก

**เงื่อนไขสำคัญที่ต้องพูด:**  
- ช่องทางแต่ละแบบมีไฟล์ check ของตัวเอง เพื่อไม่ปน charge คนละประเภท  
- สิ่งของต้องผ่านเงื่อนไข **อนุมัติแล้ว + ยังไม่ปิดรับตามเวลา**

---

## บล็อกที่ 3 — บัตร + อุปการะเด็กแบบงวด (ประมาณ 4–5 นาที)

### ภาพรวมที่พูด

หน้า **`children_donate.php`** โหลด **Omise.js** จาก CDN แล้วตั้ง Public Key จาก config เมื่อผู้บริจาคเลือกแผนรายเดือน / หกเดือน / ปี JavaScript เรียก **`OmiseCard.open`** ได้ **token ชั่วคราว** ส่ง POST ไป **`payment/child_subscription_create.php`**

ฝั่งเซิร์ฟเวอร์สร้างหรือผูก **Customer** ที่ Omise แล้วพยายามสร้าง **Charge Schedule** ถ้า Omise ไม่ให้สร้าง — เช่นต้องยืนยันอีเมลบัญชี Omise — ระบบมีทางเลือก **cron** ที่ **`payment/cron_child_subscription_charges.php`** พร้อม **secret** ที่ไม่เปิดเผย ตามที่อธิบายใน comment ของ `payment/config.php`

ข้อมูล subscription ฝั่งเราอยู่ที่ **`includes/child_omise_subscription.php`** — มีตาราง **`child_omise_subscription`** เก็บ `child_id`, `donor_user_id`, `omise_schedule_id`, `status` ฯลฯ

**Webhook:** ทุกครั้งที่มีการหักสำเร็จ ควรตั้ง Webhook ชี้มาที่ **`payment/omise_webhook.php`** เพื่อให้ยอดสะสมใน **`child_donations`** สอดคล้องกับ Omise  

**ประวัติผู้บริจาค (รายบุคคล):** หน้า **`profile.php`** (role donor) แสดงประวัติจากตาราง **`donation`** ไม่ใช่จาก **`child_donations`** โดยตรง — ดังนั้นแต่ละงวดที่หักจาก subscription ต้องสร้างแถว **`donation`** (หมวดเด็ก, `target_id` = เด็ก) และ **`payment_transaction`** ด้วย ซึ่งทำใน **`drawdream_child_persist_subscription_paid_charge()`** เดียวกับที่ webhook / cron / งวดแรกตอนสมัครเรียก ผลคือผู้บริจาคเห็นรายการ **บริจาคให้เด็ก — ชื่อเด็กที่อุปการะ** ทีละงวด พร้อม **อ้างอิง charge** เหมือนบริจาค QR ครั้งเดียวที่ผ่าน **`check_child_payment.php`**

### เงื่อนไขธุรกิจที่ควรพูดชัด

- **`drawdream_child_can_start_omise_subscription()`** — ไม่ให้สมัครถ้าเด็กยังไม่อนุมัติ/ถูกซ่อน/ถูกลบ และ **ถ้ามี subscription active ของเด็กคนนั้นแล้ว ไม่ให้คนอื่นสมัครซ้ำ**  
- **`drawdream_child_can_receive_donation()`** — หยุดรับ **QR ครั้งเดียว** เมื่อ **ครบเกณฑ์ยอดรอบเดือน** (เช่น 20,000 บาทในรอบปฏิทิน Bangkok ตาม `first_approved_at`) **หรือ** เมื่อมี **subscription active** ของเด็กคนนั้นแล้ว  
- รายชื่อเด็ก **`children_.php`** แยกกลุ่ม **“เด็กที่มีผู้อุปการะ”** เมื่อ **ครบเกณฑ์รอบเดือน** หรือ **มีแผน Omise active** — ใช้ฟังก์ชันเช่น **`drawdream_child_is_showcase_sponsored()`** และแผนที่ subscription แบบ batch

### ผลลัพธ์ที่มูลนิธิกรอก

- **`foundation_child_outcome.php`** — มูลนิธิแก้ข้อความ **`sponsor_outcome_text`** ได้เมื่อ **อุปการะครบยอดในเดือน** หรือ **มี subscription active**  
- บนหน้า **`children_donate.php`** ถ้ามีผู้อุปการะแบบงวดแล้ว ผู้บริจาคจะเน้นเห็น **ผลลัพธ์** (หรือข้อความรอมูลนิธิกรอก) แทนช่องบริจาค

---

## บล็อกที่ 4 — เด็ก: workflow มูลนิธิ → แอดมิน → สาธารณะ (ประมาณ 3 นาที)

1. มูลนิธิสร้างโปรไฟล์ใน **`foundation_add_children.php`** บันทึก **`foundation_children`** มักเริ่มสถานะรอตรวจ  
2. แอดมินตรวจที่ **`admin_approve_children.php`** — อนุมัติครั้งแรกตั้ง **`อนุมัติ`**, **`reviewed_at`**, และ **`first_approved_at`** ถ้ายังว่าง  
3. การแก้ไขหลังอนุมัติอาจไปที่ **`pending_edit_json`** รอ merge เมื่อแอดมินอนุมัติการแก้ไข  
4. **Soft delete:** ใช้ **`deleted_at`** ไม่ลบถาวร — สอดคล้อง audit  
5. หน้าสาธารณะ **`children_.php`** กรองเฉพาะเด็กที่ **`approve_profile`** อยู่ในกลุ่มที่แสดงได้, **ไม่ซ่อน**, **ไม่ถูกลบ**

**เกณฑ์ยอดรายเดือน:** นิยามใน **`includes/child_sponsorship.php`** เป็นค่าคงที่ **`DRAWDREAM_CHILD_MONTH_SPONSOR_THRESHOLD`** และคำนวณรอบด้วย **Asia/Bangkok** กับ anchor จาก **`first_approved_at` / `reviewed_at`**

---

## บล็อกที่ 5 — โครงการ (ประมาณ 2 นาที)

- ข้อมูลหลัก **`foundation_project`**: เป้าเงิน, วันเริ่ม–จบ, สถานะ, **`deleted_at`**  
- มูลนิธิเสนอ/แก้ไข **`foundation_add_project.php`**  
- แอดมิน **`admin_approve_projects.php`** — อนุมัติเฉพาะแถวที่ยัง pending และไม่ถูก soft delete  
- สถานะ “รออนุมัติ” ในฐานข้อมูลเดิมอาจหลากรูปแบบ — มี **`includes/drawdream_project_status.php`** ช่วย normalize  
- ชำระเงิน **`payment/payment_project.php`** มักตรวจว่าโครงการ **อนุญาตระดมทุน** และ **ยังไม่เลย end_date** ตามเวลาไทย  
- เสริม: **`foundation_merge_project.php`** รวมโครงการภายใต้เงื่อนไขในโค้ด

---

## บล็อกที่ 6 — มูลนิธิ + สิ่งของ + ระยะเวลารับบริจาค (ประมาณ 3 นาที)

- ตาราง **`foundation_needlist`**: เป้า **`total_price`**, ยอดสะสม **`current_donate`**, สถานะ **`approve_item`**, ฟิลด์ **`note`**, และ **`donate_window_end_at`**  
- มูลนิธิกรอก **`foundation_add_need.php`** — บังคับเลือกระยะเวลา ผูกเป็นข้อความใน `note`  
- แอดมิน **`admin_approve_needlist.php`**: เมื่ออนุมัติจะคำนวณ **`donate_window_end_at`** จากเวลาอนุมัติตามช่วงที่เลือก  
- **`payment/foundation_donate.php`** และ **`payment/check_needlist_payment.php`** กรองเฉพาะรายการ **approved** และ **ยังไม่ปิดรับ**  
- หน้า **`foundation.php`** แสดงสถานะปิดรับเมื่อเลยกำหนด

---

## บล็อกที่ 7 — แอดมิน: แจ้งเตือน, แดชบอร์ด, Escrow (ประมาณ 3 นาที)

**แจ้งเตือน:** **`includes/notification_audit.php`** — ตาราง **`notifications`** มี **`entity_key`** เช่น `adm_pending_need:123` เพื่อ **แทนที่แจ้งเตือนเดิม** ไม่ให้คิวเดียวกันซ้อนเป็นสิบแถว

**แดชบอร์ด:** **`admin_dashboard.php`** — ยอดรวมจาก **`donation`** ที่ `payment_status = 'completed'`, การ์ดรออนุมัติ, กราฟ 30 วัน

**Escrow:** **`admin_escrow.php`** — workflow หลังครบยอดโครงการหรือสิ่งของ: ยืนยันโอน/เริ่มจัดซื้อ อัปโหลดหลักฐาน เปลี่ยนสถานะจนจบ — เงินไม่ใช่แค่ตัวเลขบนหน้าเว็บ แต่มีขั้นตอนหลังระดมทุน

---

## ปิดท้าย + Q&A (ประมาณ 1 นาที)

สรุปอีกครั้ง: DrawDream ใช้ **PHP + MySQL**, ชำระผ่าน **Omise** แยกชัด **QR ครั้งเดียว** กับ **บัตรแบบงวดสำหรับเด็ก**, มี **workflow อนุมัติ**, **soft delete**, **ระยะเวลารับบริจาคสิ่งของ**, **เกณฑ์อุปการะเดือน + subscription**, และ **แดชบอร์ด + Escrow** เพื่อความโปร่งใส

ถ้ามีคำถามเรื่อง **Test vs Live** ตอบสั้นๆ ว่าเปลี่ยนคีย์ใน `payment/config.php` / env บนเซิร์ฟเวอร์ และตั้ง Webhook ให้ชี้ endpoint เดียวกับโดเมนจริง

---

## แช็กลิสต์ก่อนขึ้นเวที

- [ ] สไลด์/จอ demo: ใช้บัญชี Omise **Test** และคีย์ **test** คู่กัน  
- [ ] ลบหรือเบลอ Secret Key ทุกที่  
- [ ] เตรียมลิงก์ทดสอบ: หน้าโครงการ → ชำระ → `scan_qr.php`  
- [ ] เตรียมลิงก์: `children_donate.php?id=…` สำหรับบัตรงวด  
- [ ] ยืนยันว่า **Webhook** ชี้ `payment/omise_webhook.php` ใน Dashboard (Test)  
- [ ] ถ้าสาธิต Schedule ไม่ได้สร้าง อธิบายทางเลือก **cron** + secret โดยไม่อ่านค่า secret ออกเสียง

---

*อัปเดตให้สอดคล้องโค้ดใน repo — รายการไฟล์ครบถ้วนเพิ่มเติมดู `docs/CODEBASE_FILE_INDEX.md` และ `docs/SYSTEM_PRESENTATION_GUIDE.md`*

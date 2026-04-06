# เอกสารโปรเจกต์ DrawDream

| ไฟล์ | ใช้ทำอะไร |
|------|------------|
| [SYSTEM_PRESENTATION_GUIDE.md](SYSTEM_PRESENTATION_GUIDE.md) | บทพรี + สรุปโมดูล; **หัวข้อ ก) ข้อ 6** = รายการไฟล์อ้างอิงครบถ้วน (มาสเตอร์ลิสต์) เก็บทุกเส้นทางที่สรุปในเอกสารเดียวกัน — รวมเงื่อนไข **ประวัติบริจาคเด็กรายงวด → `profile.php`** (ผ่าน `donation` + `drawdream_child_persist_subscription_paid_charge`) |
| [CODEBASE_FILE_INDEX.md](CODEBASE_FILE_INDEX.md) | ดัชนีไฟล์ PHP + CSS/JS หลัก ทีละบทบาท — อ้างคู่กับมาสเตอร์ลิสต์ใน `SYSTEM_PRESENTATION_GUIDE.md` |
| [PRESENTATION_CODE_WALKTHROUGH_SCRIPT_TH.md](PRESENTATION_CODE_WALKTHROUGH_SCRIPT_TH.md) | บทพูดพรีเซนต์โค้ด (Omise / เด็ก / มูลนิธิ / แอดมิน) — อัปเดตประกอบประวัติผู้บริจาคกับ subscription เด็ก |

**มาตรฐานหัวไฟล์:** ทุกไฟล์ `.php` บรรทัดถัดจาก `<?php` ใช้รูปแบบ `// path/จากรากโปรเจกต์.php — คำอธิบาย`  
รันซ้ำได้: `php tools/apply_one_line_headers.php` (แผนที่คำอธิบายอยู่ในไฟล์นั้น)

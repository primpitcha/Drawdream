<?php
// includes/drawdream_project_status.php — มาตรฐานสถานะโครงการ + normalize DB
// โครงการบางแถวเก็บสถานะภาษาไทย (เช่น รอดำเนินการ) แต่โค้ดส่วนใหญ่ใช้ pending/approved/rejected — ปรับค่าและเงื่อนไขให้สอดคล้องกัน

declare(strict_types=1);

/** อัปเดตค่าเก่าในตารางให้ใช้รหัสภาษาอังกฤษ (รันได้ซ้ำ ไม่กระทบแถวที่ถูกต้องแล้ว) */
function drawdream_normalize_foundation_project_statuses(mysqli $conn): void
{
    $conn->query(
        "UPDATE foundation_project SET project_status = 'pending'
         WHERE TRIM(COALESCE(project_status,'')) IN ('รอดำเนินการ','รอดำนิการ','Pending','PENDING')
         AND deleted_at IS NULL"
    );
    $conn->query(
        "UPDATE foundation_project SET project_status = 'approved'
         WHERE TRIM(COALESCE(project_status,'')) IN ('อนุมัติ','Approved','APPROVED')
         AND deleted_at IS NULL"
    );
    $conn->query(
        "UPDATE foundation_project SET project_status = 'rejected'
         WHERE TRIM(COALESCE(project_status,'')) IN ('ไม่อนุมัติ','ปฏิเสธ','Rejected','REJECTED')
         AND deleted_at IS NULL"
    );
    $conn->query(
        "UPDATE foundation_project SET project_status = 'completed'
         WHERE TRIM(COALESCE(project_status,'')) IN ('เสร็จสิ้น','สำเร็จ','Completed','COMPLETED')
         AND deleted_at IS NULL"
    );
}

/**
 * นิพจน์ SQL สำหรับ WHERE — โครงการรอแอดมินอุมัติ
 *
 * @param string $col เช่น "project_status" หรือ "p.project_status"
 */
function drawdream_sql_project_is_pending(string $col = 'project_status'): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_.]/', '', $col);
    if ($safe === '') {
        $safe = 'project_status';
    }
    return "(LOWER(TRIM(COALESCE({$safe},''))) = 'pending' OR TRIM({$safe}) IN ('รอดำเนินการ','รอดำนิการ'))";
}

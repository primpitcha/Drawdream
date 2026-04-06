<?php
// tools/backfill_needlist_donations_omise.php — แก้ donation หมวดสิ่งของเก่า จาก metadata charge Omise
// ใช้: php tools/backfill_needlist_donations_omise.php           (dry-run)
//       php tools/backfill_needlist_donations_omise.php --write  (บันทึก)
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "รันผ่าน CLI: php tools/backfill_needlist_donations_omise.php [--write]\n");
    exit(1);
}

$write = in_array('--write', $argv, true);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/payment/config.php';
require_once dirname(__DIR__) . '/payment/omise_helpers.php';

$sql = "
SELECT d.donate_id, pt.omise_charge_id, d.donor_id, d.target_id
FROM donation d
INNER JOIN payment_transaction pt ON pt.donate_id = d.donate_id
INNER JOIN donate_category dc ON dc.category_id = d.category_id
  AND TRIM(COALESCE(dc.needitem_donate, '')) NOT IN ('', '-')
WHERE LOWER(TRIM(d.payment_status)) = 'completed'
  AND (
    d.donor_id IS NULL OR d.donor_id = 0
    OR d.target_id IS NULL OR d.target_id = 0
  )
  AND pt.omise_charge_id IS NOT NULL
  AND TRIM(pt.omise_charge_id) <> ''
ORDER BY d.donate_id
";

$res = $conn->query($sql);
if ($res === false) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . "\n");
    exit(1);
}

$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmtUpd = $conn->prepare('UPDATE donation SET donor_id = ?, target_id = ? WHERE donate_id = ?');

foreach ($rows as $row) {
    $donateId = (int)$row['donate_id'];
    $chargeId = trim((string)$row['omise_charge_id']);
    $curDonor = (int)$row['donor_id'];
    $curTarget = (int)$row['target_id'];

    $charge = drawdream_omise_fetch_charge($chargeId);
    if ($charge === null) {
        echo "[skip] donate_id={$donateId} charge={$chargeId} (Omise ไม่ตอบ)\n";
        continue;
    }
    if (($charge['object'] ?? '') === 'error') {
        echo "[skip] donate_id={$donateId} charge={$chargeId} (error)\n";
        continue;
    }

    $meta = $charge['metadata'] ?? [];
    if (!is_array($meta)) {
        $meta = [];
    }
    $metaDonor = (int)($meta['donor_id'] ?? 0);
    $metaFid = (int)($meta['foundation_id'] ?? 0);

    if ($metaDonor <= 0 && $metaFid <= 0) {
        echo "[skip] donate_id={$donateId} charge={$chargeId} (ไม่มี donor_id/foundation_id ใน metadata)\n";
        continue;
    }

    $newDonor = $curDonor > 0 ? $curDonor : $metaDonor;
    $newTarget = $curTarget > 0 ? $curTarget : $metaFid;

    if ($newDonor <= 0 || $newTarget <= 0) {
        echo "[skip] donate_id={$donateId} (ยังไม่ครบ donor={$newDonor} foundation={$newTarget})\n";
        continue;
    }

    if (!$write) {
        echo "[dry-run] donate_id={$donateId} → donor_id={$newDonor} target_id={$newTarget}\n";
        continue;
    }

    $stmtUpd->bind_param('iii', $newDonor, $newTarget, $donateId);
    $stmtUpd->execute();
    if ($stmtUpd->affected_rows >= 0) {
        echo "[ok] donate_id={$donateId} donor_id={$newDonor} target_id={$newTarget}\n";
    }
}

if (!$write && count($rows) > 0) {
    echo "\nบันทึกจริง: php tools/backfill_needlist_donations_omise.php --write\n";
}

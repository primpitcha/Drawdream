<?php
// includes/pending_child_donation.php — รอชำระ PromptPay: เก็บเฉพาะ payment_transaction (donation บันทึกเมื่อสำเร็จเท่านั้น)

declare(strict_types=1);

require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/donate_category_resolve.php';

/**
 * บันทึก payment_transaction สถานะ pending (ไม่สร้างแถว donation จนกว่าจะชำระสำเร็จ)
 *
 * @return int log_id ของ payment_transaction หรือ 0 ถ้าล้มเหลว
 */
function drawdream_insert_pending_child_donation(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    float $amountBaht,
    string $omiseChargeId
): int {
    if ($childId <= 0 || $donorUserId <= 0 || $amountBaht < 20 || $omiseChargeId === '') {
        return 0;
    }

    drawdream_payment_transaction_ensure_schema($conn);

    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);

    $taxId = '';
    $stTax = $conn->prepare('SELECT tax_id FROM donor WHERE user_id = ? LIMIT 1');
    if ($stTax) {
        $stTax->bind_param('i', $donorUserId);
        $stTax->execute();
        $rowT = $stTax->get_result()->fetch_assoc();
        if ($rowT) {
            $taxId = (string)($rowT['tax_id'] ?? '');
        }
    }

    $pending = 'pending';
    $insP = $conn->prepare(
        'INSERT INTO payment_transaction (
            donate_id, tax_id, omise_charge_id, transaction_status,
            pending_category_id, pending_target_id, pending_amount, pending_donor_user_id
        ) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insP) {
        return 0;
    }
    $insP->bind_param(
        'sssiiid',
        $taxId,
        $omiseChargeId,
        $pending,
        $categoryId,
        $childId,
        $amountBaht,
        $donorUserId
    );
    if (!$insP->execute()) {
        return 0;
    }
    return (int)$conn->insert_id;
}

/**
 * ยืนยันชำระสำเร็จ: สร้างแถว donation (completed) + อัปเดต payment_transaction + child_donations
 */
function drawdream_finalize_child_donation(
    mysqli $conn,
    int $childId,
    int $donateId,
    string $chargeId,
    float $amountBaht,
    int $donorUserId
): bool {
    if ($childId <= 0 || $chargeId === '' || $donorUserId <= 0) {
        return false;
    }

    drawdream_payment_transaction_ensure_schema($conn);

    $pt = $conn->prepare(
        'SELECT log_id, donate_id, pending_category_id, pending_target_id, pending_amount, pending_donor_user_id
         FROM payment_transaction
         WHERE omise_charge_id = ? AND transaction_status = ? LIMIT 1'
    );
    $pend = 'pending';
    $pt->bind_param('ss', $chargeId, $pend);
    $pt->execute();
    $ptRow = $pt->get_result()->fetch_assoc();
    if (!$ptRow) {
        return false;
    }

    $ptDonateId = isset($ptRow['donate_id']) && $ptRow['donate_id'] !== null ? (int)$ptRow['donate_id'] : 0;
    $logId = (int)$ptRow['log_id'];

    // เส้นทางเก่า: มีแถว donation pending อยู่แล้ว
    if ($ptDonateId > 0) {
        if ($donateId > 0 && $donateId !== $ptDonateId) {
            return false;
        }
        $chk = $conn->prepare(
            'SELECT donor_id, target_id, payment_status FROM donation WHERE donate_id = ? LIMIT 1'
        );
        $chk->bind_param('i', $ptDonateId);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if (!$row
            || (int)($row['donor_id'] ?? 0) !== $donorUserId
            || (int)($row['target_id'] ?? 0) !== $childId
            || (string)($row['payment_status'] ?? '') !== 'pending'
        ) {
            return false;
        }

        $serviceFee = 0.0;
        if (!$conn->begin_transaction()) {
            return false;
        }
        try {
            $upd = $conn->prepare(
                'UPDATE donation
                 SET amount = ?, service_fee = ?, payment_status = \'completed\', transfer_datetime = NOW()
                 WHERE donate_id = ? AND payment_status = \'pending\''
            );
            if (!$upd) {
                throw new RuntimeException('prepare update donation');
            }
            $upd->bind_param('ddi', $amountBaht, $serviceFee, $ptDonateId);
            $upd->execute();
            if ($upd->affected_rows < 1) {
                throw new RuntimeException('update donation');
            }

            $upt = $conn->prepare(
                'UPDATE payment_transaction SET transaction_status = \'completed\'
                 WHERE log_id = ? AND transaction_status = \'pending\''
            );
            if (!$upt) {
                throw new RuntimeException('prepare pt');
            }
            $upt->bind_param('i', $logId);
            $upt->execute();

            $conn->query(
                "CREATE TABLE IF NOT EXISTS child_donations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    child_id INT NOT NULL,
                    donor_user_id INT NULL,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                    donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX(child_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $insC = $conn->prepare(
                'INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, ?, ?)'
            );
            if (!$insC) {
                throw new RuntimeException('prepare child_donations');
            }
            $insC->bind_param('iid', $childId, $donorUserId, $amountBaht);
            $insC->execute();

            if (!function_exists('drawdream_child_sync_sponsorship_status')) {
                require_once __DIR__ . '/child_sponsorship.php';
            }
            if (function_exists('drawdream_child_sync_sponsorship_status')) {
                drawdream_child_sync_sponsorship_status($conn, $childId);
            }

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }

    // เส้นทางใหม่: ยังไม่มี donation — สร้าง completed ทันที
    $pDonor = (int)($ptRow['pending_donor_user_id'] ?? 0);
    $pTarget = (int)($ptRow['pending_target_id'] ?? 0);
    $pCat = (int)($ptRow['pending_category_id'] ?? 0);
    if ($pDonor !== $donorUserId || $pTarget !== $childId || $pCat <= 0) {
        return false;
    }

    $serviceFee = 0.0;
    if (!$conn->begin_transaction()) {
        return false;
    }
    try {
        $insD = $conn->prepare(
            'INSERT INTO donation (category_id, target_id, donor_id, amount, service_fee, payment_status, transfer_datetime)
             VALUES (?, ?, ?, ?, ?, \'completed\', NOW())'
        );
        if (!$insD) {
            throw new RuntimeException('prepare insert donation');
        }
        $insD->bind_param('iiidd', $pCat, $childId, $donorUserId, $amountBaht, $serviceFee);
        $insD->execute();
        $newDonateId = (int)$conn->insert_id;
        if ($newDonateId <= 0) {
            throw new RuntimeException('no donate_id');
        }

        $upt = $conn->prepare(
            'UPDATE payment_transaction SET donate_id = ?, transaction_status = \'completed\',
             pending_category_id = NULL, pending_target_id = NULL, pending_amount = NULL, pending_donor_user_id = NULL
             WHERE log_id = ? AND transaction_status = \'pending\''
        );
        if (!$upt) {
            throw new RuntimeException('prepare pt update');
        }
        $upt->bind_param('ii', $newDonateId, $logId);
        $upt->execute();
        if ($upt->affected_rows < 1) {
            throw new RuntimeException('update pt');
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS child_donations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_id INT NOT NULL,
                donor_user_id INT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(child_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $insC = $conn->prepare(
            'INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, ?, ?)'
        );
        if (!$insC) {
            throw new RuntimeException('prepare child_donations');
        }
        $insC->bind_param('iid', $childId, $donorUserId, $amountBaht);
        $insC->execute();

        if (!function_exists('drawdream_child_sync_sponsorship_status')) {
            require_once __DIR__ . '/child_sponsorship.php';
        }
        if (function_exists('drawdream_child_sync_sponsorship_status')) {
            drawdream_child_sync_sponsorship_status($conn, $childId);
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

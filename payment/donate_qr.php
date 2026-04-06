<?php
// donate_qr.php — แสดง QR และข้อมูลบัญชี DrawDream (เลย์เอาต์การ์ดสีน้ำเงิน)
$amount = isset($_GET['amount']) ? max(0, (float)$_GET['amount']) : 0;

/** @return string path จาก payment/ ไปยังรูป ../img/qr-code.{ext} */
function donate_qr_resolve_image(string $baseName): string {
    $imgDir = dirname(__DIR__) . '/img';
    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
        if (is_file($imgDir . '/' . $baseName . $ext)) {
            return '../img/' . $baseName . $ext;
        }
    }
    return '../img/' . $baseName . '.png';
}

$qrSrc = donate_qr_resolve_image('qr-code');
$amountLabel = $amount > 0 ? number_format($amount, 0) . ' บาท' : 'ตามจำนวนที่โอน';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
  <meta charset="utf-8">
  <title>ชำระเงินบริจาค | DrawDream</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/payment_qr.css?v=4">
</head>
<body class="payment-qr-page">

  <a href="../homepage.php" class="payment-qr-top-back" aria-label="กลับหน้าหลัก"><span aria-hidden="true">←</span></a>

  <main class="container py-3">
    <div class="payment-card">

      <div class="qr-wrapper">
        <img src="<?= htmlspecialchars($qrSrc) ?>" alt="QR Code สำหรับชำระเงิน" width="260" height="260" decoding="async">
      </div>

      <div class="payment-info">
        <div class="info-row">
          <span>ชื่อบัญชี</span>
          <span>มูลนิธิ DrawDream</span>
        </div>
        <hr class="info-divider">
        <div class="info-row">
          <span>จำนวนเงิน</span>
          <span class="amount-text"><?= htmlspecialchars($amountLabel) ?></span>
        </div>
      </div>

      <a href="../homepage.php" class="btn-attach-slip">ยืนยันการบริจาค</a>

      <p class="thank-you-text">
        ขอขอบคุณเป็นอย่างยิ่งสำหรับการสนับสนุนของท่าน<br>
        ความเมตตานี้ได้เติมพลังให้ความฝันของน้อง ๆ ก้าวไปอีกขั้น<br>
        และสร้างอนาคตที่งดงามยิ่งขึ้น
      </p>
    </div>
  </main>

</body>
</html>

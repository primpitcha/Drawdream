<?php
// includes/site_footer.php — HTML footer เว็บไซต์
// ฟุตเตอร์กลางเว็บไซต์ (ใช้ร่วมกับ foundation.php และหน้าอื่นที่ต้องการให้สอดคล้องกับ homepage)
$footerBase = $footer_base_path ?? '';
?>
<div class="site-footer-outer">
<footer class="site-footer" style="background-color: #3f4f9a;">
  <div class="container py-4" style="background-color: #3f4f9a;">
    <div class="row text-light">

      <div class="col-md-6 mb-4">
        <img src="<?= htmlspecialchars($footerBase) ?>img/logobanner.png" alt="DrawDream logo" class="mb-3 footer-logo">
        <p class="text-light">
          ร่วมบริจาคเพื่อช่วยเหลือเด็กได้ที่<br>
          ธนาคารไทยพาณิชย์<br>
          เลขที่บัญชี <span style="color:#f4c948; font-weight:bold;">011-1-11111-1</span>
        </p>
      </div>

      <div class="col-md-6 mb-4">
        <h5 class="text-center mb-3 text-light">ติดต่อเรา</h5>
        <p class="text-light footer-address">
          <i class="bi bi-geo-alt-fill me-2"></i>
          ชั้น 3 อาคาร Drawdream ถนนพหลโยธิน แขวงพญาไท เขตพญาไท กรุงเทพมหานคร 10400
        </p>
        <div class="d-flex justify-content-center gap-4 mb-3">
          <span class="text-light"><i class="bi bi-telephone-fill me-1"></i> 0949278518</span>
          <span class="text-light"><i class="bi bi-printer-fill me-1"></i> 0123456789</span>
        </div>
        <div class="social-links">
          <a href="#" class="social-link" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
          <a href="#" class="social-link" aria-label="TikTok"><i class="bi bi-tiktok"></i></a>
          <a href="#" class="social-link" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
          <a href="#" class="social-link" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
        </div>
      </div>

    </div>
    <hr style="border-color: rgba(255,255,255,0.25);">
    <p class="text-center text-light mb-0 small" style="opacity:0.7;">&copy; All right reserved 2026</p>
  </div>
</footer>
</div>

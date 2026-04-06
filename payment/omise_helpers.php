<?php
// payment/omise_helpers.php — ดึง charge / URI ภาพ PromptPay จาก Omise (ใช้ร่วมกับ payment_project, scan_qr)
/**
 * GET /charges/{id} พร้อม expand source (ต้อง include config.php ก่อน)
 */
function drawdream_omise_fetch_charge(string $chargeId): ?array
{
    $chargeId = trim($chargeId);
    if ($chargeId === '') {
        return null;
    }
    $url = rtrim(OMISE_API_URL, '/') . '/charges/' . rawurlencode($chargeId) . '?expand[]=source';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response === false || $response === '') {
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * ดึง download_uri ของภาพ QR PromptPay จากอ็อบเจ็กต์ charge (รูปแบบ Omise API)
 */
function drawdream_omise_promptpay_qr_uri_from_charge(?array $charge): string
{
    if ($charge === null) {
        return '';
    }
    $source = $charge['source'] ?? null;
    if (!is_array($source)) {
        return '';
    }
    $uri = $source['scannable_code']['image']['download_uri'] ?? '';
    return trim((string) $uri);
}

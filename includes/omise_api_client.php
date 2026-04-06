<?php
// includes/omise_api_client.php — HTTP POST แบบ form-urlencoded ไป Omise (customer / schedules)
declare(strict_types=1);

/**
 * @param array<string, mixed> $fields รองรับ nested array สำหรับ charge[metadata][key]
 * @return array<string, mixed>|null
 */
function drawdream_omise_post_form(string $path, array $fields): ?array
{
    $path = '/' . ltrim($path, '/');
    $url = rtrim(OMISE_API_URL, '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 90,
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
 * สร้าง charge จากบัตรที่ผูกลูกค้า (ไม่ใช้ Charge Schedule)
 *
 * @param array<string, string> $metadata
 * @return array<string, mixed>|null
 */
function drawdream_omise_create_card_charge(
    string $customerId,
    string $cardId,
    int $amountSatang,
    string $description,
    array $metadata
): ?array {
    $fields = [
        'amount' => (string)$amountSatang,
        'currency' => 'thb',
        'customer' => $customerId,
        'card' => $cardId,
        'description' => $description,
        'capture' => 'true',
    ];
    foreach ($metadata as $mk => $mv) {
        $fields['metadata[' . $mk . ']'] = (string)$mv;
    }
    return drawdream_omise_post_form('/charges', $fields);
}

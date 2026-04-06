<?php
/**
 * Favicon — ใส่ใน <head> หลังเปิดแท็ก head (ทุกหน้า)
 * require_once __DIR__ . '/includes/favicon_meta.php';  // จากไฟล์ในรากโปรเจกต์
 * require_once __DIR__ . '/../includes/favicon_meta.php'; // จาก payment/ เป็นต้น
 */
declare(strict_types=1);

if (!empty($GLOBALS['_drawdream_favicon_done'])) {
    return;
}
$GLOBALS['_drawdream_favicon_done'] = true;

$projectRoot = realpath(__DIR__ . '/..');
$scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($projectRoot === false || $scriptDir === '') {
    $base = '';
} else {
    $rootNorm = str_replace('\\', '/', $projectRoot);
    $dirNorm = str_replace('\\', '/', $scriptDir);
    $rel = '';
    if (strncmp($dirNorm, $rootNorm, strlen($rootNorm)) === 0) {
        $rel = substr($dirNorm, strlen($rootNorm));
    }
    $depth = substr_count(trim($rel, '/'), '/');
    $base = str_repeat('../', max(0, $depth));
}

$iconPng = $base . 'img/logobig.png';
echo '  <link rel="icon" href="' . htmlspecialchars($iconPng, ENT_QUOTES, 'UTF-8') . '" type="image/png">' . "\n";
// ไอคอน SVG เดิม — ถ้าต้องการใช้คู่กับ logobig ให้ปลดคอมเมนต์
// echo '  <link rel="icon" href="' . htmlspecialchars($base . 'img/favicon.svg', ENT_QUOTES, 'UTF-8') . '" type="image/svg+xml">' . "\n";

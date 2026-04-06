<?php

// includes/address_helpers.php — แปลง/รวมข้อความที่อยู่ไทยจาก POST
/**
 * @return array{tambon: string, amphoe: string, province: string, zip: string}|null
 */
function drawdream_parse_saved_thai_address(?string $s): ?array
{
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/ต\.\s*(.+?)\s+อ\.\s*(.+?)\s+จ\.\s*(.+?)\s+(\d{5})\s*$/u', $s, $m)) {
        return [
            'tambon'   => trim($m[1]),
            'amphoe'   => trim($m[2]),
            'province' => trim($m[3]),
            'zip'      => $m[4],
        ];
    }
    return null;
}

function drawdream_merge_foundation_address_from_post(array $post): string
{
    $p = trim((string)($post['addr_province'] ?? ''));
    $a = trim((string)($post['addr_amphoe'] ?? ''));
    $tRaw = trim((string)($post['addr_tambon'] ?? ''));
    $z = trim((string)($post['addr_zip'] ?? ''));

    if ($tRaw !== '' && strpos($tRaw, "\x1E") !== false) {
        $parts = explode("\x1E", $tRaw, 2);
        if ($z === '' && $parts[0] !== '') {
            $z = $parts[0];
        }
        $t = $parts[1] ?? '';
    } else {
        $t = $tRaw;
    }

    if ($p === '' && $a === '' && $t === '' && $z === '') {
        return '';
    }

    return 'ต.' . $t . ' อ.' . $a . ' จ.' . $p . ' ' . $z;
}

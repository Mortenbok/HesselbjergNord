<?php
/**
 * Serverer et medlemsbillede fra uploads/ — men kun til brugere, der er
 * logget ind. uploads/ er spærret for direkte adgang via .htaccess.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

if (!auth_check()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Login kræves.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT file_name, original_name, mime_type FROM member_photos WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$photo = $stmt->fetch();

if (!$photo) {
    http_response_code(404);
    exit;
}

// basename() sikrer, at et manipuleret filnavn i databasen ikke kan pege
// uden for uploads/.
$path = __DIR__ . '/uploads/' . basename($photo['file_name']);

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

// Typen aflæses af selve filen, så billeder uploadet før mime_type-kolonnen
// fandtes også serveres korrekt.
$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detected = finfo_file($finfo, $path);
finfo_close($finfo);

if (in_array($detected, $allowedTypes, true)) {
    $mime = $detected;
} elseif (in_array($photo['mime_type'], $allowedTypes, true)) {
    $mime = $photo['mime_type'];
} else {
    // Filen er ikke et billede, vi vil servere.
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($photo['file_name']) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($path);
// ?dl=1 leverer billedet som en fil, man kan gemme, i stedet for at vise det.
$download = filter_input(INPUT_GET, 'dl', FILTER_VALIDATE_INT) === 1;

/**
 * Et filnavn, browseren kan bruge: uden mappesti og uden tegn, der kan bryde
 * ud af Content-Disposition-linjen.
 */
function photo_download_name(string $original, string $stored, string $mime): string
{
    // chr(92) er et backslash — skrevet sådan, så stien ikke kan misforstås.
    $name = basename(str_replace(chr(92), '/', $original));
    $name = preg_replace('/[\x00-\x1F"]+/u', '', $name);
    $name = str_replace(chr(92), '', $name);
    $name = trim($name);

    if ($name === '' || $name === '.' || $name === '..') {
        $name = basename($stored);
    }

    // Mangler endelsen, sættes den ud fra den type, filen rent faktisk har.
    if (!preg_match('/\.(jpe?g|png|webp)$/i', $name)) {
        $ext = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'][$mime] ?? '';
        $name .= $ext;
    }

    return $name;
}

$fileName = photo_download_name(
    (string)($photo['original_name'] ?? ''),
    (string)$photo['file_name'],
    $mime
);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));

if ($download) {
    // ASCII-navnet er reserven; filename* bærer de danske tegn.
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $fileName);
    header(
        'Content-Disposition: attachment; filename="' . $ascii . '"; '
        . "filename*=UTF-8''" . rawurlencode($fileName)
    );
    header('Cache-Control: private, no-store');
} else {
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $fileName) . '"');
    header('Cache-Control: private, max-age=3600');
}

header('X-Content-Type-Options: nosniff');

readfile($path);

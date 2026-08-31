<?php
/**
 * Serverer et dokument fra uploads/dokumenter/ — men kun til brugere, der er
 * logget ind. uploads/ er spærret for direkte adgang via .htaccess.
 *
 * ?id=12            viser filen i browseren (PDF og billeder)
 * ?id=12&download=1 gemmer filen med sit oprindelige navn
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/documents.php';

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

$stmt = $pdo->prepare('SELECT file_name, original_name, mime_type FROM documents WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    exit;
}

// basename() sikrer, at et manipuleret filnavn i databasen ikke kan pege
// uden for uploads/dokumenter/.
$path = documents_dir() . '/' . basename($document['file_name']);

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = in_array($document['mime_type'], array_keys(DOCUMENT_TYPES), true)
    ? $document['mime_type']
    : 'application/octet-stream';

// PDF og billeder må vises i browseren; alt andet hentes ned, så en fil aldrig
// kan blive fortolket som HTML af browseren.
$inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
$download = filter_input(INPUT_GET, 'download', FILTER_VALIDATE_INT) === 1;
$disposition = (!$download && in_array($mime, $inlineTypes, true)) ? 'inline' : 'attachment';

$fileName = basename($document['original_name']);

if ($fileName === '') {
    $fileName = basename($document['file_name']);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($path);

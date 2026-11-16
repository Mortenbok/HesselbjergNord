<?php
/**
 * Generalforsamling — arkivet bag login med mapperne Dagsorden, Referat og
 * Regnskab. Al logik og HTML ligger i includes/documents.php.
 *
 * Regnskab havde før sin egen side; regnskab.php sender nu videre hertil.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/documents.php';

auth_require('index.html');

$user = auth_user();
$message = documents_handle_post($pdo, $user);

documents_render_page(documents_list($pdo), $user, $message);

<?php
/**
 * Generalforsamling — referater, indkaldelser og dagsordener bag login.
 * Al logik og HTML ligger i includes/documents.php, som deles med regnskab.php.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/documents.php';

auth_require('index.html');

$user = auth_user();
$message = documents_handle_post($pdo, $user, 'generalforsamling');

documents_render_page($pdo, 'generalforsamling', [
    'title' => 'Generalforsamling',
    'heading' => 'Generalforsamling',
    'intro' => 'Indkaldelser, dagsordener og referater fra foreningens generalforsamlinger. '
        . 'Arkivet er kun tilgængeligt for medlemmer, der er logget ind.',
    'nav' => 'generalforsamling.php',
    'titlePlaceholder' => 'Referat af ordinær generalforsamling',
    'empty' => 'Der ligger endnu ingen dokumenter fra generalforsamlingerne i arkivet.',
], $user, $message);

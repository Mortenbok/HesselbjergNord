<?php
/**
 * Regnskab — årsregnskaber og budgetter bag login.
 * Al logik og HTML ligger i includes/documents.php, som deles med
 * generalforsamling.php.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/documents.php';

auth_require('index.html');

$user = auth_user();
$message = documents_handle_post($pdo, $user, 'regnskab');

documents_render_page($pdo, 'regnskab', [
    'title' => 'Regnskab',
    'heading' => 'Regnskab',
    'intro' => 'Foreningens årsregnskaber og budgetter. Regnskabet fremlægges på '
        . 'generalforsamlingen og lægges her, så medlemmerne kan se det bagefter.',
    'nav' => 'regnskab.php',
    'titlePlaceholder' => 'Årsregnskab',
    'empty' => 'Der ligger endnu ingen regnskaber i arkivet.',
], $user, $message);

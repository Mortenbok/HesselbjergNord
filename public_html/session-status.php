<?php
/**
 * Fortæller de statiske HTML-sider, om den besøgende er logget ind, så
 * navigationen kan vise "Log ud" og linket til Medlemsfotos.
 */

require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$user = auth_user();

echo json_encode([
    'loggedIn' => $user !== null,
    'displayName' => $user['display_name'] ?? null,
    'role' => $user['role'] ?? null,
]);

<?php
/**
 * Login-endpoint. Modal-formularen på de statiske sider poster hertil med
 * fetch() og forventer JSON; uden JavaScript virker et almindeligt POST også.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

/**
 * Sender svaret enten som JSON eller som et redirect.
 *
 * Uden JavaScript sendes kun en kort fejlkode med i URL'en — teksten slås op
 * i browseren, så adressefeltet ikke kan bruges til at vise vilkårlige
 * beskeder på forsiden.
 */
function login_respond(bool $ok, string $message, string $errorCode, string $next, bool $wantsJson): void
{
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 401);
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'redirect' => $ok ? $next : null,
        ]);
        exit;
    }

    if ($ok) {
        header('Location: ' . $next, true, 302);
        exit;
    }

    header('Location: index.html?login=1&error=' . rawurlencode($errorCode), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    login_respond(false, 'Ugyldig forespørgsel.', 'request', 'medlemsfotos.php', $wantsJson);
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

// Kun kendte sider accepteres som viderestilling, så parameteren ikke kan
// bruges til at sende folk videre til et fremmed domæne.
$allowedNext = [
    'medlemsfotos.php',
    'generalforsamling.php',
    'regnskab.php',
    'bestyrelsen.php',
    'index.html',
];
$next = (string)($_POST['next'] ?? 'medlemsfotos.php');

if (!in_array($next, $allowedNext, true)) {
    $next = 'medlemsfotos.php';
}

if ($username === '' || $password === '') {
    login_respond(false, 'Udfyld både brugernavn og adgangskode.', 'empty', $next, $wantsJson);
}

// Simpel bremse mod gætteri: fem fejlforsøg giver et minuts karantæne.
auth_start_session();
$now = time();
$attempts = $_SESSION['login_attempts'] ?? 0;
$blockedUntil = $_SESSION['login_blocked_until'] ?? 0;

if ($attempts >= 5 && $now < $blockedUntil) {
    login_respond(false, 'For mange forsøg. Vent et minut, og prøv igen.', 'throttled', $next, $wantsJson);
}

if ($now >= $blockedUntil) {
    $attempts = 0;
}

if (auth_login($pdo, $username, $password)) {
    unset($_SESSION['login_attempts'], $_SESSION['login_blocked_until']);
    login_respond(true, 'Velkommen.', '', $next, $wantsJson);
}

$_SESSION['login_attempts'] = $attempts + 1;
$_SESSION['login_blocked_until'] = $now + 60;

usleep(400000);
login_respond(false, 'Forkert brugernavn eller adgangskode.', 'auth', $next, $wantsJson);

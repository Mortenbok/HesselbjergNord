<?php
/**
 * Session- og loginhåndtering for medlemsområdet.
 *
 * Inkludér denne fil øverst på enhver side, der kræver login, og kald
 * auth_require() for at sende ikke-loggede besøgende videre til forsiden.
 */

/** Starter sessionen med sikre cookie-indstillinger (kun én gang pr. request). */
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
    ]);

    session_name('hbnord_session');
    session_start();
}

/** Er den besøgende logget ind? */
function auth_check(): bool
{
    auth_start_session();

    return isset($_SESSION['user_id']);
}

/** Den aktuelt loggede bruger, eller null. */
function auth_user(): ?array
{
    if (!auth_check()) {
        return null;
    }

    return [
        'id' => (int)$_SESSION['user_id'],
        'username' => (string)($_SESSION['username'] ?? ''),
        'display_name' => (string)($_SESSION['display_name'] ?? ''),
        'role' => (string)($_SESSION['role'] ?? 'medlem'),
    ];
}

/** Afviser besøgende uden login. */
function auth_require(string $redirectTo = 'index.html'): void
{
    if (auth_check()) {
        return;
    }

    $target = $redirectTo . '?login=1&next=' . rawurlencode(basename($_SERVER['SCRIPT_NAME']));
    header('Location: ' . $target, true, 302);
    exit;
}

/**
 * Slår brugernavn/adgangskode op og logger ind ved match.
 * Returnerer true ved succes.
 */
function auth_login(PDO $pdo, string $username, string $password): bool
{
    auth_start_session();

    $stmt = $pdo->prepare(
        'SELECT id, username, display_name, password_hash, role
           FROM users
          WHERE username = :username AND is_active = 1
          LIMIT 1'
    );
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    // Kør altid en hash-verifikation, så svartiden ikke afslører, om
    // brugernavnet findes.
    $hash = is_array($user)
        ? $user['password_hash']
        : '$2y$10$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQ';

    $ok = password_verify($password, $hash);

    if (!is_array($user) || !$ok) {
        return false;
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $rehash->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $user['id'],
        ]);
    }

    // Nyt session-id ved login beskytter mod session fixation.
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['role'] = $user['role'];

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
        ->execute([':id' => $user['id']]);

    return true;
}

/** Logger brugeren ud og rydder sessionen helt. */
function auth_logout(): void
{
    auth_start_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

/** CSRF-token til formularer i medlemsområdet. */
function auth_csrf_token(): string
{
    auth_start_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Sammenligner et indsendt token med sessionens. */
function auth_csrf_valid(?string $token): bool
{
    auth_start_session();

    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

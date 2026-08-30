<?php
/**
 * Engangsopsætning: opretter den første bruger.
 *
 * Siden virker KUN så længe users-tabellen er tom. Så snart der findes en
 * bruger, afviser den enhver henvendelse. Slet gerne filen bagefter.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$locked = $userCount > 0;
$error = '';
$done = false;

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $displayName = trim((string)($_POST['display_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['password_repeat'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        $error = 'Brugernavnet skal være 3-64 tegn og må kun indeholde bogstaver, tal, punktum, bindestreg og understreg.';
    } elseif ($displayName === '') {
        $error = 'Udfyld et visningsnavn.';
    } elseif (strlen($password) < 10) {
        $error = 'Adgangskoden skal være mindst 10 tegn.';
    } elseif ($password !== $repeat) {
        $error = 'De to adgangskoder er ikke ens.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, display_name, password_hash, role)
             VALUES (:username, :display_name, :password_hash, :role)'
        );
        $stmt->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => 'bestyrelse',
        ]);

        $done = true;
        $locked = true;
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Opsætning — Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="beach-bg.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    position: relative;
    padding: 20px;
  }
  body::before { content: ""; position: fixed; inset: 0; background: rgba(0,0,0,0.45); }
  .panel {
    position: relative;
    z-index: 1;
    width: min(94vw, 440px);
    background: rgba(14, 22, 27, 0.92);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.35);
  }
  h1 { font-size: 1.6rem; margin-bottom: 8px; }
  p.intro { color: rgba(255,255,255,0.85); font-size: 0.95rem; margin-bottom: 20px; }
  label { display: block; margin-bottom: 6px; font-size: 0.9rem; }
  input {
    width: 100%;
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
  }
  button {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 10px;
    padding: 10px 18px;
    cursor: pointer;
    font: inherit;
  }
  .message { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 0.92rem; }
  .message.error { background: rgba(180,40,40,0.35); color: #ffdede; }
  .message.success { background: rgba(40,140,70,0.3); color: #dcffe4; }
  a { color: #cfe9ff; }
</style>
</head>
<body>
  <div class="panel">
    <h1>Opsætning</h1>

    <?php if ($done): ?>
      <div class="message success">
        Brugeren er oprettet. Du kan nu logge ind via <strong>Login</strong> i menuen.
      </div>
      <p class="intro">
        Slet <code>setup.php</code> fra serveren nu — siden er låst, men filen er
        overflødig. Gå til <a href="index.html">forsiden</a>.
      </p>
    <?php elseif ($locked): ?>
      <div class="message error">
        Opsætningen er allerede gennemført og kan ikke køres igen.
      </div>
      <p class="intro">
        Nye brugere oprettes direkte i databasen. Gå til
        <a href="index.html">forsiden</a>.
      </p>
    <?php else: ?>
      <p class="intro">
        Opret det første login til medlemsområdet. Siden låser sig selv,
        så snart brugeren er oprettet.
      </p>

      <?php if ($error !== ''): ?>
        <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <label for="username">Brugernavn</label>
        <input id="username" name="username" type="text" required
               value="<?php echo htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <label for="display_name">Visningsnavn</label>
        <input id="display_name" name="display_name" type="text" required
               value="<?php echo htmlspecialchars((string)($_POST['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <label for="password">Adgangskode (mindst 10 tegn)</label>
        <input id="password" name="password" type="password" required>

        <label for="password_repeat">Gentag adgangskode</label>
        <input id="password_repeat" name="password_repeat" type="password" required>

        <button type="submit">Opret bruger</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>

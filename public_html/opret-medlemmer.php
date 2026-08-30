<?php
/**
 * Opretter medlemslogins i bulk.
 *
 * Adgangskoderne indsættes i formularen og hashes med det samme — de gemmes
 * aldrig i klartekst, hverken i databasen eller i en fil på serveren.
 * Siden kræver login som bestyrelsesmedlem.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

auth_require('index.html');

$currentUser = auth_user();

if ($currentUser['role'] !== 'bestyrelse') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kun bestyrelsen har adgang til denne side.');
}

$csrf = auth_csrf_token();

$errors = [];
$created = [];
$skipped = [];
$submitted = false;

/** Finder næste ledige brugernavn med det valgte præfiks. */
function next_free_username(PDO $pdo, string $prefix, array $claimed): string
{
    static $counters = [];

    $n = $counters[$prefix] ?? 1;

    while (true) {
        $candidate = $prefix . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        $n++;

        if (isset($claimed[strtolower($candidate)])) {
            continue;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $candidate]);

        if (!$stmt->fetchColumn()) {
            $counters[$prefix] = $n;
            return $candidate;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
    } else {
        $raw = (string)($_POST['members'] ?? '');
        $prefix = trim((string)($_POST['prefix'] ?? 'medlem'));
        $role = ($_POST['role'] ?? 'medlem') === 'bestyrelse' ? 'bestyrelse' : 'medlem';

        if (!preg_match('/^[a-z][a-z0-9_-]{0,20}$/i', $prefix)) {
            $errors[] = 'Præfikset må kun indeholde bogstaver, tal, bindestreg og understreg.';
        }

        $lines = preg_split('/\R/', $raw);
        $rows = [];
        $claimed = [];
        $lineNo = 0;

        foreach ($lines as $line) {
            $lineNo++;
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Linjeformat: "kode", "brugernavn;kode" eller
            // "brugernavn;kode;visningsnavn".
            $parts = array_map('trim', explode(';', $line));

            if (count($parts) === 1) {
                $username = null;
                $password = $parts[0];
                $displayName = null;
            } else {
                $username = $parts[0];
                $password = $parts[1] ?? '';
                $displayName = ($parts[2] ?? '') !== '' ? $parts[2] : null;
            }

            if (strlen($password) < 8) {
                $errors[] = 'Linje ' . $lineNo . ': adgangskoden skal være mindst 8 tegn.';
                continue;
            }

            if ($username !== null && !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                $errors[] = 'Linje ' . $lineNo . ': ugyldigt brugernavn "' . $username . '".';
                continue;
            }

            $rows[] = [
                'username' => $username,
                'password' => $password,
                'display_name' => $displayName,
            ];

            if ($username !== null) {
                $claimed[strtolower($username)] = true;
            }
        }

        if (!$rows && !$errors) {
            $errors[] = 'Indsæt mindst én adgangskode.';
        }

        if (!$errors) {
            $lookup = $pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
            $insert = $pdo->prepare(
                'INSERT INTO users (username, display_name, password_hash, role)
                 VALUES (:username, :display_name, :password_hash, :role)'
            );

            $pdo->beginTransaction();

            try {
                foreach ($rows as $row) {
                    $username = $row['username'];

                    if ($username === null) {
                        $username = next_free_username($pdo, $prefix, $claimed);
                        $claimed[strtolower($username)] = true;
                    } else {
                        $lookup->execute([':u' => $username]);

                        if ($lookup->fetchColumn()) {
                            $skipped[] = $username;
                            continue;
                        }
                    }

                    $displayName = $row['display_name'] ?? ucfirst($username);

                    $insert->execute([
                        ':username' => $username,
                        ':display_name' => $displayName,
                        ':password_hash' => password_hash($row['password'], PASSWORD_DEFAULT),
                        ':role' => $role,
                    ]);

                    $created[] = [
                        'username' => $username,
                        'display_name' => $displayName,
                        'password' => $row['password'],
                    ];
                }

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Opret medlemmer: ' . $e->getMessage());
                $created = [];
                $errors[] = 'Databasen afviste oprettelsen. Ingen brugere blev oprettet.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Opret medlemmer — Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  html, body {
    min-height: 100%;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover;
    color: #fff;
    position: relative;
    padding: 30px 20px 60px;
  }

  body::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 0;
  }

  .container {
    position: relative;
    z-index: 1;
    max-width: 820px;
    margin: 0 auto;
  }

  h1 {
    font-size: 1.9rem;
    margin-bottom: 8px;
  }

  p.intro {
    color: rgba(255,255,255,0.85);
    margin-bottom: 24px;
    max-width: 62ch;
    line-height: 1.55;
  }

  .panel {
    background: rgba(10, 20, 25, 0.62);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 22px;
    backdrop-filter: blur(4px);
  }

  .panel h2 {
    font-size: 1.2rem;
    margin-bottom: 14px;
  }

  label {
    display: block;
    margin-bottom: 6px;
    font-size: 0.9rem;
    color: rgba(255,255,255,0.9);
  }

  textarea, input, select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
    margin-bottom: 16px;
  }

  textarea {
    min-height: 220px;
    font-family: Consolas, 'Courier New', monospace;
    font-size: 0.92rem;
    resize: vertical;
  }

  select option { color: #111; }

  .row {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
  }

  .row > div { flex: 1 1 220px; }

  button {
    background: rgba(45, 115, 190, 0.75);
    color: #fff;
    border: 1px solid rgba(160, 205, 255, 0.7);
    border-radius: 10px;
    padding: 12px 24px;
    cursor: pointer;
    font: inherit;
    font-weight: 600;
  }

  button:hover { background: rgba(60, 140, 220, 0.9); }

  .hint {
    font-size: 0.86rem;
    color: rgba(255,255,255,0.7);
    margin: -8px 0 16px;
    line-height: 1.5;
  }

  .message {
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 0.93rem;
    line-height: 1.5;
  }

  .message.error { background: rgba(180,40,40,0.35); color: #ffdede; }
  .message.warn { background: rgba(190,140,20,0.32); color: #ffeec9; }
  .message.success { background: rgba(40,140,70,0.32); color: #dcffe4; }

  .message ul { margin: 6px 0 0 20px; }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.94rem;
  }

  th, td {
    text-align: left;
    padding: 9px 10px;
    border-bottom: 1px solid rgba(255,255,255,0.14);
  }

  th {
    font-size: 0.8rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.65);
  }

  td.code {
    font-family: Consolas, 'Courier New', monospace;
    letter-spacing: 0.5px;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 18px;
  }

  a.back {
    color: #cfe9ff;
    font-size: 0.92rem;
  }

  @media print {
    body { background: #fff; color: #000; padding: 0; }
    body::before, .no-print { display: none !important; }
    .panel { background: none; border: none; backdrop-filter: none; }
    th, td { border-bottom: 1px solid #999; }
    a.back { display: none; }
  }
</style>
</head>
<body>
  <div class="container">
    <h1>Opret medlemmer</h1>
    <p class="intro no-print">
      Indsæt adgangskoderne herunder — én pr. linje. De hashes med det samme og
      kan ikke læses ud af databasen bagefter. Listen med brugernavn og kode
      vises kun én gang, lige efter oprettelsen.
    </p>

    <?php if ($errors): ?>
      <div class="message error no-print">
        <strong>Der blev ikke oprettet noget:</strong>
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($skipped): ?>
      <div class="message warn no-print">
        Sprunget over — brugernavnet fandtes i forvejen:
        <?php echo htmlspecialchars(implode(', ', $skipped), ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <?php if ($created): ?>
      <div class="panel">
        <div class="message success no-print">
          <?php echo count($created); ?> brugere oprettet. <strong>Gem eller udskriv
          denne liste nu</strong> — adgangskoderne kan ikke vises igen.
        </div>

        <h2>Logins</h2>
        <table>
          <thead>
            <tr>
              <th>Brugernavn</th>
              <th>Navn</th>
              <th>Adgangskode</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($created as $row): ?>
              <tr>
                <td class="code"><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="code"><?php echo htmlspecialchars($row['password'], ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="actions no-print">
          <button type="button" onclick="window.print()">Udskriv listen</button>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$created): ?>
      <div class="panel no-print">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

          <label for="members">Adgangskoder — én pr. linje</label>
          <textarea id="members" name="members" required
                    placeholder="En kode pr. linje.&#10;&#10;Eller angiv selv brugernavn:&#10;bruger;kode&#10;bruger;kode;Visningsnavn"><?php
            echo $submitted ? htmlspecialchars((string)($_POST['members'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
          ?></textarea>
          <p class="hint">
            Hver linje kan være <code>kode</code>, <code>brugernavn;kode</code>
            eller <code>brugernavn;kode;Visningsnavn</code>. Uden brugernavn
            dannes det automatisk ud fra præfikset. Mindst 8 tegn pr. kode.
          </p>

          <div class="row">
            <div>
              <label for="prefix">Præfiks til automatiske brugernavne</label>
              <input id="prefix" type="text" name="prefix"
                     value="<?php echo htmlspecialchars((string)($_POST['prefix'] ?? 'medlem'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
              <label for="role">Rolle</label>
              <select id="role" name="role">
                <option value="medlem">Medlem</option>
                <option value="bestyrelse"<?php echo ($_POST['role'] ?? '') === 'bestyrelse' ? ' selected' : ''; ?>>Bestyrelse</option>
              </select>
            </div>
          </div>

          <button type="submit">Opret brugerne</button>
        </form>
      </div>
    <?php endif; ?>

    <p class="no-print"><a class="back" href="medlemsfotos.php">← Tilbage til medlemsfotos</a></p>
  </div>
</body>
</html>

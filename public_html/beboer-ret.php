<?php
/**
 * Ret én beboer i kartoteket — eller opret en ny.
 *
 * Felterne kommer fra BEBOER_FELTER i includes/beboer.php, så siden følger
 * automatisk med, hvis regnearket får en kolonne mere.
 *
 * INDEHOLDER PERSONOPLYSNINGER. Kræver login som bestyrelsesmedlem.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/beboer.php';

auth_require('index.html');

$user = auth_user();

if (!beboer_can_manage($user)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kun bestyrelsen har adgang til denne side.');
}

$e = 'beboer_e';
$csrf = auth_csrf_token();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$nyRaekke = $id === 0;

$fejl = '';
$besked = (string)($_GET['besked'] ?? '');

// Tomme værdier til en ny række.
$v = [];

foreach (array_keys(BEBOER_FELTER) as $felt) {
    $v[$felt] = '';
}

$v['forhold'] = 'ejer';
$v['land'] = 'Danmark';
$v['noter'] = '';

if (!$nyRaekke) {
    $stmt = $pdo->prepare('SELECT * FROM beboerkartotek WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $raekke = $stmt->fetch();

    if (!$raekke) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Rækken blev ikke fundet.');
    }

    foreach (array_keys(BEBOER_FELTER) as $felt) {
        $v[$felt] = (string)($raekke[$felt] ?? '');
    }

    $v['noter'] = (string)($raekke['noter'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $fejl = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
    } elseif (($_POST['handling'] ?? '') === 'slet' && !$nyRaekke) {
        $pdo->prepare('DELETE FROM beboerkartotek WHERE id = :id')->execute([':id' => $id]);
        header('Location: beboerkartotek.php?besked=' . rawurlencode('Rækken er slettet.'), true, 302);
        exit;
    } else {
        foreach (array_keys(BEBOER_FELTER) as $felt) {
            $v[$felt] = trim((string)($_POST[$felt] ?? ''));
        }

        $v['noter'] = trim((string)($_POST['noter'] ?? ''));

        // Bestyrelse er et afkrydsningsfelt: det sendes kun med, når det er sat.
        $v['bestyrelse'] = isset($_POST['bestyrelse']) ? '1' : '0';
        $v['forhold'] = $v['forhold'] === 'medejer' ? 'medejer' : 'ejer';
        $v['mail'] = mb_strtolower($v['mail']);
        $v['mail2'] = mb_strtolower($v['mail2']);

        if ($v['kontaktperson'] === '') {
            $fejl = 'Kontaktpersonen skal udfyldes.';
        } elseif ($v['sommerhusadresse'] === '') {
            $fejl = 'Sommerhusadressen skal udfyldes.';
        } elseif ($v['mail'] !== '' && !filter_var($v['mail'], FILTER_VALIDATE_EMAIL)) {
            $fejl = 'Mailadressen ser ikke rigtig ud.';
        } elseif ($v['mail2'] !== '' && !filter_var($v['mail2'], FILTER_VALIDATE_EMAIL)) {
            $fejl = 'Den anden mailadresse ser ikke rigtig ud.';
        } else {
            $felter = array_keys(BEBOER_FELTER);
            $bind = [];

            foreach ($felter as $felt) {
                $bind[':' . $felt] = $v[$felt];
            }

            $bind[':noter'] = $v['noter'];

            if ($nyRaekke) {
                $kolonner = implode(', ', array_map('beboer_kolonne', $felter)) . ', noter, kilde';
                $pladser = ':' . implode(', :', $felter) . ', :noter, \'kartotek\'';

                $pdo->prepare("INSERT INTO beboerkartotek ($kolonner) VALUES ($pladser)")->execute($bind);
                $id = (int)$pdo->lastInsertId();
                $tekst = 'Rækken er oprettet.';
            } else {
                $saet = [];

                foreach ($felter as $felt) {
                    $saet[] = beboer_kolonne($felt) . ' = :' . $felt;
                }

                $saet[] = 'noter = :noter';
                $bind[':id'] = $id;

                $pdo->prepare('UPDATE beboerkartotek SET ' . implode(', ', $saet) . ' WHERE id = :id')
                    ->execute($bind);
                $tekst = 'Ændringen er gemt.';
            }

            header('Location: beboer-ret.php?id=' . $id . '&besked=' . rawurlencode($tekst), true, 302);
            exit;
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
<title><?php echo $nyRaekke ? 'Ny beboer' : 'Ret beboer'; ?> — Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { min-height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover; color: #fff; position: relative;
  }
  body::before { content: ""; position: fixed; inset: 0; background: rgba(0,0,0,0.58); z-index: 0; }
  .container { position: relative; z-index: 1; max-width: 860px; margin: 0 auto; padding: 30px 20px 80px; }
  .panel {
    background: rgba(10, 20, 25, 0.6); border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px; padding: 26px; margin-bottom: 20px; backdrop-filter: blur(4px);
  }
  h1 { font-size: 1.8rem; margin-bottom: 6px; }
  .sti { font-size: 0.9rem; color: rgba(255,255,255,0.65); margin-bottom: 18px; }
  .sti a { color: #cfe9ff; }
  .gitter { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .felt { display: flex; flex-direction: column; gap: 5px; }
  .felt.bred { grid-column: 1 / -1; }
  label { font-size: 0.84rem; color: rgba(255,255,255,0.7); }
  input[type="text"], input[type="email"], select, textarea {
    padding: 11px 13px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.26);
    background: rgba(255,255,255,0.08); color: #fff; font: inherit; font-size: 16px; width: 100%;
  }
  select option { background: #16222b; color: #fff; }
  textarea { min-height: 70px; resize: vertical; }
  input:focus-visible, select:focus-visible, textarea:focus-visible {
    outline: 2px solid rgba(255,255,255,0.7); outline-offset: 1px;
  }
  /* flex-direction skal sættes igen: .felt stabler ellers lodret. */
  .afkryds {
    display: flex; flex-direction: row; align-items: center;
    gap: 10px; padding-top: 24px; justify-content: flex-start;
  }
  .afkryds input { width: 22px; height: 22px; flex: none; }
  .knapper { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 22px; align-items: center; }
  button, .knap {
    font: inherit; font-size: 1rem; padding: 12px 24px; border-radius: 10px; cursor: pointer;
    text-decoration: none; display: inline-block;
  }
  button.gem { background: rgba(70, 150, 225, 0.6); color: #fff; border: 1px solid rgba(150, 200, 255, 0.75); }
  button.gem:hover { background: rgba(80, 165, 240, 0.75); }
  .knap.retur { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.4); }
  button.slet {
    background: transparent; color: #ffd3d3; border: 1px solid rgba(255,140,140,0.5); margin-left: auto;
  }
  button.slet:hover { background: rgba(180,60,60,0.3); }
  .besked { padding: 12px 15px; border-radius: 10px; margin-bottom: 18px; line-height: 1.5; }
  .besked.fejl { background: rgba(180,40,40,0.35); color: #ffdede; }
  .besked.ok { background: rgba(40,140,70,0.32); color: #dcffe4; }
  .status {
    font-size: 0.86rem; color: rgba(255,255,255,0.6); margin-top: 14px; line-height: 1.6;
  }
  @media (max-width: 620px) { .gitter { grid-template-columns: 1fr; } }
</style>
</head>
<body>
  <div class="container">
    <div class="panel">
      <h1><?php echo $nyRaekke ? 'Ny beboer' : $e($v['kontaktperson']); ?></h1>
      <p class="sti">
        <a href="beboerkartotek.php">&larr; Beboerkartotek</a>
        <?php if (!$nyRaekke): ?>
          &nbsp;·&nbsp; <?php echo $e($v['sommerhusadresse']); ?>
        <?php endif; ?>
      </p>

      <?php if ($fejl !== ''): ?>
        <div class="besked fejl"><?php echo $e($fejl); ?></div>
      <?php elseif ($besked !== ''): ?>
        <div class="besked ok"><?php echo $e($besked); ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

        <div class="gitter">
          <?php foreach (BEBOER_FELTER as $felt => $info): ?>
            <?php if ($info['type'] === 'ja-nej'): ?>
              <div class="felt afkryds">
                <input id="f-<?php echo $e($felt); ?>" type="checkbox" name="<?php echo $e($felt); ?>"
                       value="1"<?php echo $v[$felt] === '1' ? ' checked' : ''; ?>>
                <label for="f-<?php echo $e($felt); ?>"><?php echo $e($info['navn']); ?></label>
              </div>
            <?php elseif ($info['type'] === 'valg'): ?>
              <div class="felt">
                <label for="f-<?php echo $e($felt); ?>"><?php echo $e($info['navn']); ?></label>
                <select id="f-<?php echo $e($felt); ?>" name="<?php echo $e($felt); ?>">
                  <option value="ejer"<?php echo $v[$felt] === 'ejer' ? ' selected' : ''; ?>>Ejer</option>
                  <option value="medejer"<?php echo $v[$felt] === 'medejer' ? ' selected' : ''; ?>>Medejer</option>
                </select>
              </div>
            <?php else: ?>
              <div class="felt<?php echo in_array($felt, ['sommerhusadresse', 'kontaktperson', 'hjemmeadresse'], true) ? ' bred' : ''; ?>">
                <label for="f-<?php echo $e($felt); ?>"><?php echo $e($info['navn']); ?></label>
                <input id="f-<?php echo $e($felt); ?>"
                       type="<?php echo $info['type'] === 'mail' ? 'email' : 'text'; ?>"
                       name="<?php echo $e($felt); ?>" maxlength="255"
                       value="<?php echo $e($v[$felt]); ?>">
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

          <div class="felt bred">
            <label for="f-noter">Note — kun til bestyrelsen</label>
            <textarea id="f-noter" name="noter"><?php echo $e($v['noter']); ?></textarea>
          </div>
        </div>

        <div class="knapper">
          <button type="submit" class="gem"><?php echo $nyRaekke ? 'Opret' : 'Gem'; ?></button>
          <a class="knap retur" href="beboerkartotek.php">Tilbage</a>

          <?php if (!$nyRaekke): ?>
            <button type="submit" name="handling" value="slet" class="slet"
                    onclick="return confirm('Slet <?php echo $e($v['kontaktperson']); ?> fra kartoteket? Det kan ikke fortrydes.');">
              Slet rækken
            </button>
          <?php endif; ?>
        </div>
      </form>

      <?php if (!$nyRaekke && isset($raekke)): ?>
        <p class="status">
          Kilde: <?php echo $raekke['kilde'] === 'ny-beboer' ? 'tilmeldt selv på Ny beboer' : 'bestyrelsens regneark'; ?>.
          Status: <?php echo $raekke['status'] === 'afventer_oplysninger' ? 'afventer hjemmeadresse' : 'aktiv'; ?>.
          <?php if ($raekke['created_at']): ?>
            Oprettet <?php echo $e(date('d-m-Y', strtotime($raekke['created_at']))); ?>.
          <?php endif; ?>
          <?php if ($raekke['updated_at']): ?>
            Sidst rettet <?php echo $e(date('d-m-Y', strtotime($raekke['updated_at']))); ?>.
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>

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
require_once __DIR__ . '/includes/bfe.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/admin.php';

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
$v['bfe'] = '';

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
    // Står nummeret ikke i databasen, foreslår vi det, vi kender i
    // forvejen fra includes/bfe.php. Så er det gemt, næste gang rækken
    // bliver rettet.
    $v['bfe'] = bfe_for_raekke($raekke);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $fejl = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
    } elseif (($_POST['handling'] ?? '') === 'slet' && !$nyRaekke) {
        $pdo->prepare('DELETE FROM beboerkartotek WHERE id = :id')->execute([':id' => $id]);
        header('Location: admin.php?besked=' . rawurlencode('Rækken er slettet.'), true, 302);
        exit;
    } elseif (($_POST['handling'] ?? '') === 'send-kode' && !$nyRaekke) {
        // Koden går til den person, der er åbnet — ud fra den gemte række,
        // ikke det, der måske står halvt rettet i formularen. Se
        // admin_send_kode().
        $hus = admin_hus($pdo, (string)$raekke['sommerhusadresse']);

        if ($hus === null) {
            $fejl = 'Husstanden blev ikke fundet.';
        } else {
            $svar = admin_send_kode($pdo, $hus, admin_base_url(), (int)$user['id'], admin_person_mail($raekke));

            if (!$svar['ok']) {
                $fejl = $svar['fejl'];
            } else {
                $tekst = 'Ny kode sendt til ' . $svar['mail'] . '.'
                    . ($svar['lukkede'] > 0
                        ? ' ' . $svar['lukkede'] . ' login' . ($svar['lukkede'] === 1 ? '' : 's') . ' på adressen er lukket.'
                        : '');
                header('Location: beboer-ret.php?id=' . $id . '&besked=' . rawurlencode($tekst), true, 303);
                exit;
            }
        }
    } else {
        foreach (array_keys(BEBOER_FELTER) as $felt) {
            $v[$felt] = trim((string)($_POST[$felt] ?? ''));
        }

        $v['noter'] = trim((string)($_POST['noter'] ?? ''));
        // BFE er altid tal; alt andet kasseres.
        $v['bfe'] = preg_replace('/[^0-9]/', '', (string)($_POST['bfe'] ?? '')) ?? '';

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
            $bind[':bfe'] = $v['bfe'];

            if ($nyRaekke) {
                $kolonner = implode(', ', array_map('beboer_kolonne', $felter)) . ', noter, bfe, kilde';
                $pladser = ':' . implode(', :', $felter) . ', :noter, :bfe, \'kartotek\'';

                $pdo->prepare("INSERT INTO beboerkartotek ($kolonner) VALUES ($pladser)")->execute($bind);
                $id = (int)$pdo->lastInsertId();
                $tekst = 'Rækken er oprettet.';
            } else {
                $saet = [];

                foreach ($felter as $felt) {
                    $saet[] = beboer_kolonne($felt) . ' = :' . $felt;
                }

                $saet[] = 'noter = :noter';
                $saet[] = 'bfe = :bfe';
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

// Husstanden til boksen med adgangskoden — ud fra den gemte adresse, ikke
// det, der måske står halvt rettet i formularen.
$hus = !$nyRaekke ? admin_hus($pdo, (string)$raekke['sommerhusadresse']) : null;
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
  .hjaelp {
    font-size: 0.82rem; line-height: 1.55; color: rgba(255,255,255,0.62); margin-top: 2px;
  }
  .hjaelp a { color: #cfe9ff; }
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
  /* .felt stabler lodret og strækker; knappen skal have sin egen bredde. */
  .gem-midt { align-items: flex-start; }
  h2 { font-size: 1.25rem; margin-bottom: 10px; }
  .tekst { font-size: 0.95rem; line-height: 1.55; color: rgba(255,255,255,0.88); margin-bottom: 14px; max-width: 68ch; }
  @media (max-width: 620px) { .gitter { grid-template-columns: 1fr; } }
</style>
</head>
<body>
  <div class="container">
    <div class="panel">
      <h1><?php echo $nyRaekke ? 'Ny beboer' : $e($v['kontaktperson']); ?></h1>
      <p class="sti">
        <a href="admin.php">&larr; Admin</a>
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

            <?php if ($felt === 'telefon2'): ?>
              <?php // Ekstra Gem midt i formularen, så man ikke skal rulle ned efter mail og telefon. ?>
              <div class="felt bred gem-midt">
                <button type="submit" class="gem"><?php echo $nyRaekke ? 'Opret' : 'Gem'; ?></button>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

          <div class="felt bred">
            <label for="f-bfe">BFE-nummer</label>
            <input id="f-bfe" type="text" name="bfe" maxlength="12"
                   inputmode="numeric" pattern="[0-9]*"
                   value="<?php echo $e($v['bfe']); ?>">
            <p class="hjaelp">
              Ejendommens nummer i Det Fælleskommunale Ejendomsstamregister.
              Slå sommerhusadressen op på
              <a href="https://boligejer.dk/" target="_blank" rel="noopener">boligejer.dk</a>
              og klik videre til OIS. Tallet står til sidst i adressen på den
              side, du ender på &mdash; fx nyt.ois.dk/search/<b>9856478</b>.
              Når det står her, kan ejeren slås op direkte fra kartoteket.
            </p>
          </div>

          <div class="felt bred">
            <label for="f-noter">Note — kun til bestyrelsen</label>
            <textarea id="f-noter" name="noter"><?php echo $e($v['noter']); ?></textarea>
          </div>
        </div>

        <div class="knapper">
          <button type="submit" class="gem"><?php echo $nyRaekke ? 'Opret' : 'Gem'; ?></button>
          <a class="knap retur" href="admin.php">Tilbage</a>

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

    <?php if ($hus !== null): ?>
      <div class="panel">
        <h2>Adgangskode</h2>
        <p class="tekst">
          <?php if ($hus['logins'] !== []): ?>
            <?php echo $e($hus['adresse']); ?> har
            <?php echo count($hus['logins']); ?> login<?php echo count($hus['logins']) === 1 ? '' : 's'; ?>:
            <?php echo $e(implode(', ', array_column($hus['logins'], 'username'))); ?>.
          <?php else: ?>
            Ingen på <?php echo $e($hus['adresse']); ?> har et login endnu.
          <?php endif; ?>
          <?php if ($hus['kort'] !== null && $hus['kort']['sendt_at']): ?>
            Sidste kode blev sendt <?php echo $e(date('d-m-Y', strtotime($hus['kort']['sendt_at']))); ?>.
          <?php endif; ?>
        </p>

        <?php
          // Personen, der er åbnet: får koden, og har vedkommende selv et login?
          $modtager = admin_person_mail($raekke);
          $egneMails = array_filter([
              mb_strtolower(trim((string)$raekke['mail'])),
              mb_strtolower(trim((string)$raekke['mail2'])),
          ]);
          $egetLogin = array_values(array_filter(
              $hus['logins'],
              static fn($u) => in_array(mb_strtolower((string)$u['username']), $egneMails, true)
          ));
        ?>
        <p class="tekst"><b><?php echo $e($modtager['navn']); ?></b>
          <?php if ($egetLogin !== []): ?>
            har login som <?php echo $e($egetLogin[0]['username']); ?>.
          <?php else: ?>
            har ikke selv et login endnu.
          <?php endif; ?>
        </p>

        <?php if ($modtager['mail'] !== ''): ?>
          <p class="tekst">En ny kode sendes til
            <b><?php echo $e($modtager['navn']); ?></b> (<?php echo $e($modtager['mail']); ?>).
            Den gamle kode holder op med at virke, og de logins, der er
            oprettet på adressen, lukkes — brug den, når huset skifter ejer,
            eller når nogen har brug for koden igen.</p>
          <form method="post" data-bekraeft="<?php echo $e(admin_send_spoergsmaal($hus, (int)$user['id'], $modtager)); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
            <button type="submit" name="handling" value="send-kode" class="gem">Send kode</button>
          </form>
        <?php else: ?>
          <p class="tekst">Koden kan ikke sendes: <?php echo $e($modtager['navn']); ?>
            har ingen brugbar mail. Skriv en mail ovenfor og gem, så kommer
            knappen frem.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<script>
  document.addEventListener('submit', function (e) {
    var spoerg = e.target.getAttribute('data-bekraeft');
    if (spoerg && !confirm(spoerg)) { e.preventDefault(); }
  });
</script>
</body>
</html>

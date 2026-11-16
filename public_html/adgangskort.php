<?php
/**
 * Adgangskort — bestyrelsens overblik over postkortene.
 *
 * Her ses, hvilke husstande der har oprettet sig, og her spærres et kort,
 * når et hus skifter ejer. Siden kræver login som bestyrelsesmedlem.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/adgang.php';
require_once __DIR__ . '/includes/mail.php';

auth_require('index.html');

$user = auth_user();

if (($user['role'] ?? '') !== 'bestyrelse') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kun bestyrelsen har adgang til denne side.');
}

$e = 'adgang_e';
$besked = '';
$fejl = '';

/** Husstande pr. udsendelse. Resten venter til næste klik. */
const SEND_AD_GANGEN = 25;

/** Mailadresserne fra beboerkartoteket, samlet pr. husstand. */
$mailKort = adgang_mail_pr_husstand($pdo);

/**
 * Adressen, links i mailen peger på.
 *
 * Skemaet tages fra den aktuelle forespørgsel, så linket virker både på
 * hesselbjergnord.dk og hvis siden en dag flytter.
 */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'hesselbjergnord.dk');
$base .= rtrim(dirname($_SERVER['SCRIPT_NAME']), "/\\");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $fejl = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
    } else {
        $handling = (string)($_POST['handling'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($handling === 'spaer' && $id > 0) {
            $pdo->prepare('UPDATE adgangskort SET spaerret = 1 WHERE id = :id')->execute([':id' => $id]);
            $besked = 'Kortet er spærret. Koden virker ikke længere.';
        } elseif ($handling === 'aabn' && $id > 0) {
            $pdo->prepare('UPDATE adgangskort SET spaerret = 0 WHERE id = :id')->execute([':id' => $id]);
            $besked = 'Kortet er åbnet igen.';
        } elseif ($handling === 'nykode' && $id > 0) {
            // Nyt hus, ny ejer: koden skiftes, og det gamle kort dør.
            do {
                $ny = adgang_ny_kode();
                $findes = $pdo->prepare('SELECT 1 FROM adgangskort WHERE kode = :k LIMIT 1');
                $findes->execute([':k' => $ny]);
            } while ($findes->fetchColumn());

            $pdo->prepare('UPDATE adgangskort SET kode = :k, spaerret = 0, antal_brug = 0, sidst_brugt = NULL WHERE id = :id')
                ->execute([':k' => $ny, ':id' => $id]);
            $besked = 'Kortet har fået ny kode: ' . adgang_kode_paent($ny) . '. Husk at printe det igen.';
        } elseif ($handling === 'tilfoej') {
            $vej = adresse_find_vej((string)($_POST['vej'] ?? ''));
            $nr = adresse_husnummer((string)($_POST['nummer'] ?? ''));

            if ($vej === null || $nr === null) {
                $fejl = 'Vælg en vej, og skriv et gyldigt husnummer.';
            } else {
                $adresse = adresse_saml($vej, $nr);
                $findes = $pdo->prepare('SELECT 1 FROM adgangskort WHERE adresse = :a LIMIT 1');
                $findes->execute([':a' => $adresse]);

                if ($findes->fetchColumn()) {
                    $fejl = $adresse . ' har allerede et kort.';
                } else {
                    do {
                        $ny = adgang_ny_kode();
                        $k = $pdo->prepare('SELECT 1 FROM adgangskort WHERE kode = :k LIMIT 1');
                        $k->execute([':k' => $ny]);
                    } while ($k->fetchColumn());

                    $pdo->prepare('INSERT INTO adgangskort (adresse, kode) VALUES (:a, :k)')
                        ->execute([':a' => $adresse, ':k' => $ny]);
                    $besked = 'Kort oprettet til ' . $adresse . ' med koden ' . adgang_kode_paent($ny) . '.';
                }
            }
        } elseif ($handling === 'slet' && $id > 0) {
            $pdo->prepare('DELETE FROM adgangskort WHERE id = :id')->execute([':id' => $id]);
            $besked = 'Kortet er fjernet fra listen.';
        } elseif ($handling === 'send' || $handling === 'send-alle') {
            // Ét kort, eller en bunke af dem der ikke har fået koden endnu.
            if ($handling === 'send') {
                $stmt = $pdo->prepare('SELECT * FROM adgangskort WHERE id = :id AND spaerret = 0 LIMIT 1');
                $stmt->execute([':id' => $id]);
                $bunke = array_filter([$stmt->fetch()]);
            } else {
                // Kun dem, der ikke er sendt før. Så kan en afbrudt
                // udsendelse genoptages uden at nogen får koden to gange.
                $kunNye = ($_POST['kun_nye'] ?? '1') === '1';

                $sql = 'SELECT * FROM adgangskort WHERE spaerret = 0'
                     . ($kunNye ? ' AND sendt_at IS NULL' : '')
                     . ' ORDER BY id LIMIT ' . SEND_AD_GANGEN;

                $bunke = $pdo->query($sql)->fetchAll();
            }

            $sendt = 0;
            $husstande = 0;
            $udenMail = [];
            $fejlede = [];

            $noter = $pdo->prepare(
                'UPDATE adgangskort SET sendt_at = NOW(), sendt_antal = sendt_antal + :n WHERE id = :id'
            );

            foreach ($bunke as $k) {
                $modtagere = adgang_modtagere($mailKort, $k['adresse']);

                if ($modtagere === []) {
                    $udenMail[] = $k['adresse'];
                    continue;
                }

                $svar = adgang_send_kode($k, $modtagere, $base . '/adgang.php?k=' . $k['kode']);

                if ($svar['ok'] > 0) {
                    $sendt += $svar['ok'];
                    $husstande++;
                    $noter->execute([':n' => $svar['ok'], ':id' => (int)$k['id']]);
                }

                foreach ($svar['fejlede'] as $m) {
                    $fejlede[] = $k['adresse'] . ': ' . $m;
                }
            }

            $dele = [];

            if ($husstande > 0) {
                $dele[] = 'Koden er sendt til ' . $sendt . ' modtager'
                    . ($sendt === 1 ? '' : 'e') . ' i ' . $husstande . ' husstand'
                    . ($husstande === 1 ? '' : 'e') . '.';
            }

            if ($udenMail !== []) {
                $dele[] = count($udenMail) . ' husstand' . (count($udenMail) === 1 ? '' : 'e')
                    . ' har ingen mail i beboerkartoteket: ' . implode(', ', array_slice($udenMail, 0, 8))
                    . (count($udenMail) > 8 ? ' m.fl.' : '');
            }

            if ($fejlede !== []) {
                $fejl = 'Mailen kunne ikke sendes til: ' . implode(', ', array_slice($fejlede, 0, 8));
            }

            $besked = $dele !== [] ? implode(' ' , $dele) : 'Der var ingen at sende til.';
        }
    }
}

$kort = $pdo->query(
    'SELECT k.id, k.adresse, k.kode, k.spaerret, k.antal_brug, k.sidst_brugt,
            k.sendt_at, k.sendt_antal,
            (SELECT COUNT(*) FROM users u WHERE u.address = k.adresse) AS brugere
       FROM adgangskort k'
)->fetchAll();

// Husnumre sorteres ikke rigtigt af databasen ("Klitrosevej 10" før "2").
usort($kort, static fn($a, $b) => strnatcasecmp($a['adresse'], $b['adresse']));

$antal = count($kort);
$oprettet = 0;
$spaerrede = 0;
$udenMailIalt = 0;
$manglerMail = 0;

foreach ($kort as $k) {
    if ((int)$k['brugere'] > 0) { $oprettet++; }
    if ((int)$k['spaerret'] === 1) { $spaerrede++; continue; }

    if (adgang_modtagere($mailKort, $k['adresse']) === []) {
        $udenMailIalt++;
    } elseif ($k['sendt_at'] === null) {
        $manglerMail++;
    }
}

$csrf = auth_csrf_token();
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Adgangskort — Grundejerforeningen Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { min-height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover;
    color: #fff;
    position: relative;
  }
  body::before { content: ""; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 0; }
  nav {
    position: relative; z-index: 2; width: 100%;
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; padding: 20px 15px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.45), transparent);
  }
  .nav-links { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; flex: 1; }
  nav a, .login-btn {
    color: #fff; text-decoration: none; font-size: 0.95rem; letter-spacing: 1px;
    padding: 8px 16px; border-radius: 20px; text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }
  nav a:hover, .login-btn:hover, nav a.active { background: rgba(255,255,255,0.18); }
  .nav-account { display: flex; align-items: center; gap: 10px; margin-right: 10px; white-space: nowrap; }
  .nav-user { font-size: 0.9rem; color: rgba(255,255,255,0.85); }
  .login-btn { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.4); }

  .container { position: relative; z-index: 1; max-width: 1100px; margin: 0 auto; padding: 24px 20px 80px; }
  .panel {
    background: rgba(10, 20, 25, 0.5);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px; padding: 24px; margin-bottom: 22px;
    backdrop-filter: blur(4px);
  }
  h1 { font-size: clamp(1.9rem, 4vw, 2.6rem); margin-bottom: 10px; }
  .lead { font-weight: 300; color: rgba(255,255,255,0.9); max-width: 70ch; line-height: 1.55; }

  .tal { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
  .tal div {
    padding: 10px 16px; background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.16); border-radius: 12px;
  }
  .tal b { display: block; font-size: 1.3rem; }
  .tal span { font-size: 0.8rem; color: rgba(255,255,255,0.6); }

  .vaerktoej { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; align-items: flex-end; }
  .felt { display: flex; flex-direction: column; gap: 5px; }
  .felt label { font-size: 0.8rem; color: rgba(255,255,255,0.65); }
  input, select, button { font: inherit; }
  input[type="text"], select {
    padding: 9px 11px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.08); color: #fff;
  }
  select option { background: #16222b; color: #fff; }
  button {
    background: rgba(255,255,255,0.12); color: #fff; padding: 9px 16px;
    border: 1px solid rgba(255,255,255,0.4); border-radius: 10px; cursor: pointer;
  }
  button:hover { background: rgba(255,255,255,0.2); }
  button.fare { border-color: rgba(255,140,140,0.5); color: #ffd3d3; }
  .print-knap {
    display: inline-block; text-decoration: none; color: #fff;
    background: rgba(70, 150, 225, 0.5); border: 1px solid rgba(150, 200, 255, 0.7);
    border-radius: 10px; padding: 11px 20px;
  }
  .print-knap:hover { background: rgba(80, 165, 240, 0.65); }

  /* Udsendelse på mail. */
  .udsend {
    margin-top: 24px; padding-top: 20px;
    border-top: 1px solid rgba(255,255,255,0.16);
  }
  .udsend h2 {
    font-size: 1.1rem; letter-spacing: 1px; text-transform: uppercase;
    color: rgba(150, 210, 255, 0.95); margin-bottom: 10px;
  }
  .udsend p {
    font-weight: 300; line-height: 1.55; color: rgba(255,255,255,0.85);
    max-width: 72ch; margin-bottom: 10px;
  }
  .udsend a { color: #cfe9ff; }
  .mangler-mail { color: rgba(255, 210, 150, 0.9); }
  .smaat { font-size: 0.86rem; color: rgba(255,255,255,0.6); line-height: 1.5; }
  button.send-knap {
    background: rgba(70, 150, 225, 0.55); border-color: rgba(150, 200, 255, 0.7);
    padding: 11px 22px;
  }
  button.send-knap:hover:not(:disabled) { background: rgba(80, 165, 240, 0.7); }
  button.send-knap:disabled { opacity: 0.5; cursor: default; }

  .besked { padding: 11px 14px; border-radius: 10px; margin-top: 16px; font-size: 0.93rem; }
  .besked.ok { background: rgba(40,140,70,0.32); color: #dcffe4; }
  .besked.fejl { background: rgba(180,40,40,0.35); color: #ffdede; }

  .tabel-rum { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; min-width: 720px; }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.12); }
  th { font-size: 0.78rem; letter-spacing: 1px; text-transform: uppercase; color: rgba(255,255,255,0.6); }
  td.kode { font-family: ui-monospace, Consolas, monospace; letter-spacing: 1px; }
  tr.brugt td { background: rgba(120, 220, 150, 0.07); }
  tr.spaerret td { background: rgba(220, 120, 120, 0.09); color: rgba(255,255,255,0.65); }
  .maerke {
    display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 0.8rem;
    border: 1px solid rgba(255,255,255,0.3); white-space: nowrap;
  }
  .maerke.ja { background: rgba(80,190,120,0.3); border-color: rgba(140,230,170,0.6); }
  .maerke.nej { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.65); }
  .maerke.stop { background: rgba(200,80,80,0.35); border-color: rgba(255,150,150,0.6); }
  .rad-knapper { display: flex; gap: 6px; flex-wrap: wrap; }
  .rad-knapper button { padding: 5px 10px; font-size: 0.82rem; }
  form.inline { display: inline; }
  footer {
    position: relative; z-index: 1; text-align: center; padding: 10px 10px 30px;
    font-size: 0.85rem; color: rgba(255,255,255,0.8);
  }
</style>
<link rel="stylesheet" href="mobile-nav.css">
<script src="mobile-nav.js" defer></script>
</head>
<body>
  <nav>
    <div class="nav-links">
      <a href="index.html">Forside</a>
      <a href="bestyrelsen.php">Bestyrelsen</a>
      <a href="omraade.html">Området</a>
      <a href="aarshjul.php">Årshjul</a>
      <a href="vedtaegter.php">Vedtægter</a>
      <a href="medlemsfotos.php">Medlemsfotos</a>
      <a href="generalforsamling.php">Generalforsamling</a>
      <a href="besked.php">Beskeder</a>
      <a href="adgangskort.php" class="active">Adgangskort</a>
      <a href="beboerkartotek.php">Beboerkartotek</a>
    </div>
    <div class="nav-account">
      <span class="nav-user">Logget ind som <?php echo $e($user['display_name']); ?></span>
      <a href="logout.php" class="login-btn">Log ud</a>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Adgangskort</h1>
      <p class="lead">Hver husstand har ét postkort med en QR-kode. Kortet hører
        til adressen og brænder ikke ved brug: begge ejere kan oprette hver
        deres login, og et glemt kodeord klares ved at scanne kortet igen.
        Skifter et hus ejer, så giv kortet en ny kode — så virker det gamle
        postkort ikke længere.</p>

      <div class="tal">
        <div><b><?php echo $antal; ?></b><span>husstande</span></div>
        <div><b><?php echo $oprettet; ?></b><span>har oprettet sig</span></div>
        <div><b><?php echo $antal - $oprettet; ?></b><span>mangler endnu</span></div>
        <div><b><?php echo $spaerrede; ?></b><span>spærrede kort</span></div>
      </div>

      <div class="vaerktoej">
        <a class="print-knap" href="adgangskort-print.php" target="_blank" rel="noopener">
          Print alle postkort
        </a>
        <a class="print-knap" href="adgangskort-print.php?kun=ubrugte" target="_blank" rel="noopener">
          Print kun dem, der mangler
        </a>
      </div>

      <div class="udsend">
        <h2>Send koden på mail</h2>
        <p>Mailadresserne kommer fra <a href="beboerkartotek.php">beboerkartoteket</a>.
          Både ejer og medejere på adressen får mailen — kortet hører til
          husstanden, ikke til én person. Hver modtager får sin egen mail, så
          ingen kan se hinandens adresser.</p>

        <?php if ($udenMailIalt > 0): ?>
          <p class="mangler-mail">
            <?php echo $udenMailIalt; ?> husstand<?php echo $udenMailIalt === 1 ? '' : 'e'; ?>
            har ingen mail i kartoteket og kan kun nås med et postkort.
          </p>
        <?php endif; ?>

        <form method="post" class="vaerktoej"
              onsubmit="return confirm('Send koden til op til <?php echo min($manglerMail, SEND_AD_GANGEN); ?> husstande nu?');">
          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
          <input type="hidden" name="kun_nye" value="1">
          <button type="submit" name="handling" value="send-alle" class="send-knap"
                  <?php echo $manglerMail === 0 ? 'disabled' : ''; ?>>
            <?php if ($manglerMail === 0): ?>
              Alle har fået koden
            <?php else: ?>
              Send til de næste <?php echo min($manglerMail, SEND_AD_GANGEN); ?>
              (<?php echo $manglerMail; ?> mangler)
            <?php endif; ?>
          </button>
        </form>

        <?php if ($manglerMail > SEND_AD_GANGEN): ?>
          <p class="smaat">Der sendes <?php echo SEND_AD_GANGEN; ?> ad gangen, så
            udsendelsen ikke løber ind i serverens tidsgrænse. Tryk igen, til
            tallet er nul — ingen får koden to gange.</p>
        <?php endif; ?>
      </div>

      <?php if ($besked !== ''): ?>
        <div class="besked ok"><?php echo $e($besked); ?></div>
      <?php endif; ?>
      <?php if ($fejl !== ''): ?>
        <div class="besked fejl"><?php echo $e($fejl); ?></div>
      <?php endif; ?>

      <form method="post" class="vaerktoej" style="margin-top:22px;">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
        <input type="hidden" name="handling" value="tilfoej">
        <div class="felt">
          <label for="vej">Tilføj en husstand</label>
          <select id="vej" name="vej">
            <?php foreach (HBN_VEJE as $v): ?>
              <option value="<?php echo $e($v); ?>"><?php echo $e($v); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="felt">
          <label for="nummer">Nr.</label>
          <input id="nummer" type="text" name="nummer" maxlength="4" style="width:90px;" placeholder="12">
        </div>
        <button type="submit">Opret kort</button>
      </form>
    </div>

    <div class="panel">
      <div class="tabel-rum">
        <table>
          <thead>
            <tr>
              <th>Adresse</th>
              <th>Kode</th>
              <th>Oprettet sig</th>
              <th>Sidst brugt</th>
              <th>Mail</th>
              <th>Handling</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($kort as $k): ?>
              <?php
                $erSpaerret = (int)$k['spaerret'] === 1;
                $brugere = (int)$k['brugere'];
                $klasse = $erSpaerret ? 'spaerret' : ($brugere > 0 ? 'brugt' : '');
              ?>
              <tr class="<?php echo $klasse; ?>">
                <td><?php echo $e($k['adresse']); ?></td>
                <td class="kode"><?php echo $e(adgang_kode_paent($k['kode'])); ?></td>
                <td>
                  <?php if ($erSpaerret): ?>
                    <span class="maerke stop">spærret</span>
                  <?php elseif ($brugere > 0): ?>
                    <span class="maerke ja"><?php echo $brugere; ?> bruger<?php echo $brugere === 1 ? '' : 'e'; ?></span>
                  <?php else: ?>
                    <span class="maerke nej">ikke endnu</span>
                  <?php endif; ?>
                </td>
                <td><?php echo $k['sidst_brugt'] ? $e(date('d-m-Y', strtotime($k['sidst_brugt']))) : '—'; ?></td>
                <td>
                  <?php $modt = adgang_modtagere($mailKort, $k['adresse']); ?>
                  <?php if ($modt === []): ?>
                    <span class="maerke nej">ingen mail</span>
                  <?php elseif ($k['sendt_at']): ?>
                    <span class="maerke ja" title="<?php echo $e(implode(', ', array_keys($modt))); ?>">
                      sendt <?php echo $e(date('d-m-Y', strtotime($k['sendt_at']))); ?>
                    </span>
                  <?php else: ?>
                    <span class="maerke nej" title="<?php echo $e(implode(', ', array_keys($modt))); ?>">
                      <?php echo count($modt); ?> modtager<?php echo count($modt) === 1 ? '' : 'e'; ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="rad-knapper">
                    <?php if (!$erSpaerret && $modt !== []): ?>
                      <form method="post" class="inline"
                            onsubmit="return confirm('Send koden til <?php echo $e($k['adresse']); ?>?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$k['id']; ?>">
                        <button type="submit" name="handling" value="send"><?php echo $k['sendt_at'] ? 'Send igen' : 'Send'; ?></button>
                      </form>
                    <?php endif; ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$k['id']; ?>">
                      <?php if ($erSpaerret): ?>
                        <button type="submit" name="handling" value="aabn">Åbn igen</button>
                      <?php else: ?>
                        <button type="submit" name="handling" value="spaer">Spær</button>
                      <?php endif; ?>
                    </form>
                    <form method="post" class="inline"
                          onsubmit="return confirm('Giv <?php echo $e($k['adresse']); ?> en ny kode? Det gamle postkort holder op med at virke.');">
                      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$k['id']; ?>">
                      <button type="submit" name="handling" value="nykode">Ny kode</button>
                    </form>
                    <form method="post" class="inline"
                          onsubmit="return confirm('Fjern <?php echo $e($k['adresse']); ?> fra listen?');">
                      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$k['id']; ?>">
                      <button type="submit" name="handling" value="slet" class="fare">Fjern</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <footer>&copy; <?php echo date('Y'); ?> Grundejerforeningen Hesselbjerg Nord</footer>
</body>
</html>

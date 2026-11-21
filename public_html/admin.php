<?php
/**
 * Admin — bestyrelsens overblik over husstandene.
 *
 * Én række pr. sommerhus: hvem der bor der (beboerkartoteket), om nogen
 * har oprettet et login, og en knap, der sender en ny adgangskode til
 * ejerens mail. En ny kode lukker de logins, der allerede er oprettet på
 * adressen — se admin_send_kode() i includes/admin.php.
 *
 * Erstatter beboerkartotek.php og adgangskort.php, der nu sender hertil.
 *
 * INDEHOLDER PERSONOPLYSNINGER. Kræver login som bestyrelsesmedlem og er
 * noindex.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
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
$soeg = trim((string)($_GET['s'] ?? $_POST['s'] ?? ''));
$vis = (string)($_GET['vis'] ?? $_POST['vis'] ?? '');

/** Tilbage til listen med samme filter og en besked. Så kan et genindlæs ikke sende igen. */
$tilbage = static function (string $besked, string $fejl = '') use ($soeg, $vis): void {
    $q = array_filter(['s' => $soeg, 'vis' => $vis, 'besked' => $besked, 'fejl' => $fejl], 'strlen');
    header('Location: admin.php' . ($q ? '?' . http_build_query($q) : ''), true, 303);
    exit;
};

$huse = admin_husstande($pdo);

/** Husstande, der får koden ved en samlet udsendelse: ingen login, aldrig sendt, ejer har mail. */
$tilUdsendelse = array_filter($huse, static fn($h) =>
    $h['personer'] !== []
    && $h['logins'] === []
    && $h['ejer']['mail'] !== ''
    && ($h['kort'] === null || $h['kort']['sendt_at'] === null));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $tilbage('', 'Sessionen er udløbet. Genindlæs siden, og prøv igen.');
    }

    // Den enkelte husstand får sin kode fra beboer-ret.php (Ret). Her er
    // kun den samlede udsendelse.
    $base = admin_base_url();
    $handling = (string)($_POST['handling'] ?? '');

    if ($handling === 'send-alle') {
        $sendt = 0;
        $fejlede = [];

        foreach (array_slice($tilUdsendelse, 0, ADMIN_SEND_AD_GANGEN) as $hus) {
            $svar = admin_send_kode($pdo, $hus, $base, (int)$user['id']);

            if ($svar['ok']) {
                $sendt++;
            } else {
                $fejlede[] = $hus['adresse'];
            }
        }

        $tilbage(
            'Koden er sendt til ' . $sendt . ' husstand' . ($sendt === 1 ? '' : 'e') . '.',
            $fejlede !== []
                ? 'Mailen kunne ikke sendes til: ' . implode(', ', array_slice($fejlede, 0, 8))
                    . (count($fejlede) > 8 ? ' m.fl.' : '')
                : ''
        );
    }

    $tilbage('', 'Ukendt handling.');
}

// Tallene på knapperne regnes på alle husstande, ikke kun dem der vises.
$filtre = [
    ''              => 'husstande',
    'ikke-oprettet' => 'uden login',
    'ingen-mail'    => 'ejer uden mail',
    'mangler'       => 'mangler hjemmeadresse',
    'problemer'     => 'mail/tlf kan ikke bruges',
    'bestyrelse'    => 'bestyrelse',
    'noter'         => 'med note',
    'uden-beboer'   => 'uden beboer',
];
$antal = [];

foreach (array_keys($filtre) as $f) {
    $antal[$f] = count(array_filter($huse, static fn($h) => admin_filter($h, $f)));
}

$viste = array_filter($huse, static fn($h) => admin_filter($h, $vis) && admin_soeg($h, $soeg));
$csrf = auth_csrf_token();
$besked = (string)($_GET['besked'] ?? '');
$fejl = (string)($_GET['fejl'] ?? '');
$naesteBunke = min(count($tilUdsendelse), ADMIN_SEND_AD_GANGEN);
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin — Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { min-height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover; color: #fff; position: relative;
  }
  body::before { content: ""; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 0; }
  nav {
    position: relative; z-index: 2; width: 100%; display: flex; align-items: center;
    justify-content: space-between; gap: 10px; padding: 20px 15px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.45), transparent);
  }
  .nav-links { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; flex: 1; }
  nav a, .login-btn {
    color: #fff; text-decoration: none; font-size: 0.95rem; letter-spacing: 1px;
    padding: 8px 16px; border-radius: 20px;
  }
  nav a:hover, .login-btn:hover, nav a.active { background: rgba(255,255,255,0.18); }
  .nav-account { display: flex; align-items: center; gap: 10px; margin-right: 10px; white-space: nowrap; }
  .nav-user { font-size: 0.9rem; color: rgba(255,255,255,0.85); }
  .login-btn { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.4); }

  .container { position: relative; z-index: 1; max-width: 1240px; margin: 0 auto; padding: 24px 20px 80px; }
  .panel {
    background: rgba(10, 20, 25, 0.55); border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px; padding: 24px; margin-bottom: 20px; backdrop-filter: blur(4px);
  }
  h1 { font-size: clamp(1.9rem, 4vw, 2.5rem); margin-bottom: 10px; }
  .lead { font-weight: 300; color: rgba(255,255,255,0.9); max-width: 72ch; line-height: 1.55; }
  .lead a, .smaat a { color: #cfe9ff; }
  .advarsel {
    margin-top: 14px; background: rgba(170, 120, 20, 0.25);
    border: 1px solid rgba(255, 200, 100, 0.4); border-radius: 12px;
    padding: 11px 14px; font-size: 0.9rem; line-height: 1.5;
  }
  .tal { display: flex; gap: 10px; flex-wrap: wrap; margin: 18px 0; }
  .tal a {
    padding: 10px 16px; background: rgba(255,255,255,0.06); text-decoration: none; color: #fff;
    border: 1px solid rgba(255,255,255,0.16); border-radius: 12px; display: block;
  }
  .tal a:hover, .tal a.valgt { background: rgba(150, 210, 255, 0.22); border-color: rgba(150,210,255,0.5); }
  .tal a.nul { opacity: 0.55; }
  .tal b { display: block; font-size: 1.3rem; }
  .tal span { font-size: 0.8rem; color: rgba(255,255,255,0.6); }
  .linje { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  form.soeg { display: flex; gap: 10px; flex: 1 1 420px; }
  input[type="search"] {
    flex: 1 1 260px; padding: 11px 14px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.08);
    color: #fff; font: inherit;
  }
  button {
    background: rgba(255,255,255,0.12); color: #fff; padding: 11px 20px; font: inherit;
    border: 1px solid rgba(255,255,255,0.4); border-radius: 10px; cursor: pointer;
  }
  button:hover:not(:disabled) { background: rgba(255,255,255,0.2); }
  button:disabled { opacity: 0.5; cursor: default; }
  a.tilfoej, button.blaa {
    display: inline-block; text-decoration: none; color: #fff;
    background: rgba(70, 150, 225, 0.5); border: 1px solid rgba(150, 200, 255, 0.7);
    border-radius: 10px; padding: 10px 20px;
  }
  a.tilfoej:hover, button.blaa:hover:not(:disabled) { background: rgba(80, 165, 240, 0.65); }
  .besked { padding: 11px 14px; border-radius: 10px; margin-top: 14px; font-size: 0.93rem; line-height: 1.5; }
  .besked.ok { background: rgba(40,140,70,0.32); color: #dcffe4; }
  .besked.fejl { background: rgba(180,40,40,0.35); color: #ffdede; }

  .tabel-rum { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; min-width: 860px; font-size: 0.9rem; }
  th, td { text-align: left; padding: 11px; border-bottom: 1px solid rgba(255,255,255,0.12); vertical-align: top; }
  th { font-size: 0.74rem; letter-spacing: 1px; text-transform: uppercase; color: rgba(255,255,255,0.6); }
  td.hus { white-space: nowrap; font-weight: 600; width: 1%; }
  td.login { width: 1%; white-space: nowrap; }
  td.knapper { width: 1%; white-space: nowrap; text-align: right; }
  tr.tom-hus td { color: rgba(255,255,255,0.6); }
  .person + .person { margin-top: 10px; padding-top: 10px; border-top: 1px dashed rgba(255,255,255,0.12); }
  .person.medejer { color: rgba(255,255,255,0.78); }
  .navn { font-weight: 600; }
  .detalje { font-size: 0.84rem; color: rgba(255,255,255,0.78); margin-top: 2px; }
  .detalje a { color: #cfe9ff; text-decoration: none; }
  .detalje a:hover { text-decoration: underline; }
  .note { font-size: 0.8rem; color: rgba(255,210,150,0.85); margin-top: 2px; }
  .stjerne { color: #ffd76a; cursor: help; }
  .maerke {
    display: inline-block; padding: 1px 9px; border-radius: 20px; font-size: 0.76rem;
    border: 1px solid rgba(255,255,255,0.3); white-space: nowrap; vertical-align: 1px;
  }
  .maerke.best { background: rgba(80,160,230,0.3); border-color: rgba(150,200,255,0.6); }
  .maerke.vent { background: rgba(220,170,60,0.3); border-color: rgba(255,210,120,0.6); }
  .maerke.ja { background: rgba(80,190,120,0.3); border-color: rgba(140,230,170,0.6); }
  .maerke.nej { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.65); }
  .maerke.stop { background: rgba(200,60,60,0.32); border-color: rgba(255,150,150,0.65); }
  .smaat { font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-top: 4px; }
  a.lille, button.lille, .ejer {
    display: inline-block; white-space: nowrap; font: inherit; font-size: 0.84rem;
    color: #cfe9ff; text-decoration: none; cursor: pointer; background: transparent;
    border: 1px solid rgba(255,255,255,0.28); border-radius: 8px; padding: 4px 12px;
  }
  a.lille:hover, button.lille:hover:not(:disabled), .ejer:hover { background: rgba(150,210,255,0.2); }
  button.ejer { color: rgba(255,255,255,0.7); border-style: dashed; }
  .knapper form { display: inline; }
  .knapper > * + * { margin-left: 4px; }


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
      <a href="hjertestarter.html">Hjertestarter</a>
      <a href="omraade.html">Området</a>
      <a href="aarshjul.php">Årshjul</a>
      <a href="vedtaegter.php">Vedtægter</a>
      <a href="medlemsfotos.php">Medlemsfotos</a>
      <a href="generalforsamling.php">Generalforsamling</a>
      <a href="besked.php">Beskeder</a>
      <a href="admin.php" class="active">Admin</a>
    </div>
    <div class="nav-account">
      <span class="nav-user">Logget ind som <?php echo $e($user['display_name']); ?></span>
      <a href="logout.php" class="login-btn">Log ud</a>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Admin</h1>
      <p class="lead">Én række pr. sommerhus: hvem der bor der, og om de har
        et login. Koden sendes til ejerens mail (<span class="stjerne">★</span>).
        Skal én husstand have en ny kode — fx når huset skifter ejer — så tryk
        <b>Ret</b> og <b>Send kode</b> derinde. Den gamle kode holder op med
        at virke, og de logins, der er oprettet på adressen, lukkes.</p>

      <div class="advarsel">
        Siden indeholder personoplysninger om navngivne mennesker. Del ikke
        skærmbilleder eller udtræk, og lad den ikke stå åben på en delt skærm.
      </div>

      <?php if ($besked !== ''): ?>
        <div class="besked ok"><?php echo $e($besked); ?></div>
      <?php endif; ?>
      <?php if ($fejl !== ''): ?>
        <div class="besked fejl"><?php echo $e($fejl); ?></div>
      <?php endif; ?>

      <div class="tal">
        <?php foreach ($filtre as $f => $tekst): ?>
          <?php
            $q = array_filter(['vis' => $f, 's' => $soeg], 'strlen');
            $klasse = trim(($vis === $f ? 'valgt' : '') . ($antal[$f] === 0 && $f !== '' ? ' nul' : ''));
          ?>
          <a href="admin.php<?php echo $q ? '?' . $e(http_build_query($q)) : ''; ?>" class="<?php echo $klasse; ?>">
            <b><?php echo $antal[$f]; ?></b><span><?php echo $e($tekst); ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="linje">
        <form method="get" class="soeg">
          <?php if ($vis !== ''): ?><input type="hidden" name="vis" value="<?php echo $e($vis); ?>"><?php endif; ?>
          <input type="search" name="s" placeholder="Søg på navn, adresse, mail, by eller telefon"
                 value="<?php echo $e($soeg); ?>">
          <button type="submit">Søg</button>
          <?php if ($soeg !== '' || $vis !== ''): ?>
            <a class="login-btn" href="admin.php" style="padding:11px 20px;">Nulstil</a>
          <?php endif; ?>
        </form>
        <a class="tilfoej" href="beboer-ret.php">+ Tilføj beboer</a>
        <form method="post" data-bekraeft="Send en kode til ejeren på <?php echo $naesteBunke; ?> husstande nu?">
          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
          <input type="hidden" name="s" value="<?php echo $e($soeg); ?>">
          <input type="hidden" name="vis" value="<?php echo $e($vis); ?>">
          <button type="submit" name="handling" value="send-alle" class="blaa"
                  title="Til ejeren på hver husstand, der hverken har et login eller har fået en kode før. <?php echo ADMIN_SEND_AD_GANGEN; ?> ad gangen."
                  <?php echo $naesteBunke === 0 ? 'disabled' : ''; ?>>
            <?php if ($naesteBunke === 0): ?>
              Alle har fået en kode
            <?php elseif (count($tilUdsendelse) > $naesteBunke): ?>
              Send kode til de næste <?php echo $naesteBunke; ?> (<?php echo count($tilUdsendelse); ?> mangler)
            <?php else: ?>
              Send kode til alle (<?php echo $naesteBunke; ?>)
            <?php endif; ?>
          </button>
        </form>
      </div>
      <?php if (count($tilUdsendelse) > ADMIN_SEND_AD_GANGEN): ?>
        <p class="smaat">Der sendes <?php echo ADMIN_SEND_AD_GANGEN; ?> ad gangen, så
          serveren ikke løber tør for tid. Tryk igen, til alle har fået en —
          ingen får koden to gange.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <?php if (!$viste): ?>
        <p class="lead">
          <?php if (!$huse): ?>
            Der er ingen husstande endnu. Læg regnearket op på
            <a href="beboer-import.php">importsiden</a>.
          <?php else: ?>
            Ingen husstande passer på søgningen.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <div class="tabel-rum">
          <table>
            <thead>
              <tr>
                <th>Sommerhus</th>
                <th>Beboere</th>
                <th>Login</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($viste as $noegle => $h): ?>
                <?php
                  // Under "Bestyrelse" vises kun bestyrelsesmedlemmerne selv,
                  // ikke medejerne på deres adresse.
                  $personer = $vis === 'bestyrelse'
                      ? array_values(array_filter($h['personer'], static fn($p) => (int)$p['bestyrelse'] === 1))
                      : $h['personer'];
                ?>
                <tr class="<?php echo $h['personer'] === [] ? 'tom-hus' : ''; ?>">
                  <td class="hus"><?php echo $e($h['adresse']); ?></td>

                  <td>
                    <?php if ($h['personer'] === []): ?>
                      <span class="maerke nej">ingen i kartoteket</span>
                    <?php endif; ?>
                    <?php foreach ($personer as $p): ?>
                      <?php
                        $faarKoden = (int)$p['id'] === $h['ejer']['id'];
                        $venter = $p['status'] === 'afventer_oplysninger';
                      ?>
                      <div class="person <?php echo $p['forhold'] === 'medejer' ? 'medejer' : ''; ?>">
                        <span class="navn"><?php echo $e($p['kontaktperson']); ?></span>
                        <?php if ($faarKoden): ?>
                          <span class="stjerne" title="Koden sendes til <?php echo $e($h['ejer']['mail']); ?>">★</span>
                        <?php endif; ?>
                        <?php if ($p['forhold'] === 'medejer'): ?>
                          <span class="maerke nej">medejer</span>
                        <?php endif; ?>
                        <?php if ((int)$p['bestyrelse'] === 1): ?>
                          <span class="maerke best"><?php echo $e($p['rolle'] !== '' ? $p['rolle'] : 'Bestyrelse'); ?></span>
                        <?php endif; ?>

                        <?php
                          $kontakt = [];
                          foreach (['mail', 'mail2'] as $felt) {
                              if ($p[$felt] !== '') {
                                  $kontakt[] = '<a href="mailto:' . $e($p[$felt]) . '">' . $e($p[$felt]) . '</a>';
                              }
                          }
                          foreach (['telefon', 'telefon2'] as $felt) {
                              if ($p[$felt] !== '') {
                                  $kontakt[] = $e($p[$felt]);
                              }
                          }
                        ?>
                        <?php if ($kontakt): ?>
                          <div class="detalje"><?php echo implode(' · ', $kontakt); ?></div>
                        <?php endif; ?>

                        <div class="detalje">
                          <?php if ($venter): ?>
                            <span class="maerke vent">afventer hjemmeadresse</span>
                          <?php else: ?>
                            <?php echo $e(implode(', ', array_filter([
                                $p['hjemmeadresse'], $p['sted'], trim($p['postnr'] . ' ' . $p['by']),
                                $p['land'] !== 'Danmark' ? $p['land'] : '',
                            ], static fn($x) => trim((string)$x) !== ''))); ?>
                          <?php endif; ?>
                        </div>

                        <?php foreach (beboer_problemer($p) as $hvad): ?>
                          <div><span class="maerke stop"><?php echo $e($hvad); ?></span></div>
                        <?php endforeach; ?>
                        <?php if (trim((string)$p['noter']) !== ''): ?>
                          <div class="note"><?php echo $e($p['noter']); ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </td>

                  <td class="login">
                    <?php if ($h['logins'] !== []): ?>
                      <?php $n = count($h['logins']); ?>
                      <span class="maerke ja" title="<?php echo $e(implode(', ', array_column($h['logins'], 'username'))); ?>">
                        <?php echo $n; ?> login<?php echo $n === 1 ? '' : 's'; ?>
                      </span>
                    <?php elseif ($h['personer'] !== []): ?>
                      <span class="maerke nej">ingen login</span>
                    <?php endif; ?>
                    <?php if ($h['personer'] !== [] && $h['ejer']['mail'] === ''): ?>
                      <div><span class="maerke stop" title="Koden kan ikke sendes, før ejeren har en brugbar mail.">ejer uden mail</span></div>
                    <?php endif; ?>
                    <?php if ($h['kort'] !== null && $h['kort']['sendt_at']): ?>
                      <div class="smaat">kode sendt <?php echo $e(date('d-m-Y', strtotime($h['kort']['sendt_at']))); ?></div>
                    <?php endif; ?>
                  </td>

                  <td class="knapper">
                    <?php
                      $bfe = bfe_for_raekke($h['personer'][0] ?? ['sommerhusadresse' => $h['adresse']]);
                    ?>
                    <?php if ($bfe !== ''): ?>
                      <a class="ejer" target="_blank" rel="noopener"
                         title="BFE <?php echo $e($bfe); ?> — åbner OIS i en ny fane"
                         href="<?php echo $e(bfe_link($bfe)); ?>">Ejer</a>
                    <?php else: ?>
                      <button type="button" class="ejer" data-ejer-adresse="<?php
                          echo $e($h['adresse'] . ', ' . HBN_POSTNR . ' ' . HBN_BY);
                      ?>" title="Kopierer adressen og åbner boligejer.dk">Find ejer</button>
                    <?php endif; ?>

                    <?php foreach ($personer as $p): ?>
                      <?php
                        // Bor der flere, står fornavnet på knappen, så man kan se, hvem man retter.
                        $fornavn = strtok(trim((string)$p['kontaktperson']), ' ') ?: '';
                      ?>
                      <a class="lille" href="beboer-ret.php?id=<?php echo (int)$p['id']; ?>"
                         title="Ret <?php echo $e($p['kontaktperson']); ?>">Ret<?php
                         echo count($personer) > 1 && $fornavn !== '' ? ' ' . $e($fornavn) : ''; ?></a>
                    <?php endforeach; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <p class="lead">
        <?php if ($antal['ingen-mail'] > 0): ?>
          <a href="admin.php?vis=ingen-mail"><?php echo $antal['ingen-mail']; ?>
            husstand<?php echo $antal['ingen-mail'] === 1 ? '' : 'e'; ?></a> kan ikke få en kode,
          fordi ejeren ikke har en brugbar mail. &nbsp;·&nbsp;
        <?php endif; ?>
        <a href="beboer-import.php">Importér regneark</a>
      </p>
    </div>
  </div>

  <footer>&copy; <?php echo date('Y'); ?> Grundejerforeningen Hesselbjerg Nord</footer>
<script>
  /* Formularer med data-bekraeft spørger først. Teksten ligger i et
     attribut og ikke i onsubmit, så en adresse eller mail med ' i ikke
     ødelægger JavaScriptet. */
  document.addEventListener('submit', function (e) {
    var spoerg = e.target.getAttribute('data-bekraeft');
    if (spoerg && !confirm(spoerg)) { e.preventDefault(); }
  });

  /* "Find ejer": BFE-nummeret mangler. Kopiér adressen og åbn
     boligejer.dk, hvor den kan søges frem. Nummeret til sidst i
     OIS-adressen gemmes på beboer-ret.php, og derefter er knappen et
     direkte link. */
  (function () {
    function kopier(tekst) {
      try {
        var felt = document.createElement('textarea');
        felt.value = tekst;
        felt.setAttribute('readonly', '');
        felt.style.position = 'fixed';
        felt.style.top = '-1000px';
        document.body.appendChild(felt);
        felt.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(felt);
        if (ok) { return true; }
      } catch (e) { /* falder igennem til Clipboard-API'et */ }

      if (navigator.clipboard) {
        navigator.clipboard.writeText(tekst);
        return true;
      }
      return false;
    }

    document.addEventListener('click', function (e) {
      var mål = e.target instanceof Element ? e.target : null;
      var knap = mål ? mål.closest('button[data-ejer-adresse]') : null;
      if (!knap) { return; }

      var adresse = knap.getAttribute('data-ejer-adresse');
      var ok = kopier(adresse);
      var oprindelig = knap.textContent;
      knap.textContent = ok ? 'Adressen er kopieret' : adresse;
      setTimeout(function () { knap.textContent = oprindelig; }, 4000);

      window.open('https://boligejer.dk/', '_blank', 'noopener');
    });
  })();
</script>
</body>
</html>

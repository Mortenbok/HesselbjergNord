<?php
/**
 * Admin — bestyrelsens overblik over husstandene.
 *
 * Én række pr. sommerhus og én linje pr. person: hvem der bor der
 * (beboerkartoteket), og om hver person har et login. Hver person får sin
 * egen kode på sin egen mail — se admin_send_kode() i includes/admin.php.
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
require_once __DIR__ . '/includes/ejertjek.php';

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

// Ejertjekket fra OIS: farven regnes her, mod kartoteket som det ser ud
// lige nu, så en rettelse under Ret slår igennem med det samme.
$ejertjek = ejertjek_alle($pdo);

foreach ($huse as $noegle => &$h) {
    $h['bfe'] = ejertjek_bfe($h);
    $h['ejertjek'] = ejertjek_status($h, $ejertjek[$noegle] ?? null, $h['bfe']);
}
unset($h);

/** Personer, der får koden ved en samlet udsendelse: har mail, intet login, aldrig fået en kode. */
$tilUdsendelse = [];

foreach ($huse as $h) {
    foreach (admin_mangler_login($h) as $p) {
        if ($p['sendt'] === null) {
            $tilUdsendelse[] = ['hus' => $h, 'person' => $p];
        }
    }
}

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

        foreach (array_slice($tilUdsendelse, 0, ADMIN_SEND_AD_GANGEN) as $t) {
            $svar = admin_send_kode($pdo, $t['hus'], $t['person'], $base, (int)$user['id']);

            if ($svar['ok']) {
                $sendt++;
            } else {
                $fejlede[] = $t['person']['kontaktperson'] . ' (' . $t['hus']['adresse'] . ')';
            }
        }

        $tilbage(
            'Koden er sendt til ' . $sendt . ' person' . ($sendt === 1 ? '' : 'er') . '.',
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
    'ikke-oprettet' => 'nogen mangler login',
    'ingen-mail'    => 'ejer uden mail',
    'mangler'       => 'mangler hjemmeadresse',
    'problemer'     => 'mail/tlf kan ikke bruges',
    'bestyrelse'    => 'bestyrelse',
    'noter'         => 'med note',
    'uden-beboer'   => 'uden beboer',
    'ejer-forkert'  => 'ejer passer ikke med OIS',
];

// Filteret på ejertjekket ligger her og ikke i admin_filter(), fordi det
// bruger farven, der er regnet ovenfor.
$passer = static fn(array $h, string $f): bool => $f === 'ejer-forkert'
    ? $h['ejertjek']['farve'] === 'nej'
    : admin_filter($h, $f);

$antal = [];

foreach (array_keys($filtre) as $f) {
    $antal[$f] = count(array_filter($huse, static fn($h) => $passer($h, $f)));
}

$viste = array_filter($huse, static fn($h) => $passer($h, $vis) && admin_soeg($h, $soeg));

// Hvornår OIS sidst blev spurgt — står under tabellen.
$sidstTjekket = $ejertjek ? max(array_column($ejertjek, 'tjekket_at')) : null;
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
  td.knap-celle { width: 1%; white-space: nowrap; padding-left: 4px; }
  /* Personer i samme husstand skilles med en stiplet linje, husstande med en hel. */
  tbody.husstand tr:not(:last-child) td:not([rowspan]) { border-bottom-style: dashed; }
  tbody.tom-hus td { color: rgba(255,255,255,0.6); }
  .person.medejer { color: rgba(255,255,255,0.78); }
  .navn { font-weight: 600; }
  .detalje { font-size: 0.84rem; color: rgba(255,255,255,0.78); margin-top: 2px; }
  .detalje a { color: #cfe9ff; text-decoration: none; }
  .detalje a:hover { text-decoration: underline; }
  .note { font-size: 0.8rem; color: rgba(255,210,150,0.85); margin-top: 2px; }
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
  /* Ejertjekket mod OIS: grøn = en fra kartoteket står som ejer, rød = ingen. */
  a.ejer.ok { color: #dcffe4; background: rgba(60,170,95,0.4); border-color: rgba(140,230,170,0.75); }
  a.ejer.ok:hover { background: rgba(60,170,95,0.6); }
  a.ejer.nej { color: #ffe0e0; background: rgba(200,60,60,0.45); border-color: rgba(255,150,150,0.75); }
  a.ejer.nej:hover { background: rgba(200,60,60,0.65); }


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
      <p class="lead">Én række pr. sommerhus og én linje pr. person: hvem der
        bor der, og om hver af dem har et login. Alle — ejer som medejer — får
        deres egen kode på deres egen mail. Skal én have en ny kode, så tryk
        <b>Ret</b> ud for personen og <b>Send kode</b> derinde. Personens
        gamle kode holder op med at virke; de andre på adressen røres ikke.</p>

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
        <form method="post" data-bekraeft="Send en kode til <?php echo $naesteBunke; ?> personer nu?">
          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
          <input type="hidden" name="s" value="<?php echo $e($soeg); ?>">
          <input type="hidden" name="vis" value="<?php echo $e($vis); ?>">
          <button type="submit" name="handling" value="send-alle" class="blaa"
                  title="Til hver person — ejer og medejer — der har en mail, men hverken har et login eller har fået en kode før. <?php echo ADMIN_SEND_AD_GANGEN; ?> ad gangen."
                  <?php echo $naesteBunke === 0 ? 'disabled' : ''; ?>>
            <?php if ($naesteBunke === 0): ?>
              Alle med mail har fået en kode
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
                <th></th>
              </tr>
            </thead>
            <?php foreach ($viste as $h): ?>
              <?php
                // Under "Bestyrelse" vises kun bestyrelsesmedlemmerne selv,
                // ikke medejerne på deres adresse.
                $personer = $vis === 'bestyrelse'
                    ? array_values(array_filter($h['personer'], static fn($p) => (int)$p['bestyrelse'] === 1))
                    : $h['personer'];
                // Én tabelrække pr. person, så Ret står ud for den, den retter.
                // Adresse, login og Ejer spænder over hele husstanden.
                $raekker = $personer !== [] ? $personer : [null];
                $span = count($raekker);
                $bfe = $h['bfe'];
              ?>
              <tbody class="husstand<?php echo $h['personer'] === [] ? ' tom-hus' : ''; ?>">
              <?php foreach ($raekker as $i => $p): ?>
                <tr>
                  <?php if ($i === 0): ?>
                    <td class="hus" rowspan="<?php echo $span; ?>"><?php echo $e($h['adresse']); ?></td>
                  <?php endif; ?>

                  <td class="person-celle">
                    <?php if ($p === null): ?>
                      <span class="maerke nej">ingen i kartoteket</span>
                    <?php else: ?>
                      <?php $venter = $p['status'] === 'afventer_oplysninger'; ?>
                      <div class="person <?php echo $p['forhold'] === 'medejer' ? 'medejer' : ''; ?>">
                        <span class="navn"><?php echo $e($p['kontaktperson']); ?></span>
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
                    <?php endif; ?>
                  </td>

                  <td class="login">
                    <?php if ($p !== null): ?>
                      <?php if ($p['login'] !== null): ?>
                        <span class="maerke ja" title="<?php echo $e($p['login']['username']); ?>">login</span>
                      <?php elseif (admin_person_mails($p) === []): ?>
                        <span class="maerke stop" title="Koden kan ikke sendes, før personen har en brugbar mail.">ingen mail</span>
                      <?php else: ?>
                        <span class="maerke nej">ingen login</span>
                      <?php endif; ?>
                      <?php if ($p['sendt'] !== null): ?>
                        <div class="smaat">kode sendt <?php echo $e(date('d-m-Y', strtotime($p['sendt']))); ?></div>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($i === $span - 1 && $h['andre_logins'] !== []): ?>
                      <?php $n = count($h['andre_logins']); ?>
                      <div class="smaat" title="<?php echo $e(implode(', ', array_column($h['andre_logins'], 'username'))); ?>">
                        + <?php echo $n; ?> andet login<?php echo $n === 1 ? '' : 's'; ?> på adressen
                      </div>
                    <?php endif; ?>
                  </td>

                  <?php if ($i === 0): ?>
                    <td class="knap-celle" rowspan="<?php echo $span; ?>">
                      <?php if ($bfe !== ''): ?>
                        <a class="ejer <?php echo $e($h['ejertjek']['farve']); ?>" target="_blank" rel="noopener"
                           title="<?php echo $e($h['ejertjek']['tekst'] . "\nBFE " . $bfe . ' — åbner OIS i en ny fane'); ?>"
                           href="<?php echo $e(bfe_link($bfe)); ?>">Ejer</a>
                      <?php else: ?>
                        <button type="button" class="ejer" data-ejer-adresse="<?php
                            echo $e($h['adresse'] . ', ' . HBN_POSTNR . ' ' . HBN_BY);
                        ?>" title="Kopierer adressen og åbner boligejer.dk">Find ejer</button>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>

                  <td class="knap-celle">
                    <?php if ($p !== null): ?>
                      <a class="lille" href="beboer-ret.php?id=<?php echo (int)$p['id']; ?>"
                         title="Ret <?php echo $e($p['kontaktperson']); ?>">Ret</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            <?php endforeach; ?>
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
      <p class="smaat" style="margin-top:10px;">
        Ejer-knappen er <b style="color:#9fe6b4;">grøn</b>, når mindst én fra
        kartoteket står som ejer hos OIS, og <b style="color:#ffb0b0;">rød</b>,
        når ingen af dem gør. Uden farve er den ikke tjekket endnu, eller OIS
        kunne ikke svare — hold musen over knappen for at se navnene.
        <?php if ($sidstTjekket): ?>
          OIS blev sidst spurgt <?php echo $e(date('d-m-Y', strtotime($sidstTjekket))); ?>;
        <?php endif; ?>
        det sker af sig selv den 1. i hver måned.
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

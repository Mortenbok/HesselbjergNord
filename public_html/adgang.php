<?php
/**
 * Adgang — siden, QR-koden på husstandens postkort fører hen til.
 *
 * Adressen kommer fra kortet og kan ikke vælges. Den besøgende skriver kun
 * navn, mail og en adgangskode, og bliver logget ind med det samme.
 *
 * Siden er offentlig, men uden en gyldig kode viser den ingenting — hverken
 * hvilke adresser der findes, eller om en kode er tæt på at være rigtig.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/adgang.php';

auth_start_session();

$e = 'adgang_e';
$kode = (string)($_GET['k'] ?? $_POST['k'] ?? '');
$kort = adgang_find_kort($pdo, $kode);

$fejl = '';
$done = false;
$fornyet = false;
$rolle = 'medlem';
$form = ['navn' => '', 'mail' => ''];

// Et spærret kort behandles som et ukendt kort udadtil, men med sin egen
// besked — ellers kan man ikke forstå, hvorfor kortet ikke virker.
$spaerret = $kort !== null && (int)$kort['spaerret'] === 1;

if ($kort !== null && !$spaerret && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['navn'] = trim((string)($_POST['navn'] ?? ''));
    $form['mail'] = trim((string)($_POST['mail'] ?? ''));
    $pw = (string)($_POST['adgangskode'] ?? '');
    $pw2 = (string)($_POST['gentag'] ?? '');

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $fejl = 'Siden var udløbet. Prøv at sende igen.';
    } elseif ($pw !== $pw2) {
        $fejl = 'De to adgangskoder er ikke ens.';
    } else {
        $svar = adgang_opret_bruger($pdo, $kort, $form['navn'], $form['mail'], $pw);

        if (!$svar['ok']) {
            $fejl = $svar['fejl'];
        } else {
            adgang_noter_brug($pdo, (int)$kort['id']);
            adgang_log_ind($pdo, $svar['id']);
            $done = true;
            $fornyet = !empty($svar['fornyet']);
            $rolle = (string)($svar['rolle'] ?? 'medlem');
        }
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
<title>Få adgang — Grundejerforeningen Hesselbjerg Nord</title>
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
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px 60px;
  }
  body::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
  }
  .panel {
    position: relative;
    z-index: 1;
    width: min(96vw, 460px);
    background: rgba(10, 20, 25, 0.78);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 18px;
    padding: 28px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.35);
    backdrop-filter: blur(4px);
  }
  .brand {
    font-size: 0.8rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.6);
    margin-bottom: 6px;
  }
  h1 { font-size: 1.7rem; margin-bottom: 12px; }
  p.intro {
    font-weight: 300;
    line-height: 1.55;
    color: rgba(255,255,255,0.88);
    margin-bottom: 20px;
  }
  .adresse {
    display: block;
    padding: 12px 16px;
    margin-bottom: 20px;
    border-radius: 12px;
    background: rgba(150, 210, 255, 0.16);
    border: 1px solid rgba(150, 210, 255, 0.45);
    font-size: 1.15rem;
    font-weight: 600;
  }
  .adresse small {
    display: block;
    font-size: 0.78rem;
    font-weight: 400;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.6);
    margin-bottom: 2px;
  }
  label {
    display: block;
    margin-bottom: 6px;
    font-size: 0.88rem;
    color: rgba(255,255,255,0.8);
  }
  input {
    width: 100%;
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.24);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
    /* 16px forhindrer, at iPhone zoomer ind, når feltet får fokus. */
    font-size: 16px;
  }
  input:focus-visible { outline: 2px solid rgba(255,255,255,0.7); outline-offset: 1px; }
  button {
    width: 100%;
    background: rgba(70, 150, 225, 0.55);
    color: #fff;
    border: 1px solid rgba(150, 200, 255, 0.7);
    border-radius: 10px;
    padding: 13px 18px;
    font: inherit;
    font-size: 1.02rem;
    cursor: pointer;
  }
  button:hover { background: rgba(80, 165, 240, 0.7); }
  .besked {
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 18px;
    font-size: 0.93rem;
    line-height: 1.5;
  }
  .besked.fejl { background: rgba(180,40,40,0.35); color: #ffdede; }
  .besked.ok { background: rgba(40,140,70,0.32); color: #dcffe4; }
  .hjaelp {
    margin-top: 18px;
    font-size: 0.86rem;
    line-height: 1.5;
    color: rgba(255,255,255,0.6);
  }
  .hjaelp a { color: #cfe9ff; }
  .knap-link {
    display: block;
    text-align: center;
    margin-top: 16px;
    padding: 13px 18px;
    border-radius: 10px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.4);
    color: #fff;
    text-decoration: none;
  }
  .knap-link:hover { background: rgba(255,255,255,0.2); }
</style>
</head>
<body>
  <div class="panel">
    <p class="brand">Grundejerforeningen</p>

    <?php if ($done): ?>
      <h1>Velkommen</h1>
      <div class="besked ok">
        <?php if ($fornyet): ?>
          Din adgangskode er sat på ny, og du er logget ind.
        <?php else: ?>
          Din bruger er oprettet, og du er logget ind.
        <?php endif; ?>
      </div>
      <p class="intro">Du logger fremover ind med din mailadresse
        <strong><?php echo $e($form['mail']); ?></strong> og den adgangskode, du
        lige har valgt.</p>

      <?php if ($rolle === 'bestyrelse'): ?>
        <p class="intro">Du står i beboerkartoteket som bestyrelsesmedlem, så du
          har også adgang til bestyrelsens sider — beskeder og admin.</p>
      <?php endif; ?>

      <a class="knap-link" href="medlemsfotos.php">Gå til medlemsområdet</a>

    <?php elseif ($kort === null): ?>
      <h1>Koden blev ikke genkendt</h1>
      <p class="intro">Tjek, at koden er tastet rigtigt — den står med store
        bogstaver på postkortet, fx <strong>K7M2-P9QX</strong>. Virker den
        stadig ikke, så skriv til bestyrelsen.</p>

      <form method="get">
        <label for="k">Kode fra postkortet</label>
        <input id="k" name="k" type="text" required autocapitalize="characters"
               placeholder="K7M2-P9QX" value="<?php echo $e($kode); ?>">
        <button type="submit">Fortsæt</button>
      </form>

      <p class="hjaelp">Har du ikke fået et postkort, så skriv til
        <a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a>.</p>

    <?php elseif ($spaerret): ?>
      <h1>Kortet er spærret</h1>
      <p class="intro">Koden på dette postkort er ikke længere gyldig. Det sker
        typisk, når et hus har skiftet ejer, og der er lavet et nyt kort.</p>
      <p class="hjaelp">Skriv til
        <a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a>,
        så sender vi et nyt.</p>

    <?php else: ?>
      <h1>Få adgang</h1>
      <p class="intro">Velkommen til Hesselbjerg Nords Medlemsportal. Adgangen
        hører til adressen herunder. Udfyld felterne, så kan du se dagsordener,
        referater, regnskaber, vedtægter og medlemsbilleder.</p>

      <span class="adresse">
        <small>Adresse</small>
        <?php echo $e($kort['adresse']); ?>
      </span>

      <?php if ($fejl !== ''): ?>
        <div class="besked fejl"><?php echo $e($fejl); ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
        <input type="hidden" name="k" value="<?php echo $e($kort['kode']); ?>">

        <label for="navn">Dit navn</label>
        <input id="navn" name="navn" type="text" required maxlength="255"
               autocomplete="name" value="<?php echo $e($form['navn']); ?>">

        <label for="mail">Din mail — den bruger du til at logge ind</label>
        <input id="mail" name="mail" type="email" required maxlength="64"
               autocomplete="email" value="<?php echo $e($form['mail']); ?>">

        <label for="adgangskode">Vælg en adgangskode (mindst 8 tegn)</label>
        <input id="adgangskode" name="adgangskode" type="password" required
               minlength="8" autocomplete="new-password">

        <label for="gentag">Gentag adgangskoden</label>
        <input id="gentag" name="gentag" type="password" required
               minlength="8" autocomplete="new-password">

        <button type="submit">Opret min adgang</button>
      </form>

    <?php endif; ?>
  </div>
</body>
</html>

<?php
/**
 * Resten af oplysningerne — siden, bekræftelsesmailen fører hen til.
 *
 * Tilmeldingen på ny-beboer.php spørger kun om det nødvendige. Her udfyldes
 * hjemmeadressen, så indkaldelser og opkrævninger havner det rigtige sted.
 *
 * Siden er offentlig, men viser kun noget, hvis linkets token er gyldigt og
 * ikke udløbet. Uden token afsløres hverken navne eller adresser.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/beboer.php';

auth_start_session();

$e = 'beboer_e';
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$beboer = beboer_find_token($pdo, $token);

$fejl = '';
$done = false;
$form = [
    'hjemmeadresse' => '', 'postnr' => '', 'by' => '',
    'land' => '', 'mail2' => '', 'telefon2' => '',
];

if ($beboer !== null) {
    foreach ($form as $k => $_) {
        $form[$k] = (string)($beboer[$k] ?? '');
    }
}

if ($beboer !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $fejl = 'Siden var udløbet. Prøv at sende igen.';
    } elseif (mb_strlen($form['hjemmeadresse']) < 4) {
        $fejl = 'Skriv din hjemmeadresse — vej og husnummer.';
    } elseif (!preg_match('/^[A-Za-z]{0,2}-?\d{4,6}$/', $form['postnr'])) {
        $fejl = 'Postnummeret ser ikke rigtigt ud.';
    } elseif (mb_strlen($form['by']) < 2) {
        $fejl = 'Skriv byen.';
    } elseif ($form['mail2'] !== '' && !filter_var($form['mail2'], FILTER_VALIDATE_EMAIL)) {
        $fejl = 'Den anden mailadresse ser ikke rigtig ud.';
    } else {
        $cifre = preg_replace('/\s+/', '', $form['telefon2']) ?? '';

        if ($form['telefon2'] !== '' && !preg_match('/^\+?\d{8,15}$/', $cifre)) {
            $fejl = 'Det andet telefonnummer ser ikke rigtigt ud.';
        } else {
            beboer_gem_oplysninger($pdo, (int)$beboer['id'], $form);
            $done = true;
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
<title>Dine oplysninger — Grundejerforeningen Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { min-height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover; color: #fff; position: relative;
    display: flex; align-items: center; justify-content: center;
    padding: 24px 16px 60px;
  }
  body::before { content: ""; position: fixed; inset: 0; background: rgba(0,0,0,0.55); }
  .panel {
    position: relative; z-index: 1; width: min(96vw, 520px);
    background: rgba(10, 20, 25, 0.78); border: 1px solid rgba(255,255,255,0.2);
    border-radius: 18px; padding: 28px; backdrop-filter: blur(4px);
    box-shadow: 0 20px 50px rgba(0,0,0,0.35);
  }
  .brand {
    font-size: 0.8rem; letter-spacing: 3px; text-transform: uppercase;
    color: rgba(255,255,255,0.6); margin-bottom: 6px;
  }
  h1 { font-size: 1.7rem; margin-bottom: 12px; }
  p.intro { font-weight: 300; line-height: 1.55; color: rgba(255,255,255,0.88); margin-bottom: 20px; }
  .adresse {
    display: block; padding: 12px 16px; margin-bottom: 22px; border-radius: 12px;
    background: rgba(150, 210, 255, 0.16); border: 1px solid rgba(150, 210, 255, 0.45);
    font-size: 1.1rem; font-weight: 600;
  }
  .adresse small {
    display: block; font-size: 0.75rem; font-weight: 400; letter-spacing: 1px;
    text-transform: uppercase; color: rgba(255,255,255,0.6); margin-bottom: 2px;
  }
  label { display: block; margin-bottom: 6px; font-size: 0.88rem; color: rgba(255,255,255,0.8); }
  input {
    width: 100%; margin-bottom: 16px; padding: 12px 14px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.24); background: rgba(255,255,255,0.08);
    color: #fff; font: inherit; font-size: 16px;
  }
  input:focus-visible { outline: 2px solid rgba(255,255,255,0.7); outline-offset: 1px; }
  .to { display: flex; gap: 12px; }
  .to .lille { flex: 0 0 120px; }
  .to .stor { flex: 1; min-width: 0; }
  button {
    width: 100%; background: rgba(70, 150, 225, 0.55); color: #fff;
    border: 1px solid rgba(150, 200, 255, 0.7); border-radius: 10px;
    padding: 13px 18px; font: inherit; font-size: 1.02rem; cursor: pointer;
  }
  button:hover { background: rgba(80, 165, 240, 0.7); }
  .besked { padding: 12px 14px; border-radius: 10px; margin-bottom: 18px; font-size: 0.93rem; line-height: 1.5; }
  .besked.fejl { background: rgba(180,40,40,0.35); color: #ffdede; }
  .besked.ok { background: rgba(40,140,70,0.32); color: #dcffe4; }
  .hjaelp { margin-top: 18px; font-size: 0.86rem; line-height: 1.5; color: rgba(255,255,255,0.6); }
  .hjaelp a { color: #cfe9ff; }
  .knap-link {
    display: block; text-align: center; margin-top: 16px; padding: 13px 18px;
    border-radius: 10px; background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.4); color: #fff; text-decoration: none;
  }
  .valgfri { color: rgba(255,255,255,0.5); font-weight: 400; }
</style>
</head>
<body>
  <div class="panel">
    <p class="brand">Grundejerforeningen</p>

    <?php if ($done): ?>
      <h1>Tak — vi har det hele nu</h1>
      <div class="besked ok">Dine oplysninger er gemt.</div>
      <p class="intro">Bestyrelsen har nu, hvad der skal til for at sende dig
        indkaldelse til generalforsamlingen og opkrævning af kontingent. Skal
        noget rettes senere, så skriv til bestyrelsen.</p>
      <a class="knap-link" href="index.html">Til forsiden</a>

    <?php elseif ($beboer === null): ?>
      <h1>Linket virker ikke</h1>
      <p class="intro">Linket er enten forkert eller udløbet. Links fra
        bekræftelsesmailen virker i <?php echo BEBOER_TOKEN_DAGE; ?> dage.</p>
      <p class="hjaelp">Skriv til
        <a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a>,
        så sender vi et nyt.</p>

    <?php else: ?>
      <h1>Sidste skridt</h1>
      <p class="intro">Vi mangler kun din hjemmeadresse. Det er dér,
        indkaldelsen til generalforsamlingen og opkrævningen bliver sendt hen —
        ikke til sommerhuset.</p>

      <span class="adresse">
        <small>Dit sommerhus</small>
        <?php echo $e($beboer['sommerhusadresse']); ?>
      </span>

      <?php if ($fejl !== ''): ?>
        <div class="besked fejl"><?php echo $e($fejl); ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
        <input type="hidden" name="t" value="<?php echo $e($token); ?>">

        <label for="hjem">Hjemmeadresse — vej og husnummer</label>
        <input id="hjem" name="hjemmeadresse" type="text" required maxlength="255"
               autocomplete="street-address" placeholder="fx Kærnehøjvej 96"
               value="<?php echo $e($form['hjemmeadresse']); ?>">

        <div class="to">
          <div class="lille">
            <label for="postnr">Postnr.</label>
            <input id="postnr" name="postnr" type="text" required maxlength="12"
                   autocomplete="postal-code" placeholder="5260"
                   value="<?php echo $e($form['postnr']); ?>">
          </div>
          <div class="stor">
            <label for="by">By</label>
            <input id="by" name="by" type="text" required maxlength="100"
                   autocomplete="address-level2" placeholder="Odense S"
                   value="<?php echo $e($form['by']); ?>">
          </div>
        </div>

        <label for="land">Land <span class="valgfri">— kun hvis du bor uden for Danmark</span></label>
        <input id="land" name="land" type="text" maxlength="80"
               autocomplete="country-name" value="<?php echo $e($form['land']); ?>">

        <label for="mail2">Mail nr. 2 <span class="valgfri">— valgfrit</span></label>
        <input id="mail2" name="mail2" type="email" maxlength="255"
               placeholder="fx din ægtefælles" value="<?php echo $e($form['mail2']); ?>">

        <label for="tlf2">Telefon nr. 2 <span class="valgfri">— valgfrit</span></label>
        <input id="tlf2" name="telefon2" type="tel" maxlength="32"
               value="<?php echo $e($form['telefon2']); ?>">

        <button type="submit">Gem mine oplysninger</button>
      </form>

      <p class="hjaelp">Oplysningerne bruges kun af bestyrelsen til at drive
        foreningen. De vises ikke andre steder på hjemmesiden.</p>
    <?php endif; ?>
  </div>
</body>
</html>

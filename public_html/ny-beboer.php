<?php
/**
 * Ny beboer — offentlig tilmeldingsformular.
 *
 * Formularen kan udfyldes af alle; listen kan kun ses af bestyrelsen på
 * beboere.php. Der gemmes personoplysninger om navngivne mennesker, så
 * felterne holdes på det, foreningen faktisk skal bruge.
 *
 * Beskyttelse mod misbrug:
 *   - CSRF-token, samme mekanisme som resten af siden.
 *   - Honningkrukke: et skjult felt, som kun robotter udfylder.
 *   - Karantæne: samme session må sende én gang i minuttet.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

auth_start_session();

/** En robot udfylder formularen på under et sekund; et menneske gør ikke. */
const RESIDENT_MIN_SECONDS = 3;

/** Indflytning må højst ligge et år tilbage. */
define('RESIDENT_MOVED_MIN', date('Y-m-d', strtotime('-1 year')));

$errors = [];
$done = false;
$form = ['name' => '', 'address' => '', 'email' => '', 'phone' => '', 'moved_in' => '', 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $_) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Formularen var udløbet. Prøv at sende den igen.';
    }

    // Robotter udfylder alt, også det, et menneske aldrig ser. Vi svarer
    // "tak" uden at gemme noget, så afsenderen ikke kan regne fælden ud.
    if ($_POST['website'] ?? '') {
        $done = true;
    }

    // Tidsfælde: formularen kan ikke udfyldes meningsfuldt på under tre
    // sekunder. I modsætning til honningkrukken svarer vi her med en fejl
    // og ikke et stille "tak" — en hurtig beboer må ikke kunne miste sin
    // tilmelding uden at opdage det.
    $started = (int)($_SESSION['resident_form_started'] ?? 0);
    if (!$done && $started > 0 && time() - $started < RESIDENT_MIN_SECONDS) {
        $errors[] = 'Formularen blev sendt for hurtigt. Prøv at sende den igen.';
    }

    $last = $_SESSION['resident_last_post'] ?? 0;
    if (!$done && time() - $last < 60) {
        $errors[] = 'Du har lige sendt en tilmelding. Vent et minut, før du sender igen.';
    }

    if (!$done && $errors === []) {
        // Fulde navn: mindst to led på hver mindst to bogstaver, så
        // forkortelser som "M. Bo" eller "Jens K." bliver afvist.
        $parts = preg_split('/\s+/', $form['name'], -1, PREG_SPLIT_NO_EMPTY);
        $abbreviated = false;
        foreach ($parts as $part) {
            if (mb_strlen(rtrim($part, '.')) < 2 || str_ends_with($part, '.')) {
                $abbreviated = true;
            }
        }
        if (count($parts) < 2) {
            $errors[] = 'Skriv både fornavn og efternavn.';
        } elseif ($abbreviated) {
            $errors[] = 'Skriv navnet helt ud — undlad forkortelser som "M." eller "Jens K.".';
        }

        if (mb_strlen($form['address']) < 3) {
            $errors[] = 'Skriv din adresse i Hesselbjerg Nord.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Skriv en gyldig mailadresse.';
        }

        // Danske numre er otte cifre. Mellemrum må gerne stå i feltet.
        $digits = preg_replace('/\s+/', '', $form['phone']);
        if ($form['phone'] !== '' && !preg_match('/^\d{8}$/', $digits)) {
            $errors[] = 'Telefonnummeret skal være 8 cifre.';
        }

        if ($form['moved_in'] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['moved_in'])) {
                $errors[] = 'Indflytningsdatoen ser ikke rigtig ud.';
            } elseif ($form['moved_in'] < RESIDENT_MOVED_MIN) {
                $errors[] = 'Indflytningsdatoen må højst ligge et år tilbage.';
            }
        }

        if (mb_strlen($form['note']) > 2000) {
            $errors[] = 'Bemærkningen må højst fylde 2000 tegn.';
        }
        if (empty($_POST['consent'])) {
            $errors[] = 'Du skal give samtykke, før vi må gemme dine oplysninger.';
        }
    }

    if (!$done && $errors === []) {
        $stmt = $pdo->prepare(
            'INSERT INTO residents (name, address, email, phone, moved_in, note, consent)
             VALUES (:name, :address, :email, :phone, :moved_in, :note, 1)'
        );
        $stmt->execute([
            ':name' => $form['name'],
            ':address' => $form['address'],
            ':email' => $form['email'],
            ':phone' => $form['phone'],
            ':moved_in' => $form['moved_in'] !== '' ? $form['moved_in'] : null,
            ':note' => $form['note'] !== '' ? $form['note'] : null,
        ]);

        $_SESSION['resident_last_post'] = time();
        $done = true;
        $form = array_map(static fn() => '', $form);
    }
}

// Nulstilles ved hver visning — også når siden vises igen med fejl.
$_SESSION['resident_form_started'] = time();

$csrf = auth_csrf_token();
$e = static fn(?string $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Er du flyttet til Hesselbjerg Nord? Tilmeld dig hos grundejerforeningen her.">
<!-- Siden er endnu ikke i menuen. Fjern denne linje, når den tages i brug. -->
<meta name="robots" content="noindex, nofollow">
<title>Ny beboer — Grundejerforeningen Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  html, body {
    min-height: 100%;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  body {
    background: url('kontingent.jpg') no-repeat center center fixed;
    background-size: cover;
    color: #fff;
    position: relative;
  }

  body::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 0;
  }

  nav {
    position: relative;
    z-index: 2;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 20px 15px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.45), transparent);
  }

  .nav-links {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    flex: 1;
  }

  nav a,
  .login-btn {
    color: #fff;
    text-decoration: none;
    font-size: 0.95rem;
    letter-spacing: 1px;
    padding: 8px 16px;
    border-radius: 20px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    transition: background 0.25s ease;
  }

  nav a:hover,
  nav a.active,
  .login-btn:hover {
    background: rgba(255,255,255,0.18);
  }

  .nav-account {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 10px;
  }

  .nav-user {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.85);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    white-space: nowrap;
  }

  .login-btn {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.4);
    white-space: nowrap;
    cursor: pointer;
    font-family: inherit;
  }

  .container {
    position: relative;
    z-index: 1;
    max-width: 820px;
    margin: 0 auto;
    padding: 20px 20px 70px;
  }

  h1 {
    font-size: clamp(2rem, 5vw, 3rem);
    letter-spacing: 1px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.6);
    margin-bottom: 10px;
  }

  p.lead {
    font-size: clamp(1rem, 2.2vw, 1.15rem);
    font-weight: 300;
    color: rgba(255,255,255,0.9);
    margin-bottom: 28px;
    max-width: 62ch;
  }

  .panel {
    background: rgba(10, 20, 25, 0.55);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(4px);
  }

  .panel h2 {
    font-size: 1.35rem;
    margin-bottom: 16px;
    letter-spacing: 0.5px;
  }

  /* Tjekliste over det, man skal have ved hånden. */
  .checklist {
    list-style: none;
    display: grid;
    gap: 12px;
  }

  .checklist li {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    line-height: 1.5;
  }

  .checklist li::before {
    content: "✓";
    flex: 0 0 auto;
    width: 24px;
    height: 24px;
    margin-top: 1px;
    border-radius: 50%;
    background: rgba(70, 170, 110, 0.35);
    border: 1px solid rgba(150, 230, 180, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
  }

  .checklist strong { display: block; }

  .checklist span {
    color: rgba(255,255,255,0.8);
    font-size: 0.92rem;
  }

  .steps {
    list-style: none;
    counter-reset: step;
    display: grid;
    gap: 16px;
  }

  .steps li {
    counter-increment: step;
    display: flex;
    gap: 16px;
    align-items: flex-start;
  }

  .steps li::before {
    content: counter(step);
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
  }

  .steps strong {
    display: block;
    margin-bottom: 3px;
  }

  .steps span {
    color: rgba(255,255,255,0.85);
    font-size: 0.95rem;
    line-height: 1.5;
  }

  /* Selve tilmeldingsknappen. */
  .signup {
    text-align: center;
    padding: 26px 24px;
  }

  .signup-btn {
    display: inline-block;
    background: rgba(45, 115, 190, 0.75);
    color: #fff;
    text-decoration: none;
    font-size: 1.15rem;
    font-weight: 600;
    letter-spacing: 1px;
    padding: 16px 40px;
    border-radius: 12px;
    border: 1px solid rgba(160, 205, 255, 0.7);
    box-shadow: 0 6px 18px rgba(0,0,0,0.3);
    transition: background 0.2s ease, transform 0.1s ease;
  }

  .signup-btn:hover { background: rgba(60, 140, 220, 0.9); }
  .signup-btn:active { transform: translateY(1px); }

  .signup-note {
    margin-top: 14px;
    font-size: 0.88rem;
    color: rgba(255,255,255,0.75);
  }

  .todo {
    background: rgba(190, 140, 20, 0.32);
    border: 1px solid rgba(255, 210, 120, 0.6);
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 0.92rem;
    line-height: 1.55;
    color: #ffeec9;
    margin-bottom: 24px;
  }

  .todo strong { color: #fff; }

  .todo code {
    font-family: Consolas, 'Courier New', monospace;
    background: rgba(0,0,0,0.35);
    padding: 1px 5px;
    border-radius: 4px;
    word-break: break-all;
  }

  .todo ol {
    margin: 10px 0 0 20px;
    display: grid;
    gap: 6px;
  }

  .faq dt {
    font-weight: 600;
    margin-top: 14px;
  }

  .faq dt:first-child { margin-top: 0; }

  .faq dd {
    margin: 4px 0 0;
    color: rgba(255,255,255,0.82);
    font-size: 0.95rem;
    line-height: 1.55;
  }

  /* Login-dialog — samme som på de øvrige sider. */
  .modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.5);
    z-index: 20;
  }

  .modal.open { display: flex; }

  .modal-content {
    background: rgba(14, 22, 27, 0.94);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 16px;
    padding: 24px;
    width: min(92vw, 380px);
    box-shadow: 0 20px 50px rgba(0,0,0,0.35);
  }

  .modal-content h2 {
    margin-bottom: 18px;
    text-align: center;
    font-size: 1.5rem;
  }

  .modal-content label {
    display: block;
    margin-bottom: 8px;
    font-size: 0.9rem;
    color: rgba(255,255,255,0.9);
  }

  .modal-content input {
    width: 100%;
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
  }

  .modal-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 8px;
  }

  .modal-actions button {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 10px;
    padding: 10px 16px;
    cursor: pointer;
    font: inherit;
  }

  .close-btn {
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.8);
    font-size: 1.5rem;
    cursor: pointer;
    line-height: 1;
  }

  .login-error {
    display: none;
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(180, 40, 40, 0.35);
    color: #ffdede;
    font-size: 0.9rem;
    text-align: left;
  }

  .login-error.show { display: block; }

  footer {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 10px 10px 30px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.75);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }

  @media (max-width: 520px) {
    .signup-btn {
      display: block;
      width: 100%;
      padding: 16px 20px;
    }
  }
  /* ---- Ny beboer: formular ---------------------------------------------- */
  .signup-form { display: flex; flex-direction: column; }

  .signup-form label {
    margin: 14px 0 6px;
    font-size: 0.92rem;
    color: rgba(255,255,255,0.9);
  }

  .signup-form input[type="text"],
  .signup-form input[type="email"],
  .signup-form input[type="tel"],
  .signup-form input[type="date"],
  .signup-form textarea {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
    /* 16px forhindrer, at iPhone zoomer ind, når feltet får fokus. */
    font-size: 16px;
  }

  .signup-form textarea { resize: vertical; min-height: 96px; }

  .signup-form input:focus-visible,
  .signup-form textarea:focus-visible {
    outline: 2px solid rgba(255,255,255,0.7);
    outline-offset: 1px;
  }

  .req { color: #ffc9a8; }

  .consent {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 18px;
    font-size: 0.92rem;
    line-height: 1.5;
  }

  .consent input { width: 20px; height: 20px; margin-top: 2px; flex: none; }

  .privacy {
    margin-top: 12px;
    font-size: 0.92rem;
    color: rgba(255,255,255,0.9);
    line-height: 1.55;
  }

  /* Browserens standardblå kan ikke ses på den mørke flade, så links
     arver tekstfarven og markeres med en understregning i stedet. */
  .container a,
  .privacy a,
  .consent a {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .submit-btn {
    margin-top: 20px;
    align-self: flex-start;
    padding: 13px 26px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.45);
    background: rgba(255,255,255,0.14);
    color: #fff;
    font: inherit;
    font-size: 1rem;
    cursor: pointer;
  }

  .submit-btn:hover, .submit-btn:focus-visible { background: rgba(255,255,255,0.24); }

  .panel.ok { border-color: rgba(80,190,120,0.5); background: rgba(30,90,50,0.35); }
  .panel.bad { border-color: rgba(220,90,90,0.5); background: rgba(120,35,35,0.35); }
  .panel.bad ul { margin: 8px 0 0 18px; }

  /* Honningkrukken må ikke kunne ses eller nås med tastatur. */
  .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

  @media (max-width: 560px) {
    .submit-btn { align-self: stretch; text-align: center; }
  }
</style>
<link rel="stylesheet" href="mobile-nav.css">
<script src="mobile-nav.js" defer></script>
</head>
<body>
  <nav>
    <div class="nav-links">
      <a href="index.html">Forside</a>
      <a href="omraade.html">Område</a>
      <a href="vedtaegter.html">Vedtægter</a>
      <a href="bestyrelsen.php">Bestyrelsen</a>
      <a href="kontingent.html">Kontingent</a>
      <a href="betalingsservice.html">Betalingsservice</a>
      <a href="aktiviteter.html">Aktiviteter</a>
      <a href="hjertestarter.html">Hjertestarter</a>
      <a href="ny-beboer.php" class="active" data-guest-only>Ny beboer</a>
      <a href="medlemsfotos.php" data-members-only hidden>Medlemsfotos</a>
      <a href="generalforsamling.php" data-members-only hidden>Generalforsamling</a>
      <a href="regnskab.php" data-members-only hidden>Regnskab</a>
      <a href="besked.php" data-board-only hidden>Beskeder</a>
    </div>
    <div class="nav-account">
      <span class="nav-user" data-user-name hidden></span>
      <button type="button" class="login-btn" data-open-login>Login</button>
      <a href="logout.php" class="login-btn" data-logout hidden>Log ud</a>
    </div>
  </nav>

  <div class="modal" id="loginModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2 id="loginTitle">Log ind</h2>
        <button type="button" class="close-btn" aria-label="Luk" data-close-login>&times;</button>
      </div>
      <p class="login-error" id="loginError" role="alert"></p>

      <form method="post" action="login.php">
        <input type="hidden" name="next" value="medlemsfotos.php">

        <label for="loginUser">Brugernavn</label>
        <input id="loginUser" type="text" name="username" placeholder="Brugernavn"
               autocomplete="username" required>

        <label for="loginPass">Adgangskode</label>
        <input id="loginPass" type="password" name="password" placeholder="Adgangskode"
               autocomplete="current-password" required>

        <div class="modal-actions">
          <button type="button" data-close-login>Annuller</button>
          <button type="submit">Log ind</button>
        </div>
      </form>
    </div>
  </div>

  <div class="container">
    <h1>Ny beboer</h1>
    <p class="lead">
      Velkommen til Hesselbjerg Nord. Udfyld formularen, så ved bestyrelsen,
      hvem der er flyttet ind, og du får besked om generalforsamling,
      kontingent og arrangementer.
    </p>

    <?php if ($done): ?>
      <div class="panel ok" role="status">
        <h2>Tak for din tilmelding</h2>
        <p>
          Bestyrelsen har modtaget dine oplysninger og kontakter dig, hvis der
          mangler noget. Du er velkommen til at skrive til
          <a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a>.
        </p>
      </div>
    <?php else: ?>

      <?php if ($errors !== []): ?>
        <div class="panel bad" role="alert">
          <strong>Tilmeldingen blev ikke sendt:</strong>
          <ul>
            <?php foreach ($errors as $msg): ?>
              <li><?php echo $e($msg); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="panel">
        <form method="post" class="signup-form" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">

          <!-- Honningkrukke: skjult for mennesker, udfyldes kun af robotter. -->
          <div class="hp" aria-hidden="true">
            <label for="website">Lad dette felt stå tomt</label>
            <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
          </div>

          <label for="nbName">Fulde navn <span class="req">*</span></label>
          <input id="nbName" type="text" name="name" required maxlength="255"
                 autocomplete="name" placeholder="fx Jette Hansen"
                 value="<?php echo $e($form['name']); ?>">

          <label for="nbAddress">Adresse i Hesselbjerg Nord <span class="req">*</span></label>
          <input id="nbAddress" type="text" name="address" required maxlength="255"
                 autocomplete="street-address" placeholder="fx Klitrosevej 12"
                 value="<?php echo $e($form['address']); ?>">

          <label for="nbEmail">Mail <span class="req">*</span></label>
          <input id="nbEmail" type="email" name="email" required maxlength="255"
                 autocomplete="email" value="<?php echo $e($form['email']); ?>">

          <label for="nbPhone">Telefon</label>
          <input id="nbPhone" type="tel" name="phone" maxlength="11"
                 pattern="[0-9 ]{8,11}" title="Otte cifre, fx 12 34 56 78"
                 autocomplete="tel" inputmode="tel" placeholder="fx 12 34 56 78"
                 value="<?php echo $e($form['phone']); ?>">

          <label for="nbMoved">Indflytningsdato</label>
          <input id="nbMoved" type="date" name="moved_in"
                 min="<?php echo $e(RESIDENT_MOVED_MIN); ?>"
                 value="<?php echo $e($form['moved_in']); ?>">

          <label for="nbNote">Bemærkninger</label>
          <textarea id="nbNote" name="note" rows="4" maxlength="2000"
                    placeholder="Noget bestyrelsen bør vide?"><?php echo $e($form['note']); ?></textarea>

          <label class="consent">
            <input type="checkbox" name="consent" value="1" required>
            <span>
              Jeg giver samtykke til, at grundejerforeningen gemmer mine
              oplysninger for at kunne kontakte mig om foreningens forhold.
              <span class="req">*</span>
            </span>
          </label>

          <p class="privacy">
            Oplysningerne bruges kun af bestyrelsen og videregives ikke.
            De bliver automatisk slettet ved salg og fraflytning ved at skrive til
            <a href="mailto:kasserer@hesselbjergnord.dk">kasserer@hesselbjergnord.dk</a>.
          </p>

          <button type="submit" class="submit-btn">Send tilmelding</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <footer>&copy; 2026 Grundejerforeningen Hesselbjerg Nord</footer>

  <script>
    const loginModal = document.getElementById('loginModal');
    const openLoginButtons = document.querySelectorAll('[data-open-login]');
    const closeLoginButtons = document.querySelectorAll('[data-close-login]');

    openLoginButtons.forEach((button) => {
      button.addEventListener('click', () => {
        loginModal.classList.add('open');
        loginModal.setAttribute('aria-hidden', 'false');
      });
    });

    closeLoginButtons.forEach((button) => {
      button.addEventListener('click', () => {
        loginModal.classList.remove('open');
        loginModal.setAttribute('aria-hidden', 'true');
      });
    });

    loginModal.addEventListener('click', (event) => {
      if (event.target === loginModal) {
        loginModal.classList.remove('open');
        loginModal.setAttribute('aria-hidden', 'true');
      }
    });

    const loginForm = loginModal.querySelector('form');
    const loginError = document.getElementById('loginError');

    // Fejlkoder fra login.php (bruges kun, når JavaScript er slået fra og
    // browseren er blevet sendt tilbage hertil med ?error=...).
    const LOGIN_ERRORS = {
      empty: 'Udfyld både brugernavn og adgangskode.',
      throttled: 'For mange forsøg. Vent et minut, og prøv igen.',
      auth: 'Forkert brugernavn eller adgangskode.',
      request: 'Ugyldig forespørgsel.',
    };

    function showLoginError(message) {
      loginError.textContent = message;
      loginError.classList.add('show');
    }

    // Navigationen tilpasses efter, om den besøgende er logget ind.
    fetch('session-status.php', { credentials: 'same-origin' })
      .then((response) => (response.ok ? response.json() : null))
      .then((status) => {
        if (!status || !status.loggedIn) {
          return;
        }


        // Tilmeldingen er til nye beboere — den skal ikke fylde i menuen,
        // når man allerede er logget ind.
        if (status.role === 'bestyrelse') {
          document.querySelectorAll('[data-board-only]').forEach((el) => { el.hidden = false; });
        }

        document.querySelectorAll('[data-guest-only]').forEach((el) => { el.hidden = true; });

        document.querySelectorAll('[data-members-only], [data-logout]').forEach((el) => {
          el.hidden = false;
        });

        document.querySelectorAll('[data-open-login]').forEach((el) => {
          el.hidden = true;
        });

        document.querySelectorAll('[data-user-name]').forEach((el) => {
          el.textContent = status.displayName || '';
          el.hidden = !status.displayName;
        });
      })
      .catch(() => {
        // Uden svar bliver menuen stående som "ikke logget ind".
      });

    loginForm.addEventListener('submit', (event) => {
      event.preventDefault();
      loginError.classList.remove('show');

      const submitButton = loginForm.querySelector('button[type="submit"]');
      submitButton.disabled = true;

      fetch('login.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body: new FormData(loginForm),
      })
        .then((response) => response.json())
        .then((result) => {
          if (result.ok) {
            window.location.href = result.redirect || 'medlemsfotos.php';
            return;
          }

          submitButton.disabled = false;
          showLoginError(result.message || 'Login mislykkedes.');
        })
        .catch(() => {
          submitButton.disabled = false;
          showLoginError('Der kunne ikke oprettes forbindelse. Prøv igen.');
        });
    });

    // index.html?login=1 åbner loginvinduet med det samme — det er dertil,
    // medlemssider sender besøgende uden login.
    const params = new URLSearchParams(window.location.search);

    if (params.get('login') === '1') {
      loginModal.classList.add('open');
      loginModal.setAttribute('aria-hidden', 'false');

      const errorCode = params.get('error');

      if (errorCode) {
        showLoginError(LOGIN_ERRORS[errorCode] || 'Login mislykkedes. Prøv igen.');
      }

      const next = params.get('next');
      const nextField = loginForm.querySelector('input[name="next"]');

      if (next && /^[a-z0-9._-]+\.(php|html)$/i.test(next)) {
        nextField.value = next;
      }
    }
  </script>
</body>
</html>

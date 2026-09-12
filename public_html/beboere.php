<?php
/**
 * Beboere — tilmeldinger fra "Ny beboer"-formularen.
 *
 * Kun bestyrelsen har adgang. Siden viser personoplysninger om navngivne
 * mennesker, så den må aldrig kunne ses uden login.
 *
 * GDPR: Slet rækken, når beboeren er skrevet ind i medlemslisten. Oplysninger
 * skal ikke ligge her længere, end foreningen har brug for dem.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

auth_require('index.html');

$user = auth_user();

// Medlemmer må ikke se de andres oplysninger — kun bestyrelsen.
if (($user['role'] ?? '') !== 'bestyrelse') {
    http_response_code(403);
    $denied = true;
} else {
    $denied = false;
}

$message = null;

if (!$denied && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $message = ['bad', 'Handlingen var udløbet. Prøv igen.'];
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'handled' && $id > 0) {
            $pdo->prepare(
                'UPDATE residents
                    SET status = :s, handled_by = :u, handled_at = NOW()
                  WHERE id = :id'
            )->execute([':s' => 'behandlet', ':u' => $user['id'], ':id' => $id]);
            $message = ['ok', 'Tilmeldingen er markeret som behandlet.'];
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare('DELETE FROM residents WHERE id = :id')->execute([':id' => $id]);
            $message = ['ok', 'Tilmeldingen er slettet.'];
        }
    }
}

$rows = [];
if (!$denied) {
    $rows = $pdo->query(
        "SELECT r.*, u.display_name AS handler
           FROM residents r
      LEFT JOIN users u ON u.id = r.handled_by
       ORDER BY (r.status = 'ny') DESC, r.created_at DESC"
    )->fetchAll();
}

$csrf = auth_csrf_token();
$e = static fn(?string $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<!-- Siden er endnu ikke i menuen. Fjern denne linje, når den tages i brug. -->
<meta name="robots" content="noindex, nofollow">
<title>Beboere — Hesselbjerg Nord</title>
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
  /* ---- Beboere: liste for bestyrelsen ----------------------------------- */
  .res-list { display: grid; gap: 14px; }

  .res {
    padding: 18px;
    border: 1px solid rgba(255,255,255,0.16);
    border-radius: 14px;
    background: rgba(14, 22, 27, 0.72);
  }

  .res.is-new { border-color: rgba(120, 190, 255, 0.45); }

  .res header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }

  .res h2 { font-size: 1.15rem; }

  .tag {
    flex: none;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.74rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .tag.ny { background: rgba(90,150,220,0.35); color: #d7ecff; }
  .tag.behandlet { background: rgba(255,255,255,0.12); color: rgba(255,255,255,0.75); }

  .res dl {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 6px 14px;
    font-size: 0.94rem;
  }

  .res dt { color: rgba(255,255,255,0.62); }
  .res dd.note { white-space: normal; line-height: 1.55; }

  .res-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }

  .res-actions button {
    padding: 10px 16px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.35);
    background: rgba(255,255,255,0.12);
    color: #fff;
    font: inherit;
    cursor: pointer;
  }

  .res-actions button.danger {
    border-color: rgba(220,100,100,0.5);
    background: rgba(130,40,40,0.35);
  }

  .note-gdpr { font-size: 0.9rem; color: rgba(255,255,255,0.8); }

  .panel.ok { border-color: rgba(80,190,120,0.5); background: rgba(30,90,50,0.35); }
  .panel.bad { border-color: rgba(220,90,90,0.5); background: rgba(120,35,35,0.35); }

  /* Telefon: etiket over værdi, så lange mails ikke bliver klemt. */
  @media (max-width: 560px) {
    .res dl { grid-template-columns: 1fr; gap: 2px; }
    .res dt { margin-top: 8px; font-size: 0.8rem; }
    .res-actions form, .res-actions button { width: 100%; }
  }
</style>
<link rel="stylesheet" href="mobile-nav.css">
<script src="mobile-nav.js" defer></script>
</head>
<body>
  <nav>
    <div class="nav-links">
      <a href="omraade.html">Område</a>
      <a href="vedtaegter.html">Vedtægter</a>
      <a href="bestyrelsen.php">Bestyrelsen</a>
      <a href="kontingent.html">Kontingent</a>
      <a href="betalingsservice.html">Betalingsservice</a>
      <a href="aktiviteter.html">Aktiviteter</a>
      <a href="hjertestarter.html">Hjertestarter</a>
      <a href="ny-beboer.php">Ny beboer</a>
      <a href="medlemsfotos.php" data-members-only hidden>Medlemsfotos</a>
      <a href="generalforsamling.php" data-members-only hidden>Generalforsamling</a>
      <a href="regnskab.php" data-members-only hidden>Regnskab</a>
      <a href="beboere.php" data-board-only hidden>Beboere</a>
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

  <div class="container">
    <h1>Beboere</h1>

    <?php if ($denied): ?>
      <div class="panel bad">
        <h2>Ingen adgang</h2>
        <p>Kun bestyrelsen kan se tilmeldingerne fra nye beboere.</p>
      </div>
    <?php else: ?>
      <p class="lead">
        Tilmeldinger fra <a href="ny-beboer.php">Ny beboer</a>. Nye står øverst.
      </p>

      <?php if ($message !== null): ?>
        <div class="panel <?php echo $e($message[0]); ?>" role="status"><?php echo $e($message[1]); ?></div>
      <?php endif; ?>

      <div class="panel note-gdpr">
        <strong>Husk:</strong> slet en tilmelding, når beboeren er skrevet ind i
        medlemslisten. Oplysningerne skal ikke ligge her længere end nødvendigt.
      </div>

      <?php if ($rows === []): ?>
        <div class="panel"><p>Der er endnu ingen tilmeldinger.</p></div>
      <?php else: ?>
        <div class="res-list">
          <?php foreach ($rows as $r): ?>
            <article class="res<?php echo $r['status'] === 'ny' ? ' is-new' : ''; ?>">
              <header>
                <h2><?php echo $e($r['name']); ?></h2>
                <span class="tag <?php echo $e($r['status']); ?>">
                  <?php echo $r['status'] === 'ny' ? 'Ny' : 'Behandlet'; ?>
                </span>
              </header>

              <dl>
                <dt>Adresse</dt><dd><?php echo $e($r['address']); ?></dd>
                <dt>Mail</dt>
                <dd><a href="mailto:<?php echo $e($r['email']); ?>"><?php echo $e($r['email']); ?></a></dd>
                <dt>Telefon</dt>
                <dd>
                  <?php if ($r['phone'] !== ''): ?>
                    <a href="tel:<?php echo $e(preg_replace('/\s+/', '', $r['phone'])); ?>"><?php echo $e($r['phone']); ?></a>
                  <?php else: ?>&mdash;<?php endif; ?>
                </dd>
                <dt>Indflyttet</dt><dd><?php echo $r['moved_in'] ? $e($r['moved_in']) : '&mdash;'; ?></dd>
                <dt>Modtaget</dt><dd><?php echo $e($r['created_at']); ?></dd>
                <?php if (!empty($r['note'])): ?>
                  <dt>Bemærkning</dt><dd class="note"><?php echo nl2br($e($r['note'])); ?></dd>
                <?php endif; ?>
                <?php if ($r['status'] === 'behandlet' && $r['handler']): ?>
                  <dt>Behandlet af</dt><dd><?php echo $e($r['handler']); ?> <?php echo $e($r['handled_at']); ?></dd>
                <?php endif; ?>
              </dl>

              <div class="res-actions">
                <?php if ($r['status'] === 'ny'): ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="handled">
                    <button type="submit">Markér som behandlet</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Slet tilmeldingen fra <?php echo $e($r['name']); ?>? Det kan ikke fortrydes.');">
                  <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="delete">
                  <button type="submit" class="danger">Slet</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  </div>

  <footer>&copy; 2026 Hesselbjerg Nord</footer>

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

        if (status.role === 'bestyrelse') {
          document.querySelectorAll('[data-board-only]').forEach((el) => { el.hidden = false; });
        }

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

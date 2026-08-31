<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Bestyrelsen i Hesselbjerg Nord — hvem sidder i bestyrelsen, og hvordan du kommer i kontakt.">
<title>Bestyrelsen — Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  html, body {
    min-height: 100%;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  body {
    background: url('bestyrelsen.jpg') no-repeat center center fixed;
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
    max-width: 1000px;
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

  /* Bestyrelsen. Kort frem for en <table>, så billede og kontaktoplysninger
     også kan læses på en telefon uden vandret scroll. */
  .board {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
  }

  .member {
    display: flex;
    gap: 18px;
    align-items: flex-start;
    background: rgba(10, 20, 25, 0.55);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.18);
    backdrop-filter: blur(4px);
  }

  .member-photo {
    flex: 0 0 auto;
    width: 88px;
    height: 88px;
    border-radius: 50%;
    overflow: hidden;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.32);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .member-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .member-photo .initials {
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.6);
  }

  .member-body {
    flex: 1;
    min-width: 0;
  }

  .member-name {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 2px;
  }

  .member-role {
    font-size: 0.8rem;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(150, 210, 255, 0.95);
    margin-bottom: 14px;
  }

  .member-contact {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 7px 12px;
    margin: 0;
    font-size: 0.93rem;
  }

  .member-contact dt {
    color: rgba(255,255,255,0.55);
    font-size: 0.82rem;
    padding-top: 1px;
  }

  .member-contact dd {
    margin: 0;
    min-width: 0;
  }

  .member-contact a {
    color: #cfe9ff;
    text-decoration: none;
    word-break: break-word;
  }

  .member-contact a:hover { text-decoration: underline; }

  .member-contact address {
    font-style: normal;
    line-height: 1.45;
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

  @media (max-width: 400px) {
    .member {
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    .member-contact {
      grid-template-columns: 1fr;
      gap: 2px;
      text-align: center;
    }

    .member-contact dt { margin-top: 8px; }
  }
</style>
</head>
<body>
  <nav>
    <div class="nav-links">
      <a href="omraade.html">Område</a>
      <a href="vedtaegter.html">Vedtægter</a>
      <a href="bestyrelsen.html" class="active">Bestyrelsen</a>
      <a href="kontingent.html">Kontingent</a>
      <a href="aktiviteter.html">Aktiviteter</a>
      <a href="hjertestarter.html">Hjertestarter</a>
      <a href="medlemsfotos.php" data-members-only hidden>Medlemsfotos</a>
      <a href="generalforsamling.php" data-members-only hidden>Generalforsamling</a>
      <a href="regnskab.php" data-members-only hidden>Regnskab</a>
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
    <h1>Bestyrelsen</h1>
    <p class="lead">
      Bestyrelsen vælges på generalforsamlingen og varetager foreningens daglige
      drift. Du er altid velkommen til at kontakte os.
    </p>

    <!-- =====================================================================
         BESTYRELSESMEDLEMMER

         Telefon:  <dd><a href="tel:+4512345678">12 34 56 78</a></dd>
         Mail:     <dd><a href="mailto:navn@dk">navn@dk</a></dd>
         Adresse:  <dd><address>Vejnavn 1<br>5932 Humble</address></dd>

         Billede:  læg en kvadratisk fil (ca. 300x300 px) i mappen bestyrelse/
                   og erstat <span class="initials">..</span> med
                   <img src="bestyrelse/filnavn.jpg" alt="Navn">

         Flere eller færre medlemmer: kopiér eller slet et helt
         <article class="member">-afsnit.
         ===================================================================== -->
    <div class="board">

      <article class="member">
        <div class="member-photo">
          <span class="initials">UG</span>
        </div>
        <div class="member-body">
          <p class="member-name">Uffe Gangelhof</p>
          <p class="member-role">Formand</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:formand@hesselbjergnord.dk">formand@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

      <article class="member">
        <div class="member-photo">
          <span class="initials">MD</span>
        </div>
        <div class="member-body">
          <p class="member-name">Morten Dupont</p>
          <p class="member-role">Næstformand</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:naestformand@hesselbjergnord.dk">naestformand@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

      <article class="member">
        <div class="member-photo">
          <span class="initials">JH</span>
        </div>
        <div class="member-body">
          <p class="member-name">Jette Hansen</p>
          <p class="member-role">Kasserer</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:kasser@hesselbjergnord.dk">kasser@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

      <article class="member">
        <div class="member-photo">
          <span class="initials">LK</span>
        </div>
        <div class="member-body">
          <p class="member-name">Lars Klausen</p>
          <p class="member-role">Bestyrelseskoordinator</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

      <article class="member">
        <div class="member-photo">
          <span class="initials">TS</span>
        </div>
        <div class="member-body">
          <p class="member-name">Tea Sose</p>
          <p class="member-role">Eventkoordinator</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

      <article class="member">
        <div class="member-photo">
          <span class="initials">MBK</span>
        </div>
        <div class="member-body">
          <p class="member-name">Morten Bo Kristensen</p>
          <p class="member-role">Vejudvalg</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

      <article class="member">
        <div class="member-photo">
          <span class="initials">JBH</span>
        </div>
        <div class="member-body">
          <p class="member-name">Jesper Beck Holm</p>
          <p class="member-role">Vejudvalg</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

      <article class="member">
        <div class="member-photo">
          <span class="initials">TH</span>
        </div>
        <div class="member-body">
          <p class="member-name">Torben Holstebro</p>
          <p class="member-role">Vejudvalg</p>
          <dl class="member-contact">
            <dt>Tlf.</dt>
            <dd>&mdash;</dd>

            <dt>Mail</dt>
            <dd><a href="mailto:bestyrelsen@hesselbjergnord.dk">bestyrelsen@hesselbjergnord.dk</a></dd>

            <dt>Adresse</dt>
            <dd>&mdash;</dd>
          </dl>
        </div>
      </article>

    </div>
    <!-- =================== SLUT PÅ BESTYRELSESMEDLEMMER =================== -->
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

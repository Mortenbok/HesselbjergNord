<?php
/**
 * Vedtægter — foreningens vedtægter bag login.
 *
 * Siden viser hele teksten, så den kan læses og søges i på en telefon, og
 * har den underskrevne PDF vedhæftet. PDF'en ligger i dokumenter/, som er
 * spærret af .htaccess, og serveres kun herfra med ?fil=pdf — altså kun til
 * brugere, der er logget ind.
 */

require_once __DIR__ . '/includes/auth.php';

/** Den underskrevne udgave, som ligger vedhæftet siden. */
const VEDTAEGTER_PDF = 'vedtaegter-2017-02-19.pdf';
const VEDTAEGTER_PDF_NAVN = 'Vedtaegter Hesselbjerg Nord 19-02-2017.pdf';

// ---------------------------------------------------------------------------
// ?fil=pdf — selve filen. Skal ligge før al HTML-udskrift.
// ---------------------------------------------------------------------------
if (($_GET['fil'] ?? '') === 'pdf') {
    if (!auth_check()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Login kræves.');
    }

    $path = __DIR__ . '/dokumenter/' . VEDTAEGTER_PDF;

    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Filen blev ikke fundet.');
    }

    $download = filter_input(INPUT_GET, 'download', FILTER_VALIDATE_INT) === 1;

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
        . '; filename="' . VEDTAEGTER_PDF_NAVN . '"');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($path);
    exit;
}

// ---------------------------------------------------------------------------
// Selve siden
// ---------------------------------------------------------------------------
auth_require('index.html');

$user = auth_user();

/** Kort for htmlspecialchars med sidens faste indstillinger. */
function vedtaegter_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$e = 'vedtaegter_e';

$pdfPath = __DIR__ . '/dokumenter/' . VEDTAEGTER_PDF;
$pdfFindes = is_file($pdfPath);
$pdfStoerrelse = $pdfFindes
    ? number_format(filesize($pdfPath) / 1024, 0, ',', '.') . ' KB'
    : '';

/**
 * Vejene i § 1 — sat som liste, så de brydes pænt på en telefon.
 *
 * Teksten står præcis som i de vedtagne vedtægter. Bemærk, at "Hyldeskovvænget"
 * er en slåfejl i 2017-udgaven: vejen hedder Hylleskovvænget. Teksten rettes
 * IKKE her — en vedtægtsændring hører til på en generalforsamling (§ 9).
 * I stedet står der en fodnote under listen.
 */
$veje = [
    'Ahornvænget', 'Askevænget', 'Birkevænget', 'Blåbærvænget', 'Duevænget',
    'Elmevænget', 'Enebærvænget', 'Fyrrevænget', 'Granvænget', 'Gulspurvevænget',
    'Hasselvænget', 'Hyldeskovvænget', 'Klitrosevej', 'Lindevænget',
    'Musvitvænget', 'Rosenvænget', 'Rønnevænget', 'Syrenvænget', 'Tjørnevænget',
    'Ørnevænget',
];

/** Dagsordenen i § 7. */
$dagsorden = [
    'Beretning om foreningens virksomhed i det forløbne år',
    'Fremlæggelse af regnskab til godkendelse',
    'Valg af medlemmer til bestyrelsen',
    'Valg af suppleanter til bestyrelsen',
    'Valg af revisor',
    'Valg af revisorsuppleant',
    'Indkomne forslag',
    'Eventuelt',
];

/** Underskriverne på den vedtagne udgave fra 2017. */
$underskrivere = [
    ['Torben Tranberg Jensen', 'dirigent'],
    ['Poul Erik Larsen', 'formand'],
    ['Allan Lund Petersen', 'næstformand'],
    ['Solveig Pedersen', 'kasserer'],
    ['Flemming Thrane', 'sekretær'],
    ['Lars Peder Barnebjerg', 'bestyrelsesmedlem'],
    ['Jytte Bøje Hansen', 'bestyrelsesmedlem'],
    ['Brian Ville Osiki', 'bestyrelsesmedlem'],
    ['Hans Christensen', 'bestyrelsesmedlem'],
];
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Vedtægter — Grundejerforeningen Hesselbjerg Nord</title>
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
    position: relative;
    color: #fff;
  }
  body::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
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
  nav a, .login-btn {
    color: #fff;
    text-decoration: none;
    font-size: 0.95rem;
    letter-spacing: 1px;
    padding: 8px 16px;
    border-radius: 20px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    transition: background 0.25s ease;
  }
  nav a:hover, .login-btn:hover, nav a.active {
    background: rgba(255,255,255,0.18);
  }
  .nav-account {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 10px;
    white-space: nowrap;
  }
  .nav-user {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.85);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }
  .login-btn {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.4);
    white-space: nowrap;
  }
  .container {
    position: relative;
    z-index: 1;
    max-width: 1000px;
    margin: 0 auto;
    padding: 30px 20px 80px;
  }
  .panel {
    background: rgba(10, 20, 25, 0.42);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(4px);
  }
  h1 {
    font-size: clamp(2rem, 4vw, 2.8rem);
    margin-bottom: 6px;
  }
  .vedtaget {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.62);
    margin-bottom: 14px;
  }
  .lead {
    font-weight: 300;
    color: rgba(255,255,255,0.9);
    max-width: 62ch;
    line-height: 1.55;
  }

  /* Den vedhæftede PDF — samme udseende som dokumenterne i arkivet. */
  .vedhaeftet {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 24px;
    padding: 14px 16px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 12px;
  }
  .doc-kind {
    flex: 0 0 auto;
    width: 52px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.22);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1px;
  }
  .doc-body { flex: 1; min-width: 0; }
  .doc-title { font-size: 1.02rem; font-weight: 600; margin-bottom: 3px; }
  .doc-title a { color: #fff; text-decoration: none; }
  .doc-title a:hover { text-decoration: underline; }
  .doc-meta { font-size: 0.85rem; color: rgba(255,255,255,0.62); }
  .doc-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 0 0 auto;
  }
  .doc-actions a {
    color: #cfe9ff;
    text-decoration: none;
    font-size: 0.9rem;
    white-space: nowrap;
  }
  .doc-actions a:hover { text-decoration: underline; }
  .mangler { margin-top: 24px; color: #ffd3d3; }

  /* Selve vedtægterne. */
  .vedtaegter { margin-top: 34px; }
  .vedtaegter section {
    padding-top: 22px;
    margin-top: 22px;
    border-top: 1px solid rgba(255,255,255,0.16);
  }
  .vedtaegter section:first-child {
    padding-top: 0;
    margin-top: 0;
    border-top: none;
  }
  .vedtaegter h2 {
    font-size: 1.15rem;
    letter-spacing: 1px;
    color: rgba(150, 210, 255, 0.95);
    margin-bottom: 10px;
  }
  .vedtaegter p {
    line-height: 1.65;
    font-weight: 300;
    max-width: 68ch;
    margin-bottom: 10px;
  }
  .vedtaegter p:last-child { margin-bottom: 0; }
  .vedtaegter ol {
    margin: 4px 0 10px 1.4em;
    line-height: 1.6;
    font-weight: 300;
  }
  .vedtaegter li { margin-bottom: 4px; }
  .fodnote {
    margin-top: 12px;
    padding-left: 12px;
    border-left: 2px solid rgba(150, 210, 255, 0.45);
    font-size: 0.85rem;
    font-weight: 300;
    line-height: 1.55;
    color: rgba(255,255,255,0.62);
    max-width: 64ch;
  }
  .fodnote a { color: rgba(170, 215, 255, 0.9); }
  .veje {
    list-style: none;
    margin: 4px 0 10px;
    font-weight: 300;
    line-height: 1.6;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
    gap: 2px 14px;
  }
  .veje li::before {
    content: "\2022";
    color: rgba(150, 210, 255, 0.7);
    margin-right: 8px;
  }

  /* Underskrifterne under § 10. */
  .underskrifter {
    list-style: none;
    margin: 14px 0 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 14px;
  }
  .underskrifter li { font-weight: 300; }
  .underskrifter strong { display: block; font-weight: 600; }
  .underskrifter span { font-size: 0.85rem; color: rgba(255,255,255,0.62); }

  footer {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 10px 10px 30px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.8);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }

  @media (max-width: 560px) {
    .vedhaeftet { flex-wrap: wrap; }
    .doc-actions { width: 100%; justify-content: flex-end; }
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
      <a href="vedtaegter.php" class="active">Vedtægter</a>
      <a href="medlemsfotos.php">Medlemsfotos</a>
      <a href="generalforsamling.php">Generalforsamling</a>
      <?php if (($user['role'] ?? '') === 'bestyrelse'): ?>
        <a href="besked.php">Beskeder</a>
        <a href="adgangskort.php">Adgangskort</a>
        <a href="beboerkartotek.php">Beboerkartotek</a>
      <?php endif; ?>
    </div>
    <div class="nav-account">
      <span class="nav-user">Logget ind som <?php echo $e($user['display_name']); ?></span>
      <a href="logout.php" class="login-btn">Log ud</a>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Vedtægter</h1>
      <p class="vedtaget">Vedtaget på to ekstraordinære generalforsamlinger
        den 29. januar 2017 og den 19. februar 2017.</p>
      <p class="lead">Foreningens gældende vedtægter. Hele teksten står nedenfor,
        og den underskrevne udgave er vedhæftet som PDF.</p>

      <?php if ($pdfFindes): ?>
        <div class="vedhaeftet">
          <div class="doc-kind">PDF</div>
          <div class="doc-body">
            <p class="doc-title">
              <a href="vedtaegter.php?fil=pdf" target="_blank" rel="noopener">
                Vedtægter — underskrevet udgave, 19. februar 2017
              </a>
            </p>
            <p class="doc-meta"><?php echo $e($pdfStoerrelse); ?> &middot; 3 sider</p>
          </div>
          <div class="doc-actions">
            <a href="vedtaegter.php?fil=pdf" target="_blank" rel="noopener">Åbn</a>
            <a href="vedtaegter.php?fil=pdf&amp;download=1">Hent</a>
          </div>
        </div>
      <?php else: ?>
        <p class="mangler">PDF-udgaven ligger ikke på serveren lige nu.
          Teksten nedenfor er den gældende.</p>
      <?php endif; ?>

      <div class="vedtaegter">
        <section>
          <h2>§ 1</h2>
          <p>Foreningens formål er at varetage grundejernes interesser i det
            sommerhusområde, der omfatter vejene:</p>
          <ul class="veje">
            <?php foreach ($veje as $vej): ?>
              <li><?php echo $e($vej); ?></li>
            <?php endforeach; ?>
          </ul>
          <p class="fodnote">Teksten står, som den blev vedtaget. Vejen, der her
            hedder <em>Hyldeskovvænget</em>, hedder i dag <em>Hylleskovvænget</em>
            — en slåfejl i 2017-udgaven, som kun en generalforsamling kan rette
            (§ 9). Se vejene på kortet under <a href="omraade.html">Området</a>.</p>
        </section>

        <section>
          <h2>§ 2</h2>
          <p>Medlem af foreningen er enhver ejer af fast ejendom i det i § 1
            nævnte område, og der er pligt for grundejerne til at være medlem.
            Er et medlem ejer af flere matr. nr. betales kontingent af hver af
            disse.</p>
        </section>

        <section>
          <h2>§ 3</h2>
          <p>Såfremt et medlem måtte afhænde sin ejendom i sommerhusområdet
            ophører medlemskab af foreningen, men han er pligtig til at oplyse
            foreningen om navn og adresse på efterfølgeren, senest 14 dage efter
            ejerskifte. Intet medlem har ved ophør af medlemskabet krav på andel
            i foreningen.</p>
        </section>

        <section>
          <h2>§ 4</h2>
          <p>Foreningen ledes af en bestyrelse på 8 personer valgt blandt
            medlemmerne. Der afgår hvert år 4 medlemmer af bestyrelsen.
            Generalforsamlingen vælger 2 suppleanter til bestyrelsen.</p>
        </section>

        <section>
          <h2>§ 5</h2>
          <p>Bestyrelsen er beslutningsdygtig, når mindst halvdelen af dens
            medlemmer deriblandt er til stede. Bestyrelsen vælger blandt sine
            medlemmer en formand, en kasserer og en sekretær. Formanden
            sammenkalder til bestyrelsesmøde og leder disse. Bestyrelsen fører
            en forhandlingsprotokol og en regnskabsprotokol.</p>
        </section>

        <section>
          <h2>§ 6</h2>
          <p>Den ordinære generalforsamling afholdes hvert år indenfor perioden
            1/7-1/10. Overordentlig generalforsamling afholdes efter bestyrelsens
            ønske eller efter begæring af mindst 1/10 af de stemmeberettigede
            medlemmer. Enhver generalforsamling indkaldes med mindst 14 dages
            varsel ved brev til hvert enkelt medlem. Forslag der ønskes under
            afstemning må være formanden i hænde senest hvert år 15/6.
            Generalforsamlingen vælger selv sin dirigent. Generalforsamlingen
            afholdes på det af bestyrelsen bestemte sted.</p>
        </section>

        <section>
          <h2>§ 7</h2>
          <p>På den ordinære generalforsamling behandles følgende:</p>
          <ol>
            <?php foreach ($dagsorden as $punkt): ?>
              <li><?php echo $e($punkt); ?></li>
            <?php endforeach; ?>
          </ol>
        </section>

        <section>
          <h2>§ 8</h2>
          <p>På generalforsamlingen kan ethvert medlem stemme personligt. Ethvert
            spørgsmål afgøres ved simpel stemmeflerhed, hvor ikke nærværende
            vedtægter gør undtagelse herfra. Hvert medlem har en stemme for hver
            grund han ejer.</p>
        </section>

        <section>
          <h2>§ 9</h2>
          <p>Forandring af nærværende vedtægter og beslutning om foreningens
            opløsning kan kun vedtages på en generalforsamling, hvor mindst 2/3
            af foreningens samlede antal stemmer er til stede og mindst ¾ af de
            fremmødte stemmer for forslaget. Er 2/3 af antallet af stemmer ikke
            til stede, men forslaget i øvrigt vedtaget af ¾ af de mødte antal
            stemmer, indkalder bestyrelsen til en overordentlig
            generalforsamling, der skal afholdes senest 1 måned derefter.
            Forslaget kan på denne generalforsamling vedtages med ¾ af det
            repræsenterede antal stemmer.</p>
        </section>

        <section>
          <h2>§ 10</h2>
          <p>Foreningens regnskabsår er kalenderåret.</p>
          <p class="doc-meta" style="margin-top:18px;">Underskrevet den 19. februar 2017 af:</p>
          <ul class="underskrifter">
            <?php foreach ($underskrivere as $underskriver): ?>
              <li>
                <strong><?php echo $e($underskriver[0]); ?></strong>
                <span><?php echo $e($underskriver[1]); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      </div>
    </div>
  </div>

  <footer>&copy; <?php echo date('Y'); ?> Grundejerforeningen Hesselbjerg Nord</footer>
</body>
</html>

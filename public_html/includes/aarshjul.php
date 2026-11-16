<?php
/**
 * Årshjulet — foreningens faste år, synligt for alle.
 *
 * Siden er offentlig, men bestyrelsen kan rette i den direkte i browseren,
 * når de er logget ind. Derfor ligger indholdet i databasen og ikke i HTML:
 * så skal der ikke FTP eller git til for at flytte en dato.
 *
 * To datoer regnes ud af sig selv hvert år og skal aldrig rettes:
 *   - generalforsamlingen, sidste lørdag i august
 *   - arbejdsdagen, lørdagen inden palmesøndag (følger påsken)
 * En post får dem ved at sætte feltet 'beregning'.
 */

/** Kategorierne i årshjulet. Nøglen er værdien i databasen. */
const AARSHJUL_KATEGORIER = [
    'bestyrelsen' => ['navn' => 'Bestyrelsen', 'farve' => '#8ad2ff'],
    'generalforsamling' => ['navn' => 'Generalforsamling', 'farve' => '#ffd24a'],
    'socialt' => ['navn' => 'Socialt', 'farve' => '#8ae2a8'],
    'andet' => ['navn' => 'Andet', 'farve' => '#c9a9ff'],
];

/** Måneder, som de skrives på siden. */
const AARSHJUL_MAANEDER = [
    1 => 'januar', 2 => 'februar', 3 => 'marts', 4 => 'april',
    5 => 'maj', 6 => 'juni', 7 => 'juli', 8 => 'august',
    9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
];

/** Korte månedsnavne til selve hjulet. */
const AARSHJUL_MAANEDER_KORT = [
    1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MAJ', 6 => 'JUN',
    7 => 'JUL', 8 => 'AUG', 9 => 'SEP', 10 => 'OKT', 11 => 'NOV', 12 => 'DEC',
];

/** De datoer, siden selv kan regne ud. */
const AARSHJUL_BEREGNINGER = [
    '' => 'Ingen — datoen skrives i hånden',
    'sidste_loerdag_august' => 'Sidste lørdag i august',
    'loerdag_foer_palmesoendag' => 'Lørdagen inden palmesøndag (følger påsken)',
];

/** Kun bestyrelsen kan rette i årshjulet. */
function aarshjul_can_manage(?array $user): bool
{
    return is_array($user) && ($user['role'] ?? '') === 'bestyrelse';
}

/** Kort for htmlspecialchars med sidens faste indstillinger. */
function aarshjul_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Påskedag som tidsstempel.
 *
 * Den anonyme gregorianske algoritme. Regnes her i stedet for med
 * easter_date(), fordi calendar-udvidelsen ikke er slået til alle steder.
 */
function aarshjul_paaskedag(int $aar): int
{
    $a = $aar % 19;
    $b = intdiv($aar, 100);
    $c = $aar % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $maaned = intdiv($h + $l - 7 * $m + 114, 31);
    $dag = (($h + $l - 7 * $m + 114) % 31) + 1;

    return mktime(12, 0, 0, $maaned, $dag, $aar);
}

/**
 * Den udregnede dato for en post i et givet år, fx "lørdag den 29. august".
 * Tom streng, hvis posten ikke har en beregning.
 */
function aarshjul_beregnet_dato(string $beregning, int $aar): string
{
    if ($beregning === 'sidste_loerdag_august') {
        $ts = strtotime('last saturday of august ' . $aar);
    } elseif ($beregning === 'loerdag_foer_palmesoendag') {
        // Palmesøndag er ugen før påskedag; lørdagen inden er dagen før det.
        $ts = strtotime('-8 days', aarshjul_paaskedag($aar));
    } else {
        return '';
    }

    if ($ts === false) {
        return '';
    }

    return 'lørdag den ' . (int)date('j', $ts) . '. ' . AARSHJUL_MAANEDER[(int)date('n', $ts)];
}

/** Måneden, en beregnet dato falder i — så hjulet viser den rigtige plads. */
function aarshjul_beregnet_maaned(string $beregning, int $aar): ?int
{
    if ($beregning === 'sidste_loerdag_august') {
        return 8;
    }

    if ($beregning === 'loerdag_foer_palmesoendag') {
        return (int)date('n', strtotime('-8 days', aarshjul_paaskedag($aar)));
    }

    return null;
}

/** Alle poster, ordnet efter kategori og sortering. */
function aarshjul_list(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, kategori, titel, tidspunkt, maaned, beregning, beskrivelse,
                sortering, updated_at, updated_by
           FROM aarshjul
          ORDER BY sortering ASC, id ASC'
    )->fetchAll();

    $poster = [];

    foreach (array_keys(AARSHJUL_KATEGORIER) as $kat) {
        $poster[$kat] = [];
    }

    foreach ($rows as $row) {
        if (!isset(AARSHJUL_KATEGORIER[$row['kategori']])) {
            continue;
        }

        $poster[$row['kategori']][] = $row;
    }

    return $poster;
}

/**
 * Håndterer POST fra redigeringen.
 *
 * Returnerer ['error' => string, 'success' => string].
 */
function aarshjul_handle_post(PDO $pdo, ?array $user): array
{
    $result = ['error' => '', 'success' => ''];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $result;
    }

    if (!aarshjul_can_manage($user)) {
        $result['error'] = 'Kun bestyrelsen kan rette i årshjulet.';
        return $result;
    }

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $result['error'] = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
        return $result;
    }

    $handling = (string)($_POST['handling'] ?? '');

    if ($handling === 'slet') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            return ['error' => 'Posten blev ikke fundet.', 'success' => ''];
        }

        $pdo->prepare('DELETE FROM aarshjul WHERE id = :id')->execute([':id' => $id]);

        return ['error' => '', 'success' => 'Posten er fjernet fra årshjulet.'];
    }

    if ($handling !== 'gem' && $handling !== 'opret') {
        return ['error' => 'Ukendt handling.', 'success' => ''];
    }

    $kategori = (string)($_POST['kategori'] ?? '');

    if (!isset(AARSHJUL_KATEGORIER[$kategori])) {
        return ['error' => 'Vælg en kategori.', 'success' => ''];
    }

    $titel = trim((string)($_POST['titel'] ?? ''));

    if ($titel === '') {
        return ['error' => 'Posten skal have en overskrift.', 'success' => ''];
    }

    $beregning = (string)($_POST['beregning'] ?? '');

    if (!isset(AARSHJUL_BEREGNINGER[$beregning])) {
        $beregning = '';
    }

    $maaned = (int)($_POST['maaned'] ?? 0);
    $maaned = ($maaned >= 1 && $maaned <= 12) ? $maaned : null;

    $felter = [
        ':kategori' => $kategori,
        ':titel' => mb_substr($titel, 0, 200),
        ':tidspunkt' => mb_substr(trim((string)($_POST['tidspunkt'] ?? '')), 0, 120),
        ':maaned' => $maaned,
        ':beregning' => $beregning,
        ':beskrivelse' => trim((string)($_POST['beskrivelse'] ?? '')),
        ':sortering' => (int)($_POST['sortering'] ?? 0),
        ':updated_by' => $user['display_name'] !== '' ? $user['display_name'] : 'Bestyrelsen',
    ];

    if ($handling === 'opret') {
        $stmt = $pdo->prepare(
            'INSERT INTO aarshjul
                (kategori, titel, tidspunkt, maaned, beregning, beskrivelse, sortering,
                 updated_at, updated_by)
             VALUES
                (:kategori, :titel, :tidspunkt, :maaned, :beregning, :beskrivelse, :sortering,
                 NOW(), :updated_by)'
        );
        $stmt->execute($felter);

        return ['error' => '', 'success' => 'Posten er føjet til årshjulet.'];
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        return ['error' => 'Posten blev ikke fundet.', 'success' => ''];
    }

    $stmt = $pdo->prepare(
        'UPDATE aarshjul
            SET kategori = :kategori, titel = :titel, tidspunkt = :tidspunkt,
                maaned = :maaned, beregning = :beregning, beskrivelse = :beskrivelse,
                sortering = :sortering, updated_at = NOW(), updated_by = :updated_by
          WHERE id = :id'
    );
    $stmt->execute($felter + [':id' => $id]);

    return ['error' => '', 'success' => 'Ændringen er gemt.'];
}

/**
 * Tegner selve årshjulet som SVG.
 *
 * Serveren tegner det færdigt, så det også virker uden JavaScript og kan
 * printes. Måneder med noget i følger kategoriens farve.
 */
function aarshjul_svg(array $poster, int $aar): string
{
    $e = 'aarshjul_e';

    // Hvilke kategorier har noget i hver måned?
    $maaneder = [];

    foreach ($poster as $kat => $liste) {
        foreach ($liste as $post) {
            $m = $post['beregning'] !== ''
                ? aarshjul_beregnet_maaned($post['beregning'], $aar)
                : ($post['maaned'] !== null ? (int)$post['maaned'] : null);

            if ($m !== null) {
                $maaneder[$m][$kat] = true;
            }
        }
    }

    $cx = 200.0;
    $cy = 200.0;
    $ydre = 170.0;
    $indre = 108.0;
    $mellemrum = 1.2;   // graders luft mellem felterne

    $svg = '<svg viewBox="0 0 400 400" class="hjul" role="img" '
         . 'aria-label="Årshjul — måneder med aktivitet er fremhævet">';

    for ($m = 1; $m <= 12; $m++) {
        // Januar starter øverst; derfor -90 grader.
        $start = -90 + ($m - 1) * 30 + $mellemrum;
        $slut = -90 + $m * 30 - $mellemrum;

        $s = deg2rad($start);
        $t = deg2rad($slut);

        $x1 = $cx + $ydre * cos($s);  $y1 = $cy + $ydre * sin($s);
        $x2 = $cx + $ydre * cos($t);  $y2 = $cy + $ydre * sin($t);
        $x3 = $cx + $indre * cos($t); $y3 = $cy + $indre * sin($t);
        $x4 = $cx + $indre * cos($s); $y4 = $cy + $indre * sin($s);

        $kategorier = array_keys($maaneder[$m] ?? []);
        $aktiv = $kategorier !== [];

        $fyld = $aktiv
            ? AARSHJUL_KATEGORIER[$kategorier[0]]['farve']
            : '#ffffff';
        $opacitet = $aktiv ? '0.30' : '0.07';

        $d = sprintf(
            'M %.2f %.2f A %.2f %.2f 0 0 1 %.2f %.2f L %.2f %.2f A %.2f %.2f 0 0 0 %.2f %.2f Z',
            $x1, $y1, $ydre, $ydre, $x2, $y2, $x3, $y3, $indre, $indre, $x4, $y4
        );

        $svg .= '<path d="' . $d . '" fill="' . $fyld . '" fill-opacity="' . $opacitet
              . '" stroke="rgba(255,255,255,0.35)" stroke-width="1"/>';

        // Månedsnavnet midt i feltet.
        $midt = deg2rad(($start + $slut) / 2);
        $lx = $cx + (($ydre + $indre) / 2) * cos($midt);
        $ly = $cy + (($ydre + $indre) / 2) * sin($midt);

        $svg .= '<text x="' . sprintf('%.2f', $lx) . '" y="' . sprintf('%.2f', $ly - 6)
              . '" text-anchor="middle" dominant-baseline="middle" class="hjul-maaned">'
              . $e(AARSHJUL_MAANEDER_KORT[$m]) . '</text>';

        // En prik pr. kategori, der har noget i måneden.
        if ($aktiv) {
            $antal = count($kategorier);
            $bredde = 11.0;
            $startX = $lx - (($antal - 1) * $bredde) / 2;

            foreach ($kategorier as $n => $kat) {
                $svg .= '<circle cx="' . sprintf('%.2f', $startX + $n * $bredde)
                      . '" cy="' . sprintf('%.2f', $ly + 10) . '" r="4.2" fill="'
                      . AARSHJUL_KATEGORIER[$kat]['farve'] . '"/>';
            }
        }
    }

    $svg .= '<text x="200" y="192" text-anchor="middle" class="hjul-titel">ÅRSHJUL</text>';
    $svg .= '<text x="200" y="220" text-anchor="middle" class="hjul-aar">' . $aar . '</text>';
    $svg .= '</svg>';

    return $svg;
}

/**
 * Skriver hele årshjulssiden ud.
 *
 * $poster kommer fra aarshjul_list(). Databasen slås ikke op herinde, så
 * siden kan gengives uden en forbindelse — det er sådan, den afprøves.
 */
function aarshjul_render_page(array $poster, ?array $user, array $message, int $aar): void
{
    $canManage = aarshjul_can_manage($user);
    $csrf = $canManage ? auth_csrf_token() : '';
    $e = 'aarshjul_e';

    $antal = 0;
    foreach ($poster as $liste) {
        $antal += count($liste);
    }
    ?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Årshjul for Grundejerforeningen Hesselbjerg Nord — bestyrelsesmøder, generalforsamling, arbejdsdag og grillaften.">
<title>Årshjul / Aktiviteter — Grundejerforeningen Hesselbjerg Nord</title>
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
  .panel {
    background: rgba(10, 20, 25, 0.42);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(4px);
  }
  h1 {
    font-size: clamp(2rem, 4vw, 2.8rem);
    margin-bottom: 10px;
  }
  .lead {
    font-weight: 300;
    color: rgba(255,255,255,0.9);
    max-width: 64ch;
    line-height: 1.55;
  }

  /* Hjulet og listen ved siden af hinanden, når der er plads. */
  .top {
    display: grid;
    grid-template-columns: minmax(280px, 400px) 1fr;
    gap: 30px;
    align-items: start;
    margin-top: 26px;
  }
  .hjul { width: 100%; height: auto; display: block; }
  .hjul-maaned {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 1px;
    fill: rgba(255,255,255,0.92);
  }
  .hjul-titel {
    font-size: 19px;
    letter-spacing: 4px;
    fill: rgba(255,255,255,0.75);
  }
  .hjul-aar {
    font-size: 30px;
    font-weight: 700;
    fill: #fff;
  }

  .signatur {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    margin-top: 14px;
  }
  .signatur span {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: rgba(255,255,255,0.85);
  }
  .prik {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
  }

  /* Posterne, grupperet efter kategori. */
  .gruppe { margin-bottom: 26px; }
  .gruppe:last-child { margin-bottom: 0; }
  .gruppe h2 {
    font-size: 1.15rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .post {
    padding: 14px 16px;
    margin-bottom: 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.14);
    border-left-width: 4px;
    border-radius: 12px;
  }
  .post-top {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px 14px;
  }
  .post-titel { font-size: 1.05rem; font-weight: 600; }
  .post-tid {
    font-size: 0.92rem;
    color: rgba(255,255,255,0.72);
  }
  .post-dato {
    font-size: 0.85rem;
    padding: 2px 10px;
    border-radius: 20px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.25);
    white-space: nowrap;
  }
  .post-tekst {
    margin-top: 6px;
    font-weight: 300;
    line-height: 1.55;
    color: rgba(255,255,255,0.85);
    max-width: 68ch;
  }
  .tom {
    color: rgba(255,255,255,0.75);
    font-weight: 300;
  }

  .message { margin-top: 16px; font-weight: 600; }
  .message.error { color: #ffd3d3; }
  .message.success { color: #d7ffd7; }

  /* Redigeringen — kun for bestyrelsen. */
  .rediger h2 {
    font-size: 1.4rem;
    margin-bottom: 6px;
  }
  .rediger > p {
    font-weight: 300;
    color: rgba(255,255,255,0.8);
    max-width: 66ch;
    line-height: 1.5;
    margin-bottom: 20px;
  }
  .ret-post {
    padding: 16px;
    margin-bottom: 14px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.16);
    border-radius: 12px;
  }
  .ret-raekke {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
  }
  .felt { display: flex; flex-direction: column; gap: 5px; }
  .felt label {
    font-size: 0.8rem;
    color: rgba(255,255,255,0.65);
  }
  input, select, textarea, button { font: inherit; }
  input[type="text"], input[type="number"], select, textarea {
    padding: 9px 11px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.08);
    color: #fff;
  }
  select option { background: #16222b; color: #fff; }
  textarea { width: 100%; min-height: 62px; resize: vertical; line-height: 1.5; }
  .felt-bred { flex: 1 1 260px; }
  .felt-smal { width: 110px; }
  button {
    background: rgba(255,255,255,0.12);
    color: #fff;
    padding: 9px 18px;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 10px;
    cursor: pointer;
  }
  button:hover { background: rgba(255,255,255,0.2); }
  button.slet {
    border-color: rgba(255,140,140,0.5);
    color: #ffd3d3;
  }
  .ret-knapper {
    display: flex;
    gap: 10px;
    margin-top: 12px;
    align-items: center;
  }
  .ret-aendret {
    font-size: 0.82rem;
    color: rgba(255,255,255,0.5);
    margin-left: auto;
  }
  .ny {
    margin-top: 26px;
    padding-top: 22px;
    border-top: 1px solid rgba(255,255,255,0.16);
  }
  .ny h3 {
    font-size: 1rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(150, 210, 255, 0.95);
    margin-bottom: 14px;
  }
  .hint {
    margin-top: 10px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.6);
  }

  footer {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 10px 10px 30px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.8);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }

  @media (max-width: 760px) {
    .top { grid-template-columns: 1fr; }
    .felt-bred, .felt-smal { width: 100%; flex: 1 1 100%; }
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
      <a href="aarshjul.php" class="active">Årshjul</a>
      <?php if (!$user): ?>
        <a href="ny-beboer.php">Ny beboer</a>
      <?php else: ?>
        <a href="vedtaegter.php">Vedtægter</a>
        <a href="medlemsfotos.php">Medlemsfotos</a>
        <a href="generalforsamling.php">Generalforsamling</a>
        <?php if ($canManage): ?>
          <a href="besked.php">Beskeder</a>
          <a href="adgangskort.php">Adgangskort</a>
          <a href="beboerkartotek.php">Beboerkartotek</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="nav-account">
      <?php if ($user): ?>
        <span class="nav-user">Logget ind som <?php echo $e($user['display_name']); ?></span>
        <a href="logout.php" class="login-btn">Log ud</a>
      <?php else: ?>
        <a href="index.html?login=1" class="login-btn">Login</a>
      <?php endif; ?>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Årshjul / Aktiviteter</h1>
      <p class="lead">Foreningens faste år — hvornår bestyrelsen mødes, hvornår
        generalforsamlingen holdes, og hvornår vi ses til arbejdsdag og
        grillaften. Datoerne for generalforsamling og arbejdsdag regnes ud for
        det år, du ser på, så de altid passer.</p>

      <?php if ($message['error'] !== ''): ?>
        <div class="message error"><?php echo $e($message['error']); ?></div>
      <?php elseif ($message['success'] !== ''): ?>
        <div class="message success"><?php echo $e($message['success']); ?></div>
      <?php endif; ?>

      <?php if ($antal === 0): ?>
        <p class="tom" style="margin-top:22px;">Årshjulet er ikke fyldt ud endnu.</p>
      <?php else: ?>
        <div class="top">
          <div>
            <?php echo aarshjul_svg($poster, $aar); ?>
            <div class="signatur">
              <?php foreach (AARSHJUL_KATEGORIER as $noegle => $kat): ?>
                <?php /* En kategori uden poster hører ikke til i signaturen. */ ?>
                <?php if (!$poster[$noegle]) { continue; } ?>
                <span>
                  <i class="prik" style="background: <?php echo $e($kat['farve']); ?>"></i>
                  <?php echo $e($kat['navn']); ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <?php foreach (AARSHJUL_KATEGORIER as $noegle => $kat): ?>
              <?php if (!$poster[$noegle]) { continue; } ?>
              <div class="gruppe">
                <h2>
                  <i class="prik" style="background: <?php echo $e($kat['farve']); ?>"></i>
                  <?php echo $e($kat['navn']); ?>
                </h2>

                <?php foreach ($poster[$noegle] as $post): ?>
                  <?php $dato = aarshjul_beregnet_dato($post['beregning'], $aar); ?>
                  <div class="post" style="border-left-color: <?php echo $e($kat['farve']); ?>">
                    <div class="post-top">
                      <span class="post-titel"><?php echo $e($post['titel']); ?></span>
                      <?php if ($post['tidspunkt'] !== ''): ?>
                        <span class="post-tid"><?php echo $e($post['tidspunkt']); ?></span>
                      <?php endif; ?>
                      <?php if ($dato !== ''): ?>
                        <span class="post-dato">I <?php echo $aar; ?>: <?php echo $e($dato); ?></span>
                      <?php endif; ?>
                    </div>
                    <?php if (trim($post['beskrivelse']) !== ''): ?>
                      <p class="post-tekst"><?php echo nl2br($e($post['beskrivelse'])); ?></p>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
      <div class="panel rediger">
        <h2>Ret i årshjulet</h2>
        <p>Rettelserne slår igennem med det samme på den offentlige side.
          Måneden bestemmer, hvor posten sidder på hjulet — lad den stå tom,
          hvis posten ikke har en fast måned. Vælges en udregnet dato, skriver
          siden selv den rigtige dato for hvert år, og måneden følger med.</p>

        <?php foreach (AARSHJUL_KATEGORIER as $noegle => $kat): ?>
          <?php foreach ($poster[$noegle] as $post): ?>
            <form method="post" class="ret-post">
              <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
              <input type="hidden" name="id" value="<?php echo (int)$post['id']; ?>">

              <div class="ret-raekke">
                <div class="felt felt-bred">
                  <label for="titel-<?php echo (int)$post['id']; ?>">Overskrift</label>
                  <input id="titel-<?php echo (int)$post['id']; ?>" type="text" name="titel"
                         maxlength="200" required value="<?php echo $e($post['titel']); ?>">
                </div>
                <div class="felt felt-bred">
                  <label for="tid-<?php echo (int)$post['id']; ?>">Tidspunkt</label>
                  <input id="tid-<?php echo (int)$post['id']; ?>" type="text" name="tidspunkt"
                         maxlength="120" value="<?php echo $e($post['tidspunkt']); ?>">
                </div>
                <div class="felt">
                  <label for="kat-<?php echo (int)$post['id']; ?>">Kategori</label>
                  <select id="kat-<?php echo (int)$post['id']; ?>" name="kategori">
                    <?php foreach (AARSHJUL_KATEGORIER as $k => $v): ?>
                      <option value="<?php echo $e($k); ?>"<?php echo $k === $post['kategori'] ? ' selected' : ''; ?>>
                        <?php echo $e($v['navn']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="felt">
                  <label for="maaned-<?php echo (int)$post['id']; ?>">Måned</label>
                  <select id="maaned-<?php echo (int)$post['id']; ?>" name="maaned">
                    <option value="0">Ingen fast måned</option>
                    <?php foreach (AARSHJUL_MAANEDER as $nr => $navn): ?>
                      <option value="<?php echo $nr; ?>"<?php echo (int)$post['maaned'] === $nr ? ' selected' : ''; ?>>
                        <?php echo $e(ucfirst($navn)); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="felt felt-smal">
                  <label for="sort-<?php echo (int)$post['id']; ?>">Rækkefølge</label>
                  <input id="sort-<?php echo (int)$post['id']; ?>" type="number" name="sortering"
                         value="<?php echo (int)$post['sortering']; ?>">
                </div>
              </div>

              <div class="ret-raekke" style="margin-top:12px;">
                <div class="felt felt-bred">
                  <label for="ber-<?php echo (int)$post['id']; ?>">Udregnet dato</label>
                  <select id="ber-<?php echo (int)$post['id']; ?>" name="beregning">
                    <?php foreach (AARSHJUL_BEREGNINGER as $k => $v): ?>
                      <option value="<?php echo $e($k); ?>"<?php echo $k === $post['beregning'] ? ' selected' : ''; ?>>
                        <?php echo $e($v); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="felt" style="margin-top:12px;">
                <label for="besk-<?php echo (int)$post['id']; ?>">Uddybning</label>
                <textarea id="besk-<?php echo (int)$post['id']; ?>" name="beskrivelse"><?php echo $e($post['beskrivelse']); ?></textarea>
              </div>

              <div class="ret-knapper">
                <button type="submit" name="handling" value="gem">Gem</button>
                <button type="submit" name="handling" value="slet" class="slet"
                        onclick="return confirm('Fjern posten fra årshjulet?');">Fjern</button>
                <?php if ($post['updated_at']): ?>
                  <span class="ret-aendret">
                    Rettet <?php echo $e(date('d-m-Y', strtotime($post['updated_at']))); ?>
                    <?php if ($post['updated_by'] !== ''): ?>
                      af <?php echo $e($post['updated_by']); ?>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              </div>
            </form>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="ny">
          <h3>Tilføj en post</h3>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">

            <div class="ret-raekke">
              <div class="felt felt-bred">
                <label for="ny-titel">Overskrift</label>
                <input id="ny-titel" type="text" name="titel" maxlength="200" required
                       placeholder="Fx Arbejdsdag">
              </div>
              <div class="felt felt-bred">
                <label for="ny-tid">Tidspunkt</label>
                <input id="ny-tid" type="text" name="tidspunkt" maxlength="120"
                       placeholder="Fx Medio oktober">
              </div>
              <div class="felt">
                <label for="ny-kat">Kategori</label>
                <select id="ny-kat" name="kategori">
                  <?php foreach (AARSHJUL_KATEGORIER as $k => $v): ?>
                    <option value="<?php echo $e($k); ?>"><?php echo $e($v['navn']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="felt">
                <label for="ny-maaned">Måned</label>
                <select id="ny-maaned" name="maaned">
                  <option value="0">Ingen fast måned</option>
                  <?php foreach (AARSHJUL_MAANEDER as $nr => $navn): ?>
                    <option value="<?php echo $nr; ?>"><?php echo $e(ucfirst($navn)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="felt felt-smal">
                <label for="ny-sort">Rækkefølge</label>
                <input id="ny-sort" type="number" name="sortering" value="<?php echo $antal * 10 + 10; ?>">
              </div>
            </div>

            <div class="felt" style="margin-top:12px;">
              <label for="ny-besk">Uddybning</label>
              <textarea id="ny-besk" name="beskrivelse"></textarea>
            </div>

            <div class="ret-knapper">
              <button type="submit" name="handling" value="opret">Tilføj</button>
            </div>
          </form>
          <p class="hint">Rækkefølgen bestemmer, hvor posten står i sin gruppe — lavest tal først.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <footer>&copy; <?php echo date('Y'); ?> Grundejerforeningen Hesselbjerg Nord</footer>
</body>
</html>
    <?php
}

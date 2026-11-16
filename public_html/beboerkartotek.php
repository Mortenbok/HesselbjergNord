<?php
/**
 * Beboerkartoteket — bestyrelsens liste over, hvem der bor hvor.
 *
 * INDEHOLDER PERSONOPLYSNINGER: navne, hjemmeadresser, mails og telefonnumre.
 * Siden kræver login som bestyrelsesmedlem og er noindex.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/beboer.php';

auth_require('index.html');

$user = auth_user();

if (!beboer_can_manage($user)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kun bestyrelsen har adgang til denne side.');
}

$e = 'beboer_e';
$soeg = trim((string)($_GET['s'] ?? ''));
$vis = (string)($_GET['vis'] ?? '');

$hvor = [];
$felter = [];

if ($soeg !== '') {
    // Hvert felt får sin egen pladsholder, selv om værdien er den samme:
    // db.php slår PDO's efterligning fra, og MySQL kan ikke genbruge et
    // navngivet felt flere steder i den samme sætning.
    $soegFelter = ['kontaktperson', 'sommerhusadresse', 'mail', 'mail2',
                   'hjemmeadresse', '`by`', 'telefon', 'telefon2'];
    $dele = [];

    foreach ($soegFelter as $i => $felt) {
        $dele[] = $felt . ' LIKE :s' . $i;
        $felter[':s' . $i] = '%' . $soeg . '%';
    }

    $hvor[] = '(' . implode(' OR ', $dele) . ')';
}

if ($vis === 'mangler') {
    $hvor[] = "status = 'afventer_oplysninger'";
} elseif ($vis === 'bestyrelse') {
    $hvor[] = 'bestyrelse = 1';
} elseif ($vis === 'noter') {
    $hvor[] = "noter IS NOT NULL AND noter <> ''";
}

$sql = 'SELECT * FROM beboerkartotek';

if ($hvor) {
    $sql .= ' WHERE ' . implode(' AND ', $hvor);
}

$stmt = $pdo->prepare($sql);
$stmt->execute($felter);
$raekker = $stmt->fetchAll();

// Husnumre sorteres ikke naturligt af databasen ("Klitrosevej 10" før "2").
usort($raekker, static fn($a, $b) => strnatcasecmp($a['sommerhusadresse'], $b['sommerhusadresse'])
    ?: strcmp($a['forhold'], $b['forhold']));

$ialt = (int)$pdo->query('SELECT COUNT(*) FROM beboerkartotek')->fetchColumn();
$mangler = (int)$pdo->query("SELECT COUNT(*) FROM beboerkartotek WHERE status = 'afventer_oplysninger'")->fetchColumn();
$medNoter = (int)$pdo->query("SELECT COUNT(*) FROM beboerkartotek WHERE noter IS NOT NULL AND noter <> ''")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Beboerkartotek — Hesselbjerg Nord</title>
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
  .tal b { display: block; font-size: 1.3rem; }
  .tal span { font-size: 0.8rem; color: rgba(255,255,255,0.6); }
  form.soeg { display: flex; gap: 10px; flex-wrap: wrap; }
  input[type="search"] {
    flex: 1 1 260px; padding: 11px 14px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.08);
    color: #fff; font: inherit;
  }
  button {
    background: rgba(255,255,255,0.12); color: #fff; padding: 11px 20px; font: inherit;
    border: 1px solid rgba(255,255,255,0.4); border-radius: 10px; cursor: pointer;
  }
  .tabel-rum { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; min-width: 1000px; font-size: 0.9rem; }
  th, td { text-align: left; padding: 9px 11px; border-bottom: 1px solid rgba(255,255,255,0.12); vertical-align: top; }
  th { font-size: 0.74rem; letter-spacing: 1px; text-transform: uppercase; color: rgba(255,255,255,0.6); }
  tr.medejer td { color: rgba(255,255,255,0.72); background: rgba(255,255,255,0.03); }
  tr.mangler td { background: rgba(220, 170, 60, 0.1); }
  .maerke {
    display: inline-block; padding: 1px 9px; border-radius: 20px; font-size: 0.76rem;
    border: 1px solid rgba(255,255,255,0.3); white-space: nowrap;
  }
  .maerke.best { background: rgba(80,160,230,0.3); border-color: rgba(150,200,255,0.6); }
  .maerke.vent { background: rgba(220,170,60,0.3); border-color: rgba(255,210,120,0.6); }
  .note { font-size: 0.8rem; color: rgba(255,210,150,0.85); }
  td a { color: #cfe9ff; text-decoration: none; }
  td a:hover { text-decoration: underline; }
  .tom { color: rgba(255,255,255,0.8); font-weight: 300; }
  a.ret {
    color: #cfe9ff; text-decoration: none; white-space: nowrap;
    border: 1px solid rgba(255,255,255,0.28); border-radius: 8px;
    padding: 4px 12px; font-size: 0.84rem;
  }
  a.ret:hover { background: rgba(150,210,255,0.2); text-decoration: none; }
  a.tilfoej {
    display: inline-block; text-decoration: none; color: #fff;
    background: rgba(70, 150, 225, 0.5); border: 1px solid rgba(150, 200, 255, 0.7);
    border-radius: 10px; padding: 10px 20px;
  }
  a.tilfoej:hover { background: rgba(80, 165, 240, 0.65); }
  .besked-ok {
    background: rgba(40,140,70,0.32); color: #dcffe4; padding: 11px 14px;
    border-radius: 10px; margin-bottom: 14px; font-size: 0.93rem;
  }
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
      <a href="aarshjul.php">Årshjul</a>
      <a href="vedtaegter.php">Vedtægter</a>
      <a href="generalforsamling.php">Generalforsamling</a>
      <a href="besked.php">Beskeder</a>
      <a href="adgangskort.php">Adgangskort</a>
      <a href="beboerkartotek.php" class="active">Beboerkartotek</a>
    </div>
    <div class="nav-account">
      <span class="nav-user">Logget ind som <?php echo $e($user['display_name']); ?></span>
      <a href="logout.php" class="login-btn">Log ud</a>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Beboerkartotek</h1>
      <?php if (($_GET['besked'] ?? '') !== ''): ?>
        <div class="besked-ok"><?php echo $e((string)$_GET['besked']); ?></div>
      <?php endif; ?>
      <p class="lead">Hvem bor hvor. Kartoteket fyldes fra bestyrelsens regneark
        og vokser derefter af sig selv, når nye beboere tilmelder sig på
        <a href="ny-beboer.php" style="color:#cfe9ff;">Ny beboer</a>.</p>

      <div class="advarsel">
        Siden indeholder personoplysninger om navngivne mennesker. Del ikke
        skærmbilleder eller udtræk, og lad den ikke stå åben på en delt skærm.
      </div>

      <div class="tal">
        <a href="beboerkartotek.php" class="<?php echo $vis === '' ? 'valgt' : ''; ?>">
          <b><?php echo $ialt; ?></b><span>i alt</span>
        </a>
        <a href="?vis=mangler" class="<?php echo $vis === 'mangler' ? 'valgt' : ''; ?>">
          <b><?php echo $mangler; ?></b><span>mangler hjemmeadresse</span>
        </a>
        <a href="?vis=bestyrelse" class="<?php echo $vis === 'bestyrelse' ? 'valgt' : ''; ?>">
          <b><?php echo (int)$pdo->query('SELECT COUNT(*) FROM beboerkartotek WHERE bestyrelse = 1')->fetchColumn(); ?></b><span>bestyrelse</span>
        </a>
        <a href="?vis=noter" class="<?php echo $vis === 'noter' ? 'valgt' : ''; ?>">
          <b><?php echo $medNoter; ?></b><span>med note</span>
        </a>
      </div>

      <p style="margin-bottom:14px;">
        <a class="tilfoej" href="beboer-ret.php">+ Tilføj beboer</a>
      </p>

      <form method="get" class="soeg">
        <input type="search" name="s" placeholder="Søg på navn, adresse, mail, by eller telefon"
               value="<?php echo $e($soeg); ?>">
        <button type="submit">Søg</button>
        <?php if ($soeg !== '' || $vis !== ''): ?>
          <a class="login-btn" href="beboerkartotek.php" style="padding:11px 20px;">Nulstil</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="panel">
      <?php if (!$raekker): ?>
        <p class="tom">
          <?php if ($ialt === 0): ?>
            Kartoteket er tomt. Læg regnearket op på
            <a href="beboer-import.php" style="color:#cfe9ff;">importsiden</a>.
          <?php else: ?>
            Ingen rækker passer på søgningen.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <div class="tabel-rum">
          <table>
            <thead>
              <tr>
                <th>Sommerhus</th>
                <th>Kontaktperson</th>
                <th>Hjemmeadresse</th>
                <th>Mail</th>
                <th>Telefon</th>
                <th></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($raekker as $r): ?>
                <?php
                  $venter = $r['status'] === 'afventer_oplysninger';
                  $klasse = trim(($r['forhold'] === 'medejer' ? 'medejer ' : '') . ($venter ? 'mangler' : ''));
                ?>
                <tr class="<?php echo $klasse; ?>">
                  <td>
                    <?php echo $e($r['sommerhusadresse']); ?>
                    <?php if ($r['forhold'] === 'medejer'): ?>
                      <div class="note">medejer</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php echo $e($r['kontaktperson']); ?>
                    <?php if ((int)$r['bestyrelse'] === 1): ?>
                      <div><span class="maerke best"><?php echo $e($r['rolle'] !== '' ? $r['rolle'] : 'Bestyrelse'); ?></span></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($venter): ?>
                      <span class="maerke vent">afventer</span>
                    <?php else: ?>
                      <?php echo $e($r['hjemmeadresse']); ?><br>
                      <?php if ($r['sted'] !== ''): ?><div class="note"><?php echo $e($r['sted']); ?></div><?php endif; ?>
                      <?php echo $e(trim($r['postnr'] . ' ' . $r['by'])); ?>
                      <?php if ($r['land'] !== ''): ?>
                        <div class="note"><?php echo $e($r['land']); ?></div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($r['mail'] !== ''): ?>
                      <a href="mailto:<?php echo $e($r['mail']); ?>"><?php echo $e($r['mail']); ?></a>
                    <?php endif; ?>
                    <?php if ($r['mail2'] !== ''): ?>
                      <div class="note"><?php echo $e($r['mail2']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php echo $e($r['telefon']); ?>
                    <?php if ($r['telefon2'] !== ''): ?>
                      <div class="note"><?php echo $e($r['telefon2']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (trim((string)$r['noter']) !== ''): ?>
                      <span class="note"><?php echo $e($r['noter']); ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a class="ret" href="beboer-ret.php?id=<?php echo (int)$r['id']; ?>">Ret</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <p class="lead"><a href="beboer-import.php" style="color:#cfe9ff;">Importér regneark</a></p>
    </div>
  </div>

  <footer>&copy; <?php echo date('Y'); ?> Grundejerforeningen Hesselbjerg Nord</footer>
</body>
</html>

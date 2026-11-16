<?php
/**
 * Postkortene, klar til print.
 *
 * Otte kort pr. A4-side. QR-koderne tegnes i browseren som SVG, så de bliver
 * skarpe uanset printer — en PNG ville blive grynet på papir.
 *
 * ?kun=ubrugte printer kun kort til de husstande, der ikke har oprettet sig
 * endnu. Det er den, man bruger anden gang, man deler ud.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/adgang.php';

auth_require('index.html');

$user = auth_user();

if (($user['role'] ?? '') !== 'bestyrelse') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kun bestyrelsen har adgang til denne side.');
}

$e = 'adgang_e';
$kun = (string)($_GET['kun'] ?? '');

$kort = $pdo->query(
    'SELECT k.adresse, k.kode, k.spaerret,
            (SELECT COUNT(*) FROM users u WHERE u.address = k.adresse) AS brugere
       FROM adgangskort k
      WHERE k.spaerret = 0'
)->fetchAll();

if ($kun === 'ubrugte') {
    $kort = array_values(array_filter($kort, static fn($k) => (int)$k['brugere'] === 0));
}

usort($kort, static fn($a, $b) => strnatcasecmp($a['adresse'], $b['adresse']));

/**
 * Adressen, QR-koden peger på.
 *
 * Skemaet tages fra den aktuelle forespørgsel, så koderne virker både på
 * hesselbjergnord.dk og hvis siden en dag flytter.
 */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'hesselbjergnord.dk');
$base .= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Postkort til print — Hesselbjerg Nord</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #4a5560;
    color: #11191f;
    padding: 24px;
  }

  .vejledning {
    max-width: 820px;
    margin: 0 auto 24px;
    background: #fff;
    border-radius: 12px;
    padding: 20px 24px;
    line-height: 1.55;
  }
  .vejledning h1 { font-size: 1.4rem; margin-bottom: 10px; }
  .vejledning p { margin-bottom: 10px; }
  .vejledning button {
    font: inherit;
    padding: 10px 20px;
    border: 1px solid #2a6496;
    background: #3b7fb8;
    color: #fff;
    border-radius: 8px;
    cursor: pointer;
  }
  .vejledning a { color: #2a6496; }

  .ark {
    max-width: 820px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }

  .kort {
    background: #fff;
    border: 1px dashed #9aa6b0;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    gap: 14px;
    align-items: center;
    /* Et kort må aldrig brækkes over to sider. */
    break-inside: avoid;
    page-break-inside: avoid;
    min-height: 118px;
  }

  .kort .qr { flex: 0 0 96px; width: 96px; height: 96px; }
  .kort .qr svg { width: 100%; height: 100%; display: block; }

  .kort .tekst { flex: 1; min-width: 0; }
  .kort .forening {
    font-size: 0.56rem;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    color: #5d6b78;
  }
  .kort .adresse {
    font-size: 1.02rem;
    font-weight: 700;
    margin: 1px 0 5px;
  }
  .kort .hvad {
    font-size: 0.68rem;
    line-height: 1.38;
    color: #33414d;
    margin-bottom: 5px;
  }
  .kort .kode {
    font-family: ui-monospace, Consolas, monospace;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 1.5px;
  }
  .kort .url {
    font-size: 0.62rem;
    color: #5d6b78;
    word-break: break-all;
  }

  .tom {
    max-width: 820px;
    margin: 0 auto;
    background: #fff;
    border-radius: 12px;
    padding: 24px;
  }

  @media print {
    body { background: #fff; padding: 0; }
    .vejledning { display: none; }
    .ark { max-width: none; gap: 0; }
    .kort {
      border: 1px dashed #bbb;
      border-radius: 0;
      min-height: 33vh;
      padding: 10mm;
    }
    @page { size: A4; margin: 8mm; }
  }
</style>
</head>
<body>
  <div class="vejledning">
    <h1>Postkort med adgangskode — <?php echo count($kort); ?> stk.</h1>
    <p>Hvert kort hører til én husstand. Klip langs de stiplede linjer, og læg
      ét kort i hver postkasse. Kortet brænder ikke ved brug: begge ejere kan
      scanne det, og et glemt kodeord klares ved at scanne igen.</p>
    <p><strong>Gem ikke filen og send den ikke rundt</strong> — alle koderne
      står i den. Print den, og luk fanen.</p>
    <p>
      <button type="button" onclick="window.print()">Print</button>
      &nbsp; <a href="adgangskort.php">Tilbage til oversigten</a>
    </p>
  </div>

  <?php if (!$kort): ?>
    <div class="tom">
      <p>Der er ingen kort at printe
        <?php echo $kun === 'ubrugte' ? '— alle husstande har oprettet sig.' : '.'; ?>
      </p>
      <p><a href="adgangskort.php">Tilbage til oversigten</a></p>
    </div>
  <?php else: ?>
    <div class="ark">
      <?php foreach ($kort as $k): ?>
        <?php $url = $base . '/adgang.php?k=' . $k['kode']; ?>
        <div class="kort">
          <div class="qr" data-url="<?php echo $e($url); ?>"></div>
          <div class="tekst">
            <p class="forening">Grundejerforeningen Hesselbjerg Nord</p>
            <p class="adresse"><?php echo $e($k['adresse']); ?></p>
            <p class="hvad">Scan og få adgang til referater, regnskaber,
              vedtægter og medlemsbilleder. Gem kortet — det er husstandens
              nøgle, også hvis du glemmer din adgangskode.</p>
            <p class="kode">Kode: <?php echo $e(adgang_kode_paent($k['kode'])); ?></p>
            <p class="url"><?php echo $e($base . '/adgang.php'); ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
  <script>
  (function () {
    'use strict';

    if (typeof qrcode !== 'function') {
      return;
    }

    document.querySelectorAll('.qr').forEach(function (rum) {
      // Type 0 = vælg selv den mindste version, der kan rumme teksten.
      // 'M' tåler, at op mod 15 % af koden bliver snavset eller foldet.
      var qr = qrcode(0, 'M');
      qr.addData(rum.dataset.url);
      qr.make();
      rum.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
    });
  })();
  </script>
</body>
</html>

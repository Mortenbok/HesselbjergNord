<?php
/**
 * Lægger det ryddede regneark ind i beboerkartoteket.
 *
 * Filen uploades her i stedet for at ligge i git: depotet er offentligt, og
 * kartoteket indeholder navne, adresser, mails og telefonnumre på ~200
 * navngivne mennesker. Filen gemmes ikke på serveren — den læses, skrives i
 * databasen og kasseres.
 *
 * Kræver login som bestyrelsesmedlem.
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
$csrf = auth_csrf_token();
$fejl = '';
$resultat = null;

/**
 * Overskrifterne, filen skal have. De kommer fra BEBOER_FELTER, så importen
 * følger med af sig selv, hvis regnearket får en kolonne mere.
 */
$KOLONNER = array_column(BEBOER_FELTER, 'ark');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $fejl = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
    } elseif (($_POST['handling'] ?? '') === 'toem') {
        // Tømmer HELE tabellen — også dem, der selv har tilmeldt sig.
        // TRUNCATE nulstiller samtidig id-tælleren.
        $foer = (int)$pdo->query('SELECT COUNT(*) FROM beboerkartotek')->fetchColumn();
        $pdo->exec('TRUNCATE TABLE beboerkartotek');
        $resultat = ['indsat' => 0, 'sprunget' => 0, 'linjer' => [],
                     'toemt' => $foer];
    } elseif (!isset($_FILES['fil']) || $_FILES['fil']['error'] !== UPLOAD_ERR_OK) {
        $fejl = 'Vælg en CSV-fil.';
    } else {
        $f = fopen($_FILES['fil']['tmp_name'], 'r');

        if ($f === false) {
            $fejl = 'Filen kunne ikke læses.';
        } else {
            $hdr = fgetcsv($f, 0, ',', '"', '\\');

            // Excel skriver et BOM forrest, som ellers klistrer sig på den
            // første kolonneoverskrift og får opslaget til at slå fejl.
            if ($hdr && isset($hdr[0])) {
                $hdr[0] = preg_replace('/^\xEF\xBB\xBF/', '', $hdr[0]);
            }

            $plads = [];

            foreach (($hdr ?: []) as $i => $navn) {
                $plads[trim((string)$navn)] = $i;
            }

            $mangler = array_values(array_filter($KOLONNER, fn($k) => !isset($plads[$k])));

            if ($mangler) {
                $fejl = 'Filen mangler kolonnerne: ' . implode(', ', $mangler);
                fclose($f);
            } else {
                $hent = static function (array $r, string $navn) use ($plads): string {
                    return trim((string)($r[$plads[$navn]] ?? ''));
                };

                $erstat = ($_POST['erstat'] ?? '') === '1';
                $indsat = 0;
                $sprunget = 0;
                $linjer = [];

                // BFE-numrene står ikke i regnearket — de er tastet i hånden
                // på beboer-ret.php. De ville gå tabt ved en genimport, så vi
                // gemmer dem på adressen og lægger dem tilbage bagefter.
                $gemtBfe = [];

                $bfeRaekker = $pdo->query(
                    "SELECT sommerhusadresse, bfe FROM beboerkartotek WHERE bfe <> ''"
                )->fetchAll();

                foreach ($bfeRaekker as $b) {
                    $noegle = adresse_noegle_af_fritekst((string)$b['sommerhusadresse']);

                    if ($noegle !== '') {
                        $gemtBfe[$noegle] = (string)$b['bfe'];
                    }
                }

                $pdo->beginTransaction();

                try {
                    if ($erstat) {
                        // Kun rækker fra regnearket ryddes. Beboere, der selv
                        // har tilmeldt sig, må ikke forsvinde ved en genimport.
                        $pdo->exec("DELETE FROM beboerkartotek WHERE kilde = 'kartotek'");
                    }

                    $felter = array_keys(BEBOER_FELTER);
                    $kolonner = implode(', ', array_map('beboer_kolonne', $felter));
                    $pladser = ':' . implode(', :', $felter);

                    $ind = $pdo->prepare(
                        "INSERT INTO beboerkartotek ($kolonner, bfe, kilde, status)
                         VALUES ($pladser, :bfe, 'kartotek', 'aktiv')"
                    );

                    while (($r = fgetcsv($f, 0, ',', '"', '\\')) !== false) {
                        if (trim(implode('', $r)) === '') {
                            continue;
                        }

                        $navn = $hent($r, 'Kontaktperson');
                        $adresse = $hent($r, 'Sommerhusadresse');

                        if ($navn === '' || $adresse === '') {
                            $sprunget++;
                            $linjer[] = 'sprunget over (mangler navn eller adresse): ' . ($adresse ?: $navn);
                            continue;
                        }

                        $bind = [];

                        foreach (BEBOER_FELTER as $felt => $info) {
                            $raa = $hent($r, $info['ark']);

                            $bind[':' . $felt] = match ($info['type']) {
                                // Regneark skriver TRUE/SAND/1 — alt andet er nej.
                                'ja-nej' => in_array(strtoupper($raa), ['TRUE', 'SAND', '1', 'JA'], true) ? 1 : 0,
                                'valg' => strtolower($raa) === 'medejer' ? 'medejer' : 'ejer',
                                'mail' => mb_strtolower($raa),
                                default => $raa,
                            };
                        }

                        $noegle = adresse_noegle_af_fritekst($adresse);
                        $bind[':bfe'] = $gemtBfe[$noegle] ?? '';

                        $ind->execute($bind);

                        $indsat++;
                    }

                    $pdo->commit();
                    $resultat = ['indsat' => $indsat, 'sprunget' => $sprunget, 'linjer' => $linjer];
                } catch (PDOException $ex) {
                    $pdo->rollBack();
                    error_log('Beboerimport: ' . $ex->getMessage());
                    $fejl = 'Databasen afviste importen. Intet blev gemt.';
                }

                fclose($f);
            }
        }
    }
}

$antal = (int)$pdo->query('SELECT COUNT(*) FROM beboerkartotek')->fetchColumn();
$fraArk = (int)$pdo->query("SELECT COUNT(*) FROM beboerkartotek WHERE kilde = 'kartotek'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Importér beboerkartotek — Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { min-height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover; color: #fff; position: relative;
  }
  body::before { content: ""; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 0; }
  .container { position: relative; z-index: 1; max-width: 820px; margin: 0 auto; padding: 40px 20px 80px; }
  .panel {
    background: rgba(10, 20, 25, 0.55); border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px; padding: 26px; margin-bottom: 20px; backdrop-filter: blur(4px);
  }
  h1 { font-size: 1.9rem; margin-bottom: 12px; }
  p { line-height: 1.6; font-weight: 300; margin-bottom: 12px; color: rgba(255,255,255,0.9); }
  .tal { display: flex; gap: 10px; flex-wrap: wrap; margin: 18px 0; }
  .tal div {
    padding: 10px 16px; background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.16); border-radius: 12px;
  }
  .tal b { display: block; font-size: 1.3rem; }
  .tal span { font-size: 0.8rem; color: rgba(255,255,255,0.6); }
  input[type="file"] {
    display: block; width: 100%; padding: 12px; margin-bottom: 14px;
    border-radius: 10px; border: 1px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.08); color: #fff; font: inherit;
  }
  label.valg { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 16px; font-size: 0.93rem; }
  label.valg input { width: 20px; height: 20px; margin-top: 2px; flex: none; }
  button {
    background: rgba(70, 150, 225, 0.55); color: #fff; font: inherit; font-size: 1rem;
    border: 1px solid rgba(150, 200, 255, 0.7); border-radius: 10px;
    padding: 12px 24px; cursor: pointer;
  }
  button:hover { background: rgba(80, 165, 240, 0.7); }
  .besked { padding: 12px 15px; border-radius: 10px; margin-bottom: 16px; line-height: 1.5; }
  .besked.fejl { background: rgba(180,40,40,0.35); color: #ffdede; }
  .besked.ok { background: rgba(40,140,70,0.32); color: #dcffe4; }
  .advarsel {
    background: rgba(170, 120, 20, 0.28); border: 1px solid rgba(255, 200, 100, 0.45);
    border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; font-size: 0.93rem; line-height: 1.55;
  }
  ul { margin: 10px 0 10px 1.3em; font-size: 0.9rem; line-height: 1.6; }
  a { color: #cfe9ff; }
  code { background: rgba(255,255,255,0.1); padding: 1px 6px; border-radius: 5px; font-size: 0.9em; }
</style>
</head>
<body>
  <div class="container">
    <div class="panel">
      <h1>Importér beboerkartotek</h1>

      <div class="advarsel">
        <strong>Filen indeholder personoplysninger.</strong> Den bliver læst og
        skrevet i databasen med det samme — den gemmes ikke på serveren. Læg
        den aldrig i git: depotet er offentligt.
      </div>

      <div class="tal">
        <div><b><?php echo $antal; ?></b><span>i kartoteket</span></div>
        <div><b><?php echo $fraArk; ?></b><span>fra regnearket</span></div>
        <div><b><?php echo $antal - $fraArk; ?></b><span>tilmeldt selv</span></div>
      </div>

      <?php if ($fejl !== ''): ?>
        <div class="besked fejl"><?php echo $e($fejl); ?></div>
      <?php endif; ?>

      <?php if ($resultat !== null): ?>
        <div class="besked ok">
          <?php if (isset($resultat['toemt'])): ?>
            Kartoteket er tømt. <?php echo (int)$resultat['toemt']; ?> rækker blev slettet.
          <?php else: ?>
          <?php echo (int)$resultat['indsat']; ?> rækker lagt i kartoteket.
          <?php if ($resultat['sprunget'] > 0): ?>
            <?php echo (int)$resultat['sprunget']; ?> sprunget over.
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($resultat['linjer']): ?>
          <ul>
            <?php foreach (array_slice($resultat['linjer'], 0, 20) as $l): ?>
              <li><?php echo $e($l); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">

        <p>Vælg den ryddede CSV-fil. Den skal have kolonnerne
          <code>Sommerhusadresse</code>, <code>Kontaktperson</code>,
          <code>Mail</code> og de øvrige fra oprydningen.</p>

        <input type="file" name="fil" accept=".csv,text/csv" required>

        <label class="valg">
          <input type="checkbox" name="erstat" value="1" checked>
          <span>Ryd de rækker, der kom fra regnearket, før importen. Beboere,
            der selv har tilmeldt sig på Ny beboer, røres ikke.</span>
        </label>

        <button type="submit">Importér</button>
      </form>
    </div>

    <div class="panel">
      <h2 style="font-size:1.2rem;margin-bottom:10px;">Tøm kartoteket</h2>
      <p>Sletter <strong>alle</strong> rækker — også dem, beboerne selv har
        oprettet gennem Ny beboer. Brug det, når kartoteket skal fyldes helt
        forfra fra regnearket. Det kan ikke fortrydes.</p>

      <form method="post"
            onsubmit="return confirm('Slet alle <?php echo $antal; ?> rækker i beboerkartoteket? Det kan ikke fortrydes.');">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
        <button type="submit" name="handling" value="toem"
                style="background:transparent;border-color:rgba(255,140,140,0.55);color:#ffd3d3;">
          Tøm kartoteket (<?php echo $antal; ?> rækker)
        </button>
      </form>
    </div>

    <div class="panel">
      <p><a href="beboerkartotek.php">Se kartoteket</a> &nbsp;·&nbsp;
         <a href="index.html">Forsiden</a></p>
    </div>
  </div>
</body>
</html>

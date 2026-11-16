<?php
/**
 * Beregner foreningens område som en zone omkring vejene.
 *
 * Det konvekse hylster duede ikke: det lagde et stort stykke skov og sø
 * sydvest for området ind under foreningen. I stedet lægges der en zone på
 * BUFFER meter ud til hver side af hver vej, og omridset af den samlede zone
 * tegnes. Så følger området vejene, som det skal.
 *
 * Fremgangsmåde: vejene lægges ned i et gitter, hver celle markeres, hvis den
 * ligger inden for BUFFER meter af en vej, og omridset af de markerede celler
 * følges rundt. Til sidst blødgøres hjørnerne.
 */

const BUFFER = 65.0;   // meter ud til hver side af vejen
const CELL   = 8.0;    // gitterets cellestørrelse i meter

$data = json_decode(file_get_contents(__DIR__ . '/osm-raa.json'), true);

// --- vejene som segmenter i lokale meter ---
$pts = [];
foreach ($data['elements'] as $e) {
    foreach ($e['geometry'] as $g) {
        $pts[] = [$g['lon'], $g['lat']];
    }
}

$lat0 = array_sum(array_column($pts, 1)) / count($pts);
$mLat = 111320.0;
$mLon = 111320.0 * cos(deg2rad($lat0));
$lon0 = min(array_column($pts, 0));
$latMin = min(array_column($pts, 1));

$toX = fn($lon) => ($lon - $lon0) * $mLon;
$toY = fn($lat) => ($lat - $latMin) * $mLat;

$segs = [];
foreach ($data['elements'] as $e) {
    $g = $e['geometry'];
    for ($i = 1; $i < count($g); $i++) {
        $segs[] = [
            $toX($g[$i-1]['lon']), $toY($g[$i-1]['lat']),
            $toX($g[$i]['lon']),   $toY($g[$i]['lat']),
        ];
    }
}

printf("%d segmenter\n", count($segs));

// --- gitteret ---
$xs = []; $ys = [];
foreach ($segs as $s) { $xs[] = $s[0]; $xs[] = $s[2]; $ys[] = $s[1]; $ys[] = $s[3]; }

$pad  = BUFFER + 2 * CELL;
$minX = min($xs) - $pad; $maxX = max($xs) + $pad;
$minY = min($ys) - $pad; $maxY = max($ys) + $pad;

$nx = (int)ceil(($maxX - $minX) / CELL);
$ny = (int)ceil(($maxY - $minY) / CELL);
printf("Gitter: %d x %d celler a %.0f m\n", $nx, $ny, CELL);

/** Afstand fra punkt til linjestykke. */
function segDist(float $px, float $py, array $s): float
{
    [$x1, $y1, $x2, $y2] = $s;
    $dx = $x2 - $x1; $dy = $y2 - $y1;
    $l2 = $dx * $dx + $dy * $dy;

    $t = $l2 > 0 ? (($px - $x1) * $dx + ($py - $y1) * $dy) / $l2 : 0.0;
    $t = max(0.0, min(1.0, $t));

    $ex = $px - ($x1 + $t * $dx);
    $ey = $py - ($y1 + $t * $dy);

    return sqrt($ex * $ex + $ey * $ey);
}

// Hvert segment rører kun celler i sin egen omegn — derfor markeres der ud fra
// segmenterne i stedet for at måle hver celle mod alle veje.
$mask = [];
$b2 = BUFFER;

foreach ($segs as $s) {
    $sMinX = min($s[0], $s[2]) - $b2; $sMaxX = max($s[0], $s[2]) + $b2;
    $sMinY = min($s[1], $s[3]) - $b2; $sMaxY = max($s[1], $s[3]) + $b2;

    $i0 = max(0, (int)floor(($sMinX - $minX) / CELL));
    $i1 = min($nx - 1, (int)ceil(($sMaxX - $minX) / CELL));
    $j0 = max(0, (int)floor(($sMinY - $minY) / CELL));
    $j1 = min($ny - 1, (int)ceil(($sMaxY - $minY) / CELL));

    for ($j = $j0; $j <= $j1; $j++) {
        $py = $minY + ($j + 0.5) * CELL;
        for ($i = $i0; $i <= $i1; $i++) {
            if (isset($mask[$j][$i])) continue;
            $px = $minX + ($i + 0.5) * CELL;
            if (segDist($px, $py, $s) <= BUFFER) $mask[$j][$i] = true;
        }
    }
}

$antalCeller = 0;
foreach ($mask as $row) $antalCeller += count($row);
printf("Markerede celler: %d (%.1f ha)\n", $antalCeller, $antalCeller * CELL * CELL / 10000);

// --- omridset: kanter mellem markeret og umarkeret celle ---
$inside = fn($i, $j) => isset($mask[$j][$i]);
$edges = [];

foreach ($mask as $j => $row) {
    foreach ($row as $i => $_) {
        if (!$inside($i, $j - 1)) $edges[] = [[$i, $j],     [$i + 1, $j]];
        if (!$inside($i + 1, $j)) $edges[] = [[$i + 1, $j], [$i + 1, $j + 1]];
        if (!$inside($i, $j + 1)) $edges[] = [[$i + 1, $j + 1], [$i, $j + 1]];
        if (!$inside($i - 1, $j)) $edges[] = [[$i, $j + 1], [$i, $j]];
    }
}

printf("Kantstykker: %d\n", count($edges));

// --- kæd kanterne sammen til lukkede ringe ---
$byStart = [];
foreach ($edges as $k => $e) {
    $byStart[$e[0][0] . ',' . $e[0][1]][] = $k;
}

$brugt = [];
$rings = [];

foreach ($edges as $k => $start) {
    if (isset($brugt[$k])) continue;

    $ring = [$start[0]];
    $cur = $k;

    while (true) {
        $brugt[$cur] = true;
        $to = $edges[$cur][1];
        $ring[] = $to;

        $key = $to[0] . ',' . $to[1];
        $next = null;

        foreach ($byStart[$key] ?? [] as $cand) {
            if (!isset($brugt[$cand])) { $next = $cand; break; }
        }

        if ($next === null) break;
        $cur = $next;
    }

    if (count($ring) > 8) $rings[] = $ring;
}

usort($rings, fn($a, $b) => count($b) <=> count($a));
printf("Ringe: %d (største %d punkter)\n", count($rings), count($rings[0]));

/** Fjerner punkter på en lige linje. */
function afkort(array $r): array
{
    $out = [];
    $n = count($r) - 1;

    for ($i = 0; $i < $n; $i++) {
        $p = $r[($i - 1 + $n) % $n]; $c = $r[$i]; $q = $r[($i + 1) % $n];
        $cross = ($c[0] - $p[0]) * ($q[1] - $p[1]) - ($c[1] - $p[1]) * ($q[0] - $p[0]);
        if ($cross != 0) $out[] = $c;
    }

    return $out;
}

/** Chaikin: skærer hjørnerne af, så omridset ikke står trappeformet. */
function blodgor(array $r, int $gange = 2): array
{
    for ($g = 0; $g < $gange; $g++) {
        $ny = [];
        $n = count($r);

        for ($i = 0; $i < $n; $i++) {
            $a = $r[$i]; $b = $r[($i + 1) % $n];
            $ny[] = [$a[0] * 0.75 + $b[0] * 0.25, $a[1] * 0.75 + $b[1] * 0.25];
            $ny[] = [$a[0] * 0.25 + $b[0] * 0.75, $a[1] * 0.25 + $b[1] * 0.75];
        }

        $r = $ny;
    }

    return $r;
}

/** Douglas-Peucker: fjerner punkter, der ikke ændrer formen nævneværdigt. */
function udtynd(array $p, float $tol): array
{
    if (count($p) < 3) return $p;

    $maxD = 0.0; $idx = 0;
    $a = $p[0]; $b = $p[count($p) - 1];

    for ($i = 1; $i < count($p) - 1; $i++) {
        $d = segDist($p[$i][0], $p[$i][1], [$a[0], $a[1], $b[0], $b[1]]);
        if ($d > $maxD) { $maxD = $d; $idx = $i; }
    }

    if ($maxD <= $tol) return [$a, $b];

    $v = udtynd(array_slice($p, 0, $idx + 1), $tol);
    $h = udtynd(array_slice($p, $idx), $tol);
    array_pop($v);

    return array_merge($v, $h);
}

$polygoner = [];

foreach ($rings as $ring) {
    // Tolerancen er i cellemål, så 0,4 celle ~ 3 m.
    $r = udtynd(blodgor(afkort($ring), 2), 0.4);

    $ll = [];
    foreach ($r as $p) {
        $x = $minX + $p[0] * CELL;
        $y = $minY + $p[1] * CELL;
        $ll[] = [round($lon0 + $x / $mLon, 6), round($latMin + $y / $mLat, 6)];
    }

    $ll[] = $ll[0];
    $polygoner[] = $ll;
}

// --- vejene, pæne og grupperet efter navn ---
$roads = [];
foreach ($data['elements'] as $e) {
    $line = [];
    foreach ($e['geometry'] as $g) $line[] = [round($g['lon'], 6), round($g['lat'], 6)];
    $roads[$e['tags']['name']][] = $line;
}
ksort($roads);

$lats = array_column($pts, 1); $lons = array_column($pts, 0);

file_put_contents(__DIR__ . '/data.json', json_encode([
    'roads'   => $roads,
    'area'    => $polygoner,
    'hektar'  => round($antalCeller * CELL * CELL / 10000, 1),
    'center'  => [round(($latMin + max($lats)) / 2, 6), round((min($lons) + max($lons)) / 2, 6)],
    'bounds'  => [[round(min($lats), 6), round(min($lons), 6)], [round(max($lats), 6), round(max($lons), 6)]],
], JSON_UNESCAPED_UNICODE));

printf("Polygoner: %d, data.json %d bytes\n", count($polygoner), filesize(__DIR__ . '/data.json'));

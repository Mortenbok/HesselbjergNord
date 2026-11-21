<?php
/**
 * Ejertjek — står ejeren i kartoteket også som ejer hos OIS?
 *
 * Én gang om måneden henter ejertjek.php ejernavnene fra OIS for hver
 * husstand med et BFE-nummer og gemmer dem i tabellen ejertjek. Selve
 * sammenligningen sker først, når admin.php vises — så retter bestyrelsen
 * et navn i kartoteket, skifter Ejer-knappen farve med det samme, uden at
 * OIS skal spørges igen.
 *
 * OIS' egen side (nyt.ois.dk/search/<BFE>) henter navnene fra
 * /api/ejer/get?bfe=<BFE>. Det svar er offentligt og kræver intet login;
 * andele og adresser er skjult, men navnene står der.
 */

require_once __DIR__ . '/admin.php';

/** Hvor gammelt et tjek må være, før det tages igen. Lidt under en måned. */
const EJERTJEK_DAGE = 25;

/** Husstande pr. kald. Hvert opslag tager under et sekund. */
const EJERTJEK_AD_GANGEN = 25;

/** Henter ejernavnene på en ejendom. Returnerer ['ok' => bool, 'navne' => [], 'fejl' => string]. */
function ejertjek_hent_ois(string $bfe): array
{
    $ch = curl_init('https://nyt.ois.dk/api/ejer/get?bfe=' . rawurlencode($bfe));
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Grundejerforeningen Hesselbjerg Nord (ejertjek)',
    ];

    $ca = (string)(sms_config()['sms_ca_bundle'] ?? '');

    // Kun hvis filen findes. config.local.php kan være kopieret fra en
    // Windows-maskine med en sti, der ikke findes på serveren — og så
    // fejler hvert eneste opslag, som det gjorde første gang.
    if ($ca !== '' && is_readable($ca)) {
        $opt[CURLOPT_CAINFO] = $ca;
    }

    curl_setopt_array($ch, $opt);
    $svar = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fejl = curl_error($ch);
    curl_close($ch);

    // Et stykke af svaret kommer med i fejlen — er det en blokeringsside,
    // står det der.
    $uddrag = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)$svar)) ?? '', 0, 120));
    $uddrag = $uddrag !== '' ? ' (' . $uddrag . ')' : '';

    if ($svar === false || $status !== 200) {
        return ['ok' => false, 'navne' => [], 'fejl' => 'OIS svarede ' . ($status ?: $fejl) . $uddrag];
    }

    $data = json_decode((string)$svar, true);

    if (!is_array($data) || !isset($data['ejerdata']) || !is_array($data['ejerdata'])) {
        return ['ok' => false, 'navne' => [], 'fejl' => 'OIS svarede noget uventet' . $uddrag];
    }

    $navne = [];

    foreach ($data['ejerdata'] as $ejer) {
        $navn = trim((string)($ejer['name'] ?? ''));

        // Har en ejer navne- og adressebeskyttelse, står der en
        // forklaring i stedet for navnet. Den er ikke et navn.
        if ($navn === '' || stripos($navn, 'forbeholdt') !== false || stripos($navn, 'beskyttet') !== false) {
            continue;
        }

        $navne[] = $navn;
    }

    if ($navne === []) {
        return ['ok' => false, 'navne' => [], 'fejl' => 'OIS viser ingen ejernavne'];
    }

    return ['ok' => true, 'navne' => $navne, 'fejl' => ''];
}

/** Et navn som ord til sammenligning: "Søren Ø. Hansen" → ['soren', 'o', 'hansen']. */
function ejertjek_ord(string $navn): array
{
    $navn = mb_strtolower(trim($navn));
    $navn = strtr($navn, [
        'æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'u', 'é' => 'e',
    ]);
    $navn = preg_replace('/[^a-z0-9]+/', ' ', $navn) ?? '';

    return array_values(array_filter(explode(' ', $navn), 'strlen'));
}

/**
 * Passer ét navn fra OIS på et navn i kartoteket?
 *
 * Ja, hvis de er ens, eller hvis OIS-navnets fornavn og efternavn begge
 * står i kartotekets navn. Så tæller mellemnavne ikke med, og
 * "Dorte S. og Torben H. Pedersen" passer på både Dorte og Torben
 * Pedersen hos OIS.
 */
function ejertjek_navn_passer(string $ois, string $kartotek): bool
{
    $o = ejertjek_ord($ois);
    $k = ejertjek_ord($kartotek);

    if ($o === [] || $k === []) {
        return false;
    }

    if ($o === $k) {
        return true;
    }

    if (count($o) < 2) {
        return false;
    }

    return in_array($o[0], $k, true) && in_array($o[count($o) - 1], $k, true);
}

/** Alle gemte tjek, efter husstandens nøgle. */
function ejertjek_alle(PDO $pdo): array
{
    $ud = [];

    foreach ($pdo->query('SELECT * FROM ejertjek')->fetchAll() as $r) {
        $ud[$r['noegle']] = $r;
    }

    return $ud;
}

/** BFE-nummeret på en husstand, eller tom streng. */
function ejertjek_bfe(array $hus): string
{
    foreach ($hus['personer'] as $p) {
        $bfe = bfe_for_raekke($p);

        if ($bfe !== '') {
            return $bfe;
        }
    }

    return bfe_for_raekke(['sommerhusadresse' => $hus['adresse']]);
}

/**
 * Farven på Ejer-knappen.
 *
 * 'ok'     mindst én fra kartoteket står som ejer hos OIS (grøn)
 * 'nej'    ingen af dem står der (rød)
 * 'ukendt' ikke tjekket endnu, tjekket på et andet BFE-nummer, eller OIS
 *          kunne ikke svare — det er ikke det samme som forkert
 *
 * Returnerer ['farve' => ..., 'tekst' => forklaringen til musen].
 */
function ejertjek_status(array $hus, ?array $tjek, string $bfe): array
{
    if ($tjek === null || $tjek['bfe'] !== $bfe) {
        return ['farve' => 'ukendt', 'tekst' => 'Ejeren er ikke tjekket mod OIS endnu.'];
    }

    $dato = date('d-m-Y', strtotime((string)$tjek['tjekket_at']));

    if ($tjek['fejl'] !== '') {
        return ['farve' => 'ukendt', 'tekst' => 'Kunne ikke tjekkes ' . $dato . ': ' . $tjek['fejl'] . '.'];
    }

    $navne = json_decode((string)$tjek['ois_navne'], true) ?: [];
    $ejere = 'Ejere hos OIS ' . $dato . ': ' . implode(', ', $navne) . '.';

    foreach ($navne as $ois) {
        foreach ($hus['personer'] as $p) {
            if (ejertjek_navn_passer($ois, (string)$p['kontaktperson'])) {
                return ['farve' => 'ok', 'tekst' => $ejere];
            }
        }
    }

    return ['farve' => 'nej', 'tekst' => $ejere . ' Ingen af dem står i kartoteket på adressen.'];
}

/**
 * Tjekker de husstande, hvis tjek er for gammelt — højst $max ad gangen.
 *
 * Returnerer ['tjekket' => int, 'fejl' => int, 'mangler' => int], hvor
 * mangler er dem, der stadig venter efter denne omgang.
 */
function ejertjek_koer(PDO $pdo, int $max = EJERTJEK_AD_GANGEN): array
{
    $tjek = ejertjek_alle($pdo);
    $graense = time() - EJERTJEK_DAGE * 86400;
    $kø = [];

    foreach (admin_husstande($pdo) as $noegle => $hus) {
        $bfe = ejertjek_bfe($hus);

        if ($bfe === '') {
            continue;
        }

        $t = $tjek[$noegle] ?? null;
        $gammel = $t === null
            || $t['bfe'] !== $bfe
            || strtotime((string)$t['tjekket_at']) < $graense
            // Et fejlet opslag prøves igen efter en time, ikke først om en måned.
            || ($t['fejl'] !== '' && strtotime((string)$t['tjekket_at']) < time() - 3600);

        if ($gammel) {
            $kø[$noegle] = ['adresse' => $hus['adresse'], 'bfe' => $bfe];
        }
    }

    $gem = $pdo->prepare(
        'INSERT INTO ejertjek (noegle, adresse, bfe, ois_navne, fejl, tjekket_at)
         VALUES (:n, :a, :b, :o, :f, NOW())
         ON DUPLICATE KEY UPDATE adresse = VALUES(adresse), bfe = VALUES(bfe),
           ois_navne = VALUES(ois_navne), fejl = VALUES(fejl), tjekket_at = NOW()'
    );

    $tjekket = 0;
    $sidsteFejl = '';
    $fejlede = 0;

    foreach (array_slice($kø, 0, $max, true) as $noegle => $h) {
        $svar = ejertjek_hent_ois($h['bfe']);

        $gem->execute([
            ':n' => $noegle,
            ':a' => $h['adresse'],
            ':b' => $h['bfe'],
            ':o' => json_encode($svar['navne'], JSON_UNESCAPED_UNICODE),
            ':f' => $svar['fejl'],
        ]);

        $tjekket++;

        if (!$svar['ok']) {
            $fejlede++;
            $sidsteFejl = $svar['fejl'];
        }

        // Pænt over for OIS: ikke flere opslag i sekundet.
        usleep(300000);
    }

    // sidste_fejl er OIS' svar eller curls fejltekst — aldrig et navn.
    return ['tjekket' => $tjekket, 'fejl' => $fejlede, 'mangler' => max(0, count($kø) - $tjekket), 'sidste_fejl' => $sidsteFejl];
}

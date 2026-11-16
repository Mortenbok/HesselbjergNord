<?php
/**
 * Henter BFE-numrene for foreningens adresser og skriver dem i
 * public_html/includes/bfe.php.
 *
 * KØRES I HÅNDEN — ikke af hjemmesiden. Derfor ligger den her og ikke
 * under public_html. Siden skal ikke spørge Datafordeleren, hver gang
 * bestyrelsen åbner kartoteket: numrene er faste, så de bliver hentet én
 * gang og lagt i koden.
 *
 *   php tools/bfe/hent-bfe.php            alle adresser
 *   php tools/bfe/hent-bfe.php "Klitrosevej 18" "Ahornvænget 1"
 *
 * KÆDEN er tre led, fordi der ikke findes ét opslag fra adresse til BFE:
 *   1. adressevaelger.dk   adresse          -> husnummer-UUID   (gratis)
 *   2. BBR_Bygning         husnummer-UUID   -> jordstykke-id    (API-nøgle)
 *   3. MAT_Jordstykke      jordstykke-id    -> BFE-nummer       (API-nøgle)
 *
 * Nøglen står i public_html/includes/config.local.php, som holdes uden for
 * git. Den bruges KUN her; serveren har den ikke og behøver den ikke.
 *
 * Svarene gemmes i tools/bfe/cache.json, så en afbrudt kørsel kan tages
 * forfra uden at spørge om det samme igen.
 */

const ROD = __DIR__ . '/../..';

require_once ROD . '/public_html/includes/adresser.php';
require_once ROD . '/public_html/includes/husstande.php';

const TID = '2026-11-16T00:00:00Z';
const FLEX = 'https://graphql.datafordeler.dk/flexible/v3';
const AV = 'https://adressevaelger.dk';
const AV_TOKEN = 'adressevaelger123';   // den offentlige demonøgle, adressevaelger.dk selv bruger

$config = require ROD . '/public_html/includes/config.local.php';
$noegle = $config['datafordeler_apikey'] ?? '';

if ($noegle === '') {
    exit("Der er ingen datafordeler_apikey i config.local.php.\n");
}

/**
 * Kalder curl.exe i stedet for PHP's egen curl.
 *
 * PHP's curl bruger OpenSSL og falder over den selvsignerede rod, som en
 * TLS-inspicerende proxy lægger ind på arbejdsmaskiner. curl.exe bruger
 * Windows' certifikatlager og kender roden.
 *
 * Kaldet skrives i en curl-konfigurationsfil i stedet for på kommandolinjen.
 * escapeshellarg() erstatter nemlig % med mellemrum på Windows, og så er
 * "Ahornv%C3%A6nget" pludselig "Ahornv C3 A6nget". Gennem filen rører
 * hverken adressen eller JSON'en skallen.
 */
function hent(string $url, ?string $json = null): ?array
{
    // Skråstreg fremad hele vejen: inde i en curl-konfigurationsfil er \
    // et undvigetegn, så "C:\Users\..." får curl til at afvise hele filen.
    $praefiks = str_replace('\\', '/', sys_get_temp_dir()) . '/df-' . getmypid();
    $konf = $praefiks . '.conf';

    $linjer = [
        'url = "' . $url . '"',
        'silent',
        'max-time = 30',
    ];

    if ($json !== null) {
        $krop = $praefiks . '-krop.json';
        file_put_contents($krop, $json);
        $linjer[] = 'request = "POST"';
        $linjer[] = 'header = "Content-Type: application/json"';
        $linjer[] = 'data = "@' . $krop . '"';
    }

    file_put_contents($konf, implode("\n", $linjer) . "\n");

    $svar = shell_exec('curl --config ' . escapeshellarg($konf));

    return $svar === null ? null : json_decode($svar, true);
}

/** GraphQL mod flexible/v3. Prøver igen, så længe nøglen svarer 401. */
function graphql(string $query): ?array
{
    global $noegle;

    for ($i = 0; $i < 20; $i++) {
        $svar = hent(FLEX . '?apikey=' . rawurlencode($noegle), json_encode(['query' => $query]));

        if (is_array($svar) && isset($svar['data'])) {
            return $svar['data'];
        }

        // 401 kommer som en fejlkrop uden data — prøv igen efter et øjeblik.
        usleep(300000);
    }

    return null;
}

/** 1. Adressen slås op hos adressevaelger.dk og giver husnummerets UUID. */
function husnummer_uuid(string $vej, string $nr): ?string
{
    $url = AV . '/husnumre/soeg?token=' . AV_TOKEN
        . '&vejnavn=' . rawurlencode($vej)
        . '&husnummer=' . rawurlencode($nr)
        . '&postnummer=' . HBN_POSTNR;

    $svar = hent($url);

    // Søgesvaret kalder feltet "id". Det er detaljesvaret på
    // /husnumre/{id}, der staver det id_lokalid.
    return $svar['fund'][0]['id'] ?? null;
}

/**
 * 2a. Grunden på husnummeret peger på den bestemte faste ejendom.
 *
 * Det er hovedvejen: en grund findes også, når der ikke er bygget noget,
 * og det er netop de ubebyggede sommerhusgrunde, bygningsvejen taber.
 */
function grund_ejendom_uuid(string $uuid): ?string
{
    $q = '{ BBR_Grund(registreringstid: "' . TID . '", virkningstid: "' . TID . '",
            first: 10, where: { husnummer: { eq: "' . $uuid . '" } })
          { nodes { bestemtFastEjendom } } }';

    $data = graphql($q);

    foreach ($data['BBR_Grund']['nodes'] ?? [] as $n) {
        if (!empty($n['bestemtFastEjendom'])) {
            return (string)$n['bestemtFastEjendom'];
        }
    }

    return null;
}

/** 2b. Ejendomsrelationen oversætter ejendommens UUID til BFE-nummeret. */
function bfe_af_ejendom(string $ejendomUuid): ?string
{
    $q = '{ BBR_Ejendomsrelation(registreringstid: "' . TID . '", virkningstid: "' . TID . '",
            first: 3, where: { id_lokalId: { eq: "' . $ejendomUuid . '" } })
          { nodes { bfeNummer } } }';

    $data = graphql($q);

    foreach ($data['BBR_Ejendomsrelation']['nodes'] ?? [] as $n) {
        if (!empty($n['bfeNummer'])) {
            return (string)$n['bfeNummer'];
        }
    }

    return null;
}

/** 3. Bygningerne på husnummeret peger på det jordstykke, huset ligger på. */
function jordstykke_id(string $uuid): ?string
{
    $q = '{ BBR_Bygning(registreringstid: "' . TID . '", virkningstid: "' . TID . '",
            first: 20, where: { husnummer: { eq: "' . $uuid . '" } })
          { nodes { jordstykke } } }';

    $data = graphql($q);

    foreach ($data['BBR_Bygning']['nodes'] ?? [] as $n) {
        if (!empty($n['jordstykke'])) {
            return (string)$n['jordstykke'];
        }
    }

    return null;
}

/** 3. Jordstykket hører til en samlet fast ejendom — og det er BFE-nummeret. */
function bfe_af_jordstykke(string $jordstykke): ?array
{
    $q = '{ MAT_Jordstykke(registreringstid: "' . TID . '", virkningstid: "' . TID . '",
            first: 2, where: { id_lokalId: { eq: "' . $jordstykke . '" } })
          { nodes { samletFastEjendomLokalId matrikelnummer ejerlavLokalId } } }';

    $data = graphql($q);
    $n = $data['MAT_Jordstykke']['nodes'][0] ?? null;

    return $n && !empty($n['samletFastEjendomLokalId']) ? $n : null;
}

/* ------------------------------------------------------------------ */

/**
 * Adresser, der står i beboerkartoteket uden at ligge på en af
 * vedtægternes 20 veje. De skal med, for medlemmerne er der — se punktet
 * om Ristingevej i TODO.md.
 */
const BFE_EKSTRA = [
    'Ristingevej 121',
    'Ristingevej 123',
];

$adresser = array_slice($argv, 1) ?: array_merge(HBN_HUSSTANDE, BFE_EKSTRA);

$cachefil = __DIR__ . '/cache.json';
$cache = is_readable($cachefil) ? json_decode(file_get_contents($cachefil), true) : [];

$fundet = 0;
$sprunget = [];
$uenige = [];

foreach ($adresser as $i => $adresse) {
    // Ligger adressen på en af foreningens veje, bruges den rettede
    // stavemåde. Ellers tages vejnavnet, som det står — Ristingevej er
    // ikke i vedtægterne, men husene skal stadig kunne slås op.
    $noegleAdr = adresse_noegle_af_fritekst($adresse) ?: adresse_noegle_fri($adresse);

    if ($noegleAdr === '') {
        printf("%3d/%d  %-26s KUNNE IKKE TYDES\n", $i + 1, count($adresser), $adresse);
        $sprunget[] = $adresse . ' (kunne ikke tydes)';
        continue;
    }

    if (isset($cache[$noegleAdr]['bfe'])) {
        printf("%3d/%d  %-26s %s  (fra cache)\n", $i + 1, count($adresser), $adresse, $cache[$noegleAdr]['bfe']);
        $fundet++;
        continue;
    }

    preg_match('/^(.*?)[\s,]*(\d{1,3}\s*[a-zA-ZæøåÆØÅ]?)$/u', $adresse, $m);
    $vej = adresse_find_vej($m[1]) ?? trim($m[1]);
    $nr = adresse_husnummer($m[2]);

    $uuid = husnummer_uuid($vej, $nr);

    if ($uuid === null) {
        printf("%3d/%d  %-26s ingen adresse i DAR\n", $i + 1, count($adresser), $adresse);
        $sprunget[] = $adresse . ' (ukendt i DAR)';
        continue;
    }

    // Hovedvej: grund -> ejendom -> BFE.
    $ejendom = grund_ejendom_uuid($uuid);
    $bfe = $ejendom === null ? null : bfe_af_ejendom($ejendom);

    // Krydstjek ad en helt anden vej: bygning -> jordstykke -> ejendom.
    // De to veje går gennem hver sit register, så er de enige, er tallet
    // rigtigt. Er de uenige, skal det undersøges i hånden.
    $jord = jordstykke_id($uuid);
    $mat = $jord === null ? null : bfe_af_jordstykke($jord);
    $bfe2 = $mat === null ? null : (string)$mat['samletFastEjendomLokalId'];

    if ($bfe === null && $bfe2 === null) {
        printf("%3d/%d  %-26s hverken grund eller bygning\n", $i + 1, count($adresser), $adresse);
        $sprunget[] = $adresse . ' (hverken grund eller bygning i BBR)';
        continue;
    }

    if ($bfe !== null && $bfe2 !== null && $bfe !== $bfe2) {
        printf("%3d/%d  %-26s UENIGE: grund %s mod matrikel %s\n",
            $i + 1, count($adresser), $adresse, $bfe, $bfe2);
        $uenige[] = $adresse . " (grund $bfe, matrikel $bfe2)";
        continue;
    }

    $cache[$noegleAdr] = [
        'adresse' => adresse_saml($vej, $nr),
        'bfe' => $bfe ?? $bfe2,
        'matrikel' => $mat ? $mat['matrikelnummer'] . ' ' . $mat['ejerlavLokalId'] : '',
        'bekraeftet' => $bfe !== null && $bfe2 !== null,
    ];

    file_put_contents($cachefil, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    printf("%3d/%d  %-26s %s  %s\n", $i + 1, count($adresser), $adresse,
        $cache[$noegleAdr]['bfe'],
        $cache[$noegleAdr]['bekraeftet'] ? 'bekræftet ad to veje' : '(kun én vej)');
    $fundet++;
}

echo "\n$fundet af " . count($adresser) . " adresser har et BFE-nummer.\n";

$kunEn = array_filter($cache, static fn($r) => empty($r['bekraeftet']));

if ($kunEn) {
    echo count($kunEn) . " af dem er kun fundet ad én vej:\n";
    foreach ($kunEn as $r) { echo "  - " . $r['adresse'] . " = " . $r['bfe'] . "\n"; }
}

if ($uenige) {
    echo "\nUENIGE — skal slås op i hånden:\n";
    foreach ($uenige as $u) { echo "  - $u\n"; }
}

if ($sprunget) {
    echo "\nSprunget over:\n";
    foreach ($sprunget as $s) { echo "  - $s\n"; }
}

echo "\nSkriv filen med:  php tools/bfe/skriv-bfe.php\n";

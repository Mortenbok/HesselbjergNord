<?php
/**
 * Skriver public_html/includes/bfe.php ud fra tools/bfe/cache.json.
 *
 * Køres efter hent-bfe.php. Filen, den laver, er den eneste, serveren
 * bruger — der ligger hverken nøgle eller opslag i driften.
 */

const ROD = __DIR__ . '/../..';

$cachefil = __DIR__ . '/cache.json';

if (!is_readable($cachefil)) {
    exit("Der er ingen cache.json. Kør først: php tools/bfe/hent-bfe.php\n");
}

$cache = json_decode(file_get_contents($cachefil), true);

if (!is_array($cache) || !$cache) {
    exit("cache.json er tom.\n");
}

// Sorteres på adressen, så filen er til at læse og rettelser giver en
// lille diff i stedet for at flytte rundt på det hele.
uasort($cache, static fn($a, $b) => strcoll($a['adresse'], $b['adresse']));

$linjer = [];
$bredde = 0;

foreach ($cache as $noegle => $r) {
    $bredde = max($bredde, strlen($noegle) + 2);
}

foreach ($cache as $noegle => $r) {
    // Numre, BBR ikke kunne føre os til, er slået op i hånden. Det skal
    // kunne ses i filen, så ingen tror, de er hentet som resten.
    $note = !empty($r['haandholdt']) ? ' (slået op i hånden)' : '';

    $linjer[] = sprintf(
        "    %-{$bredde}s => '%s',   // %s%s",
        "'" . $noegle . "'",
        $r['bfe'],
        $r['adresse'],
        $note
    );
}

$antal = count($linjer);
$dato = date('j. n. Y');

$fil = <<<PHP
<?php
/**
 * BFE-numre — nøglen til at slå ejeren op på en adresse.
 *
 * BFE står for Bestemt Fast Ejendom og er ejendommens nummer i
 * ejendomsregistret. Det er det eneste, der kan dannes et direkte link til
 * ejeroplysningerne ud af: nyt.ois.dk/search/{nummer}.
 *
 * FILEN ER LAVET AF ET VÆRKTØJ — ret den ikke i hånden.
 * Numrene er hentet $dato hos Datafordeleren med:
 *
 *     php tools/bfe/hent-bfe.php      henter numrene
 *     php tools/bfe/skriv-bfe.php     skriver denne fil
 *
 * Det sker herfra og ikke fra hjemmesiden med vilje: BFE-numre er faste,
 * så siden skal ikke spørge Datafordeleren, hver gang bestyrelsen åbner
 * kartoteket. Så er der ingen API-nøgle på serveren, ingen ventetid, og
 * siden virker også den dag, Datafordeleren er nede.
 *
 * Kommer der en adresse til, så tilføj den i includes/husstande.php og kør
 * de to kommandoer igen.
 *
 * Bestyrelsen kan desuden skrive et nummer i feltet "BFE-nummer" på
 * beboer-ret.php. Står det begge steder, er det databasen, der gælder.
 */

require_once __DIR__ . '/adresser.php';

/** Kendte BFE-numre. Nøgle: adresse_noegle_af_fritekst('Vej nr'). */
const HBN_BFE = [
%s
];

/**
 * Det BFE-nummer, vi kender til adressen. Tom streng, hvis vi ikke har et.
 *
 * Adressen må gerne være skrevet i fri tekst. Først prøves den rettede
 * stavemåde, så både "Hyldeskovvænget 4" og "Hylleskovvænget 4" rammer
 * den samme ejendom. Ligger adressen ikke på en af vedtægternes veje,
 * bruges vejnavnet, som det står — Ristingevej 121 og 123 står i
 * kartoteket uden at være blandt de 20 veje.
 */
function bfe_kendt(string \$adresse): string
{
    foreach ([adresse_noegle_af_fritekst(\$adresse), adresse_noegle_fri(\$adresse)] as \$noegle) {
        if (\$noegle !== '' && isset(HBN_BFE[\$noegle])) {
            return HBN_BFE[\$noegle];
        }
    }

    return '';
}

/**
 * Nummeret for en række i beboerkartoteket.
 *
 * Det, bestyrelsen selv har skrevet i databasen, vinder over listen
 * herover — ellers kunne en rettelse i kartoteket ikke slå igennem.
 */
function bfe_for_raekke(array \$raekke): string
{
    \$gemt = trim((string)(\$raekke['bfe'] ?? ''));

    if (\$gemt !== '') {
        return \$gemt;
    }

    return bfe_kendt((string)(\$raekke['sommerhusadresse'] ?? ''));
}

/** Adressen på ejeroplysningerne i OIS. */
function bfe_link(string \$bfe): string
{
    return 'https://nyt.ois.dk/search/' . rawurlencode(\$bfe);
}
PHP;

$ud = sprintf($fil, implode("\n", $linjer)) . "\n";

file_put_contents(ROD . '/public_html/includes/bfe.php', $ud);

echo "Skrevet: public_html/includes/bfe.php med $antal adresser.\n";

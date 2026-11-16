<?php
/**
 * BFE-numre — nøglen til at slå ejeren op på en adresse.
 *
 * BFE står for Bestemt Fast Ejendom og er ejendommens nummer i
 * ejendomsregistret. Det er det eneste, der kan dannes et direkte link ud
 * af: nyt.ois.dk/search/{nummer} viser ejerne af ejendommen.
 *
 * Der findes ikke et frit opslag fra adresse til nummer — boligejer.dk og
 * OIS bruger begge en søgetjeneste, der kræver en nøgle hos Datafordeleren.
 * Derfor står numrene her, efterhånden som de bliver slået op i hånden.
 *
 * SÅDAN TILFØJER DU ET NUMMER
 *   1. Slå sommerhusadressen op på boligejer.dk og klik videre til OIS.
 *   2. Tallet til sidst i adressen er BFE-nummeret:
 *      nyt.ois.dk/search/3073473
 *   3. Skriv en linje herunder. Nøglen er vejnavnet uden æ/ø/å og uden
 *      mellemrum, efterfulgt af husnummeret — se adresse_noegle().
 *
 * Bestyrelsen kan også skrive nummeret i feltet "BFE-nummer" på
 * beboer-ret.php. Står det begge steder, er det databasen, der gælder.
 */

require_once __DIR__ . '/adresser.php';

/** Kendte BFE-numre. Nøgle: adresse_noegle_af_fritekst('Vej nr'). */
const HBN_BFE = [
    'ahornvanget1' => '3073473',   // Ahornvænget 1
    'klitrosevej18' => '9856478',  // Klitrosevej 18
];

/**
 * Det BFE-nummer, vi kender til adressen. Tom streng, hvis vi ikke har et.
 *
 * Adressen må gerne være skrevet i fri tekst — nøglen foldes, så både
 * "Hyldeskovvænget 4" og "Hylleskovvænget 4" rammer den samme ejendom.
 */
function bfe_kendt(string $adresse): string
{
    $noegle = adresse_noegle_af_fritekst($adresse);

    return $noegle === '' ? '' : (HBN_BFE[$noegle] ?? '');
}

/**
 * Nummeret for en række i beboerkartoteket.
 *
 * Det, bestyrelsen selv har skrevet i databasen, vinder over listen
 * herover — ellers kunne en rettelse i kartoteket ikke slå igennem.
 */
function bfe_for_raekke(array $raekke): string
{
    $gemt = trim((string)($raekke['bfe'] ?? ''));

    if ($gemt !== '') {
        return $gemt;
    }

    return bfe_kendt((string)($raekke['sommerhusadresse'] ?? ''));
}

/** Adressen på ejeroplysningerne i OIS. */
function bfe_link(string $bfe): string
{
    return 'https://nyt.ois.dk/search/' . rawurlencode($bfe);
}

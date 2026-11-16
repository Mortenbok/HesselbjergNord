<?php
/**
 * BFE-numre — nøglen til at slå ejeren op på en adresse.
 *
 * BFE står for Bestemt Fast Ejendom og er ejendommens nummer i
 * ejendomsregistret. Det er det eneste, der kan dannes et direkte link til
 * ejeroplysningerne ud af: nyt.ois.dk/search/{nummer}.
 *
 * FILEN ER LAVET AF ET VÆRKTØJ — ret den ikke i hånden.
 * Numrene er hentet 16. 11. 2026 hos Datafordeleren med:
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
    'ahornvanget1'      => '3073473',   // Ahornvænget 1
    'ahornvanget2'      => '3073474',   // Ahornvænget 2
    'ahornvanget3'      => '3073472',   // Ahornvænget 3
    'ahornvanget4'      => '3073475',   // Ahornvænget 4
    'ahornvanget5'      => '3073471',   // Ahornvænget 5
    'ahornvanget6'      => '3073476',   // Ahornvænget 6
    'ahornvanget7'      => '3073470',   // Ahornvænget 7
    'ahornvanget8'      => '3073477',   // Ahornvænget 8
    'askevanget1'       => '3073861',   // Askevænget 1
    'askevanget2'       => '3073864',   // Askevænget 2
    'askevanget3'       => '3073862',   // Askevænget 3
    'askevanget4'       => '3073863',   // Askevænget 4
    'birkevanget1'      => '3073497',   // Birkevænget 1
    'birkevanget10'     => '3073502',   // Birkevænget 10
    'birkevanget2'      => '3073506',   // Birkevænget 2
    'birkevanget3'      => '3073498',   // Birkevænget 3
    'birkevanget4'      => '3073505',   // Birkevænget 4
    'birkevanget5'      => '3073499',   // Birkevænget 5
    'birkevanget6'      => '3073504',   // Birkevænget 6
    'birkevanget7'      => '3073500',   // Birkevænget 7
    'birkevanget8'      => '3073503',   // Birkevænget 8
    'birkevanget9'      => '3073501',   // Birkevænget 9
    'blabarvanget1'     => '9981102',   // Blåbærvænget 1
    'blabarvanget10'    => '9981104',   // Blåbærvænget 10
    'blabarvanget2'     => '9981108',   // Blåbærvænget 2
    'blabarvanget3'     => '9981107',   // Blåbærvænget 3
    'blabarvanget4'     => '9981109',   // Blåbærvænget 4
    'blabarvanget5'     => '9981106',   // Blåbærvænget 5
    'blabarvanget6'     => '9981110',   // Blåbærvænget 6
    'blabarvanget7'     => '9981103',   // Blåbærvænget 7
    'blabarvanget8'     => '9981105',   // Blåbærvænget 8
    'duevanget1'        => '3073449',   // Duevænget 1
    'duevanget10'       => '3073459',   // Duevænget 10
    'duevanget11'       => '3073454',   // Duevænget 11
    'duevanget12'       => '3073458',   // Duevænget 12
    'duevanget13'       => '3073455',   // Duevænget 13
    'duevanget14'       => '3073457',   // Duevænget 14
    'duevanget15'       => '3073456',   // Duevænget 15
    'duevanget2'        => '3073463',   // Duevænget 2
    'duevanget3'        => '3073450',   // Duevænget 3
    'duevanget4'        => '3073462',   // Duevænget 4
    'duevanget5'        => '3073451',   // Duevænget 5
    'duevanget6'        => '3073461',   // Duevænget 6
    'duevanget7'        => '3073452',   // Duevænget 7
    'duevanget8'        => '3073460',   // Duevænget 8
    'duevanget9'        => '3073453',   // Duevænget 9
    'elmevanget1'       => '3073865',   // Elmevænget 1
    'elmevanget2'       => '3073871',   // Elmevænget 2
    'elmevanget3'       => '3073866',   // Elmevænget 3
    'elmevanget4'       => '3073870',   // Elmevænget 4
    'elmevanget5'       => '3073867',   // Elmevænget 5
    'elmevanget6'       => '3073869',   // Elmevænget 6
    'elmevanget8'       => '3073868',   // Elmevænget 8
    'enebarvanget1'     => '9981139',   // Enebærvænget 1
    'enebarvanget10'    => '9981097',   // Enebærvænget 10
    'enebarvanget2'     => '9981101',   // Enebærvænget 2
    'enebarvanget3'     => '9981138',   // Enebærvænget 3
    'enebarvanget4'     => '9981100',   // Enebærvænget 4
    'enebarvanget5'     => '9981137',   // Enebærvænget 5
    'enebarvanget6'     => '9981099',   // Enebærvænget 6
    'enebarvanget7'     => '9981095',   // Enebærvænget 7
    'enebarvanget8'     => '9981098',   // Enebærvænget 8
    'enebarvanget9'     => '9981096',   // Enebærvænget 9
    'fyrrevanget1'      => '3073434',   // Fyrrevænget 1
    'fyrrevanget10'     => '3073429',   // Fyrrevænget 10
    'fyrrevanget11'     => '3073582',   // Fyrrevænget 11
    'fyrrevanget13'     => '3073570',   // Fyrrevænget 13
    'fyrrevanget15'     => '3073569',   // Fyrrevænget 15
    'fyrrevanget1a'     => '3073428',   // Fyrrevænget 1A (slået op i hånden)
    'fyrrevanget2'      => '3073433',   // Fyrrevænget 2
    'fyrrevanget3'      => '3073435',   // Fyrrevænget 3
    'fyrrevanget4'      => '3073432',   // Fyrrevænget 4
    'fyrrevanget5'      => '3073436',   // Fyrrevænget 5
    'fyrrevanget6'      => '3073431',   // Fyrrevænget 6
    'fyrrevanget7'      => '3073437',   // Fyrrevænget 7
    'fyrrevanget8'      => '3073430',   // Fyrrevænget 8
    'fyrrevanget9'      => '3073438',   // Fyrrevænget 9
    'granvanget1'       => '3073519',   // Granvænget 1
    'granvanget10'      => '3073528',   // Granvænget 10
    'granvanget2'       => '3073524',   // Granvænget 2
    'granvanget3'       => '3073520',   // Granvænget 3
    'granvanget4'       => '3073525',   // Granvænget 4
    'granvanget5'       => '3073521',   // Granvænget 5
    'granvanget6'       => '3073526',   // Granvænget 6
    'granvanget7'       => '3073522',   // Granvænget 7
    'granvanget8'       => '3073527',   // Granvænget 8
    'granvanget9'       => '3073523',   // Granvænget 9
    'gulspurvevanget1'  => '3073555',   // Gulspurvevænget 1
    'gulspurvevanget10' => '3073549',   // Gulspurvevænget 10
    'gulspurvevanget11' => '3073550',   // Gulspurvevænget 11
    'gulspurvevanget2'  => '3073545',   // Gulspurvevænget 2
    'gulspurvevanget3'  => '3073554',   // Gulspurvevænget 3
    'gulspurvevanget4'  => '3073546',   // Gulspurvevænget 4
    'gulspurvevanget5'  => '3073553',   // Gulspurvevænget 5
    'gulspurvevanget6'  => '3073547',   // Gulspurvevænget 6
    'gulspurvevanget7'  => '3073552',   // Gulspurvevænget 7
    'gulspurvevanget8'  => '3073548',   // Gulspurvevænget 8
    'gulspurvevanget9'  => '3073551',   // Gulspurvevænget 9
    'hasselvanget1'     => '3073482',   // Hasselvænget 1
    'hasselvanget10'    => '3073390',   // Hasselvænget 10
    'hasselvanget2'     => '3073394',   // Hasselvænget 2
    'hasselvanget3'     => '3073481',   // Hasselvænget 3
    'hasselvanget4'     => '3073393',   // Hasselvænget 4
    'hasselvanget5'     => '3073480',   // Hasselvænget 5
    'hasselvanget6'     => '3073392',   // Hasselvænget 6
    'hasselvanget7'     => '3073479',   // Hasselvænget 7
    'hasselvanget8'     => '3073391',   // Hasselvænget 8
    'hasselvanget9'     => '3073478',   // Hasselvænget 9
    'hylleskovvanget1'  => '3073583',   // Hylleskovvænget 1
    'hylleskovvanget10' => '3073574',   // Hylleskovvænget 10
    'hylleskovvanget11' => '3073580',   // Hylleskovvænget 11
    'hylleskovvanget12' => '3073568',   // Hylleskovvænget 12
    'hylleskovvanget13' => '3073579',   // Hylleskovvænget 13
    'hylleskovvanget14' => '3073573',   // Hylleskovvænget 14
    'hylleskovvanget15' => '3073578',   // Hylleskovvænget 15
    'hylleskovvanget16' => '3073567',   // Hylleskovvænget 16
    'hylleskovvanget17' => '3073577',   // Hylleskovvænget 17
    'hylleskovvanget2'  => '3073572',   // Hylleskovvænget 2
    'hylleskovvanget3'  => '3073584',   // Hylleskovvænget 3
    'hylleskovvanget4'  => '3073576',   // Hylleskovvænget 4
    'hylleskovvanget5'  => '3073585',   // Hylleskovvænget 5
    'hylleskovvanget6'  => '3073575',   // Hylleskovvænget 6
    'hylleskovvanget7'  => '3073586',   // Hylleskovvænget 7
    'hylleskovvanget8'  => '3073571',   // Hylleskovvænget 8
    'hylleskovvanget9'  => '3073581',   // Hylleskovvænget 9
    'klitrosevej10'     => '3073375',   // Klitrosevej 10
    'klitrosevej14'     => '3073529',   // Klitrosevej 14
    'klitrosevej16'     => '3073530',   // Klitrosevej 16
    'klitrosevej18'     => '9856478',   // Klitrosevej 18
    'klitrosevej2'      => '3073373',   // Klitrosevej 2
    'klitrosevej4'      => '3073374',   // Klitrosevej 4
    'klitrosevej6'      => '3073371',   // Klitrosevej 6
    'klitrosevej8'      => '3073372',   // Klitrosevej 8
    'lindevanget10'     => '9981119',   // Lindevænget 10
    'lindevanget12'     => '9981118',   // Lindevænget 12
    'lindevanget14'     => '9981117',   // Lindevænget 14
    'lindevanget16'     => '9981116',   // Lindevænget 16
    'lindevanget18'     => '9981115',   // Lindevænget 18
    'lindevanget2'      => '9981123',   // Lindevænget 2
    'lindevanget20'     => '9981114',   // Lindevænget 20
    'lindevanget22'     => '9981113',   // Lindevænget 22
    'lindevanget24'     => '9981112',   // Lindevænget 24
    'lindevanget26'     => '9981111',   // Lindevænget 26
    'lindevanget4'      => '9981122',   // Lindevænget 4
    'lindevanget6'      => '9981121',   // Lindevænget 6
    'lindevanget8'      => '9981120',   // Lindevænget 8
    'musvitvanget1'     => '3073544',   // Musvitvænget 1
    'musvitvanget2'     => '3073556',   // Musvitvænget 2
    'musvitvanget3'     => '3073543',   // Musvitvænget 3
    'musvitvanget4'     => '3073557',   // Musvitvænget 4
    'musvitvanget5'     => '3073542',   // Musvitvænget 5
    'musvitvanget6'     => '3073558',   // Musvitvænget 6
    'musvitvanget7'     => '3073541',   // Musvitvænget 7
    'musvitvanget8'     => '3073559',   // Musvitvænget 8
    'ristingevej121'    => '10067097',   // Ristingevej 121
    'ristingevej123'    => '3073368',   // Ristingevej 123
    'rosenvanget1'      => '3073379',   // Rosenvænget 1
    'rosenvanget2'      => '3073370',   // Rosenvænget 2
    'rosenvanget3'      => '3073378',   // Rosenvænget 3
    'rosenvanget4'      => '3073376',   // Rosenvænget 4
    'rosenvanget500a'   => '3073380',   // Rosenvænget 500A
    'rosenvanget6'      => '3073377',   // Rosenvænget 6
    'ronnevanget1'      => '3073395',   // Rønnevænget 1
    'ronnevanget10'     => '3073401',   // Rønnevænget 10
    'ronnevanget12'     => '3073400',   // Rønnevænget 12
    'ronnevanget2'      => '3073405',   // Rønnevænget 2
    'ronnevanget3'      => '3073396',   // Rønnevænget 3
    'ronnevanget4'      => '3073404',   // Rønnevænget 4
    'ronnevanget5'      => '3073397',   // Rønnevænget 5
    'ronnevanget6'      => '3073403',   // Rønnevænget 6
    'ronnevanget7'      => '3073398',   // Rønnevænget 7
    'ronnevanget8'      => '3073402',   // Rønnevænget 8
    'ronnevanget9'      => '3073399',   // Rønnevænget 9
    'syrenvanget10'     => '9981132',   // Syrenvænget 10
    'syrenvanget12'     => '9981131',   // Syrenvænget 12
    'syrenvanget14'     => '9981130',   // Syrenvænget 14
    'syrenvanget16'     => '9981129',   // Syrenvænget 16
    'syrenvanget18'     => '9981128',   // Syrenvænget 18
    'syrenvanget2'      => '9981136',   // Syrenvænget 2
    'syrenvanget20'     => '9981127',   // Syrenvænget 20
    'syrenvanget22'     => '9981126',   // Syrenvænget 22
    'syrenvanget24'     => '9981125',   // Syrenvænget 24
    'syrenvanget26'     => '9981124',   // Syrenvænget 26
    'syrenvanget4'      => '9981135',   // Syrenvænget 4
    'syrenvanget6'      => '9981134',   // Syrenvænget 6
    'syrenvanget8'      => '9981133',   // Syrenvænget 8
    'tjornevanget1'     => '3073406',   // Tjørnevænget 1
    'tjornevanget10'    => '3073415',   // Tjørnevænget 10
    'tjornevanget11'    => '3073411',   // Tjørnevænget 11
    'tjornevanget12'    => '3073414',   // Tjørnevænget 12
    'tjornevanget13'    => '3073412',   // Tjørnevænget 13
    'tjornevanget14'    => '3073413',   // Tjørnevænget 14
    'tjornevanget2'     => '3073419',   // Tjørnevænget 2
    'tjornevanget3'     => '3073407',   // Tjørnevænget 3
    'tjornevanget4'     => '3073418',   // Tjørnevænget 4
    'tjornevanget5'     => '3073408',   // Tjørnevænget 5
    'tjornevanget6'     => '3073417',   // Tjørnevænget 6
    'tjornevanget7'     => '3073409',   // Tjørnevænget 7
    'tjornevanget8'     => '3073416',   // Tjørnevænget 8
    'tjornevanget9'     => '3073410',   // Tjørnevænget 9
    'ornevanget1'       => '3073464',   // Ørnevænget 1
    'ornevanget2'       => '3073448',   // Ørnevænget 2
    'ornevanget3'       => '3073465',   // Ørnevænget 3
    'ornevanget5'       => '3073466',   // Ørnevænget 5
    'ornevanget7'       => '3073467',   // Ørnevænget 7
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
function bfe_kendt(string $adresse): string
{
    foreach ([adresse_noegle_af_fritekst($adresse), adresse_noegle_fri($adresse)] as $noegle) {
        if ($noegle !== '' && isset(HBN_BFE[$noegle])) {
            return HBN_BFE[$noegle];
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

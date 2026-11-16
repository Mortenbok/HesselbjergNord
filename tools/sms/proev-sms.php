<?php
/**
 * Prøver SureSMS-opsætningen af.
 *
 *   php tools/sms/proev-sms.php                 login + kontoopslag, sender intet
 *   php tools/sms/proev-sms.php 12345678        sender ÉN rigtig SMS dertil
 *
 * Uden et nummer sker der ingenting ude i verden: værktøjet bytter nøglen
 * til et token og slår kontoen op. Det er nok til at vise, at nøglen
 * virker.
 *
 * Nøglen læses fra public_html/includes/config.local.php og bliver ALDRIG
 * skrevet ud — kun dens længde, så man kan se, at den er der.
 */

const ROD = __DIR__ . '/../..';

require_once ROD . '/public_html/includes/sms.php';

$config = sms_config();

function linje(string $navn, string $vaerdi): void
{
    printf("  %-20s %s\n", $navn, $vaerdi);
}

echo "SureSMS-opsætning\n", str_repeat('-', 56), "\n";

linje('API-nøgle', $config['suresms_apikey'] !== ''
    ? 'sat (' . strlen($config['suresms_apikey']) . ' tegn)' : 'MANGLER');
linje('Afsendernavn', $config['sms_sender']);

if (!sms_enabled()) {
    echo "\nLav en API-nøgle på https://app.suresms.com/UserApi/Index,\n";
    echo "skriv den som 'suresms_apikey' i public_html/includes/config.local.php,\n";
    echo "og kør igen.\n";
    exit(1);
}

echo "\n1. Bytter nøglen til et token\n";
$token = sms_token();

if ($token['fejl'] !== '') {
    echo '   FEJLEDE: ', $token['fejl'], "\n";

    if (stripos($token['fejl'], 'certificate') !== false) {
        echo "\n   Det ligner maskinens certifikatliste og ikke nøglen. Sæt\n";
        echo "   sms_ca_bundle i config.local.php, eller kør på serveren.\n";
    } else {
        echo "\n   Tjek, at nøglen er kopieret helt med fra\n";
        echo "   https://app.suresms.com/UserApi/Index.\n";
    }

    exit(1);
}

echo '   OK — token på ', strlen($token['token']), " tegn\n";

echo "\n2. Slår kontoen op\n";
$konto = sms_konto();

if (!$konto['ok']) {
    echo '   FEJLEDE: ', $konto['fejl'], "\n";
    exit(1);
}

foreach (['userName' => 'Bruger', 'authenticationType' => 'Login via'] as $felt => $navn) {
    if (!empty($konto['data'][$felt])) {
        linje($navn, (string)$konto['data'][$felt]);
    }
}
echo "   OK\n";

/* --- Nummeret, der prøves med --- */
if (!isset($argv[1])) {
    echo "\nNøglen virker. Der er ikke sendt nogen SMS.\n";
    echo "Vil du sende en rigtig test, så kør:\n";
    echo "  php tools/sms/proev-sms.php DIT_MOBILNUMMER\n";
    exit(0);
}

$nummer = sms_msisdn($argv[1]);

if ($nummer === null) {
    echo "\nNummeret '", $argv[1], "' kunne ikke tydes som et dansk mobilnummer.\n";
    exit(1);
}

$tekst = $argv[2] ?? 'Test fra Grundejerforeningen Hesselbjerg Nord.';
$laengde = sms_length($tekst);

echo "\n3. Sender én SMS\n";
linje('Til', $nummer);
linje('Tekst', $tekst);
linje('Længde', $laengde['units'] . ' tegn, ' . $laengde['parts'] . ' SMS');

$svar = sms_send([$nummer], $tekst);

if ($svar['ok'] < 1) {
    echo '   FEJLEDE: ', $svar['error'], "\n";
    exit(1);
}

echo "   OK — sendt.\n";
echo "\nKig på telefonen. Kommer den ikke frem, så tjek afsendernavnet:\n";
echo "højst 11 tegn, ingen mellemrum eller specialtegn. De godkendte navne\n";
echo "står under Sender-ID på din SureSMS-konto.\n";

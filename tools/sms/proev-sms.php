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

// Kontoopslaget er en ekstra oplysning, ikke en prøve. En API-nøgle må
// ikke nødvendigvis læse kontoen — den svarer 401 — og det forhindrer
// ikke, at der kan sendes. Tokenet ovenfor er beviset.
echo "\n2. Slår kontoen op (kun til oplysning)\n";
$konto = sms_konto();

if (!$konto['ok']) {
    echo '   springes over: ', $konto['fejl'], "\n";
    echo "   Det er i orden — nøglen må bare ikke læse kontooplysninger.\n";
} else {
    foreach (['userName' => 'Bruger', 'authenticationType' => 'Login via'] as $felt => $navn) {
        if (!empty($konto['data'][$felt])) {
            linje($navn, (string)$konto['data'][$felt]);
        }
    }
    echo "   OK\n";
}

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

echo "   taget imod\n";

/*
 * SureSMS siger ja med det samme og smider så måske beskeden væk bagefter
 * — fx hvis afsendernavnet ikke er godkendt. Den afvisning står KUN i
 * udbakken, så "taget imod" er ikke det samme som "kommet frem".
 */
echo "\n4. Tjekker, hvad der faktisk skete\n";
sleep(3);

$u = sms_udbakke();

if (!$u['ok']) {
    echo '   kunne ikke læse udbakken: ', $u['fejl'], "\n";
    echo "   Kig på telefonen i stedet.\n";
    exit(0);
}

$mine = array_filter($u['beskeder'], static fn($m) => str_ends_with((string)($m['toPhonenumber'] ?? ''), substr($nummer, 2)));
usort($mine, static fn($a, $b) => strcmp((string)($b['submittedDateTime'] ?? ''), (string)($a['submittedDateTime'] ?? '')));
$sidste = $mine[0] ?? null;

if ($sidste === null) {
    echo "   beskeden er ikke dukket op i udbakken endnu — prøv igen om lidt.\n";
    exit(0);
}

$status = (string)($sidste['statusDescription'] ?? 'ukendt');
linje('Status', $status);
linje('Afsender', (string)($sidste['isSentFrom'] ?? '(kontoens nummer)'));
linje('Pris', (string)($sidste['priceInDKK'] ?? '?') . ' DKK');

if (stripos($status, 'fail') !== false || stripos($status, 'unauthor') !== false) {
    echo "\n   BESKEDEN KOM IKKE FREM.\n";

    if (stripos($status, 'senderid') !== false) {
        echo "   Afsendernavnet er ikke godkendt på kontoen. Sæt sms_sender til\n";
        echo "   en tom streng i config.local.php — så bruges kontoens eget\n";
        echo "   nummer — eller få navnet godkendt under Sender-ID hos SureSMS.\n";
    }

    exit(1);
}

// "Delivered" er det eneste, der betyder fremme. "Message accepted" vil
// sige, at SureSMS har taget imod den og er på vej — det kan stadig gå
// galt hos teleselskabet.
if (stripos($status, 'deliver') !== false) {
    echo "\n   Beskeden er leveret.\n";
    exit(0);
}

echo "\n   Taget imod og på vej. Kør igen om et øjeblik for at se, om der\n";
echo "   kommer til at stå \"Delivered\".\n";

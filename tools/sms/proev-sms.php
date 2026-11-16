<?php
/**
 * Prøver SureSMS-opsætningen af.
 *
 *   php tools/sms/proev-sms.php                 login + pris, sender intet
 *   php tools/sms/proev-sms.php 12345678        sender ÉN rigtig SMS dertil
 *
 * Uden et nummer sker der ingenting ude i verden: værktøjet henter et
 * token og spørger, hvad en besked VILLE koste. Det er nok til at vise,
 * at Client Id og Secret Id virker, og at nummerformatet bliver godtaget.
 *
 * Nøglerne læses fra public_html/includes/config.local.php og bliver
 * ALDRIG skrevet ud — kun deres længde, så man kan se, at de er der.
 */

const ROD = __DIR__ . '/../..';

require_once ROD . '/public_html/includes/sms.php';

$config = sms_config();

function linje(string $navn, string $vaerdi): void
{
    printf("  %-22s %s\n", $navn, $vaerdi);
}

echo "SureSMS-opsætning\n";
echo str_repeat('-', 56), "\n";

linje('Client Id', $config['suresms_client_id'] !== ''
    ? 'sat (' . strlen($config['suresms_client_id']) . ' tegn)' : 'MANGLER');
linje('Secret Id', $config['suresms_secret_id'] !== ''
    ? 'sat (' . strlen($config['suresms_secret_id']) . ' tegn)' : 'MANGLER');
linje('Afsendernavn', $config['sms_sender']);

if (!sms_enabled()) {
    echo "\nSkriv suresms_client_id og suresms_secret_id i\n";
    echo "public_html/includes/config.local.php, og kør igen.\n";
    exit(1);
}

echo "\n1. Henter token\n";
$token = sms_token();

if ($token['fejl'] !== '') {
    echo '   FEJLEDE: ', $token['fejl'], "\n";

    if (str_contains($token['fejl'], 'certificate')) {
        echo "\n   Det ligner en TLS-inspicerende proxy på denne maskine og ikke\n";
        echo "   et problem med loginnet. Enten sæt sms_ca_bundle i\n";
        echo "   config.local.php, eller kør værktøjet på serveren.\n";
    }

    exit(1);
}

echo '   OK — token på ', strlen($token['token']), " tegn\n";

/* --- Nummeret, der prøves med --- */
$raa = $argv[1] ?? '12345678';
$nummer = sms_msisdn($raa);

if ($nummer === null) {
    echo "\nNummeret '", $raa, "' kunne ikke tydes som et dansk mobilnummer.\n";
    exit(1);
}

$tekst = 'Test fra Grundejerforeningen Hesselbjerg Nord.';
$laengde = sms_length($tekst);

echo "\n2. Spørger om prisen (sender intet)\n";
linje('Nummer', $nummer);
linje('Tekst', $tekst);
linje('Længde', $laengde['units'] . ' tegn, ' . $laengde['parts'] . ' SMS');

$pris = sms_pris([$nummer], $tekst);

if (!$pris['ok']) {
    echo '   FEJLEDE: ', $pris['fejl'], "\n";
    exit(1);
}

printf("   OK — %.4f %s\n", $pris['pris'], $pris['valuta']);

if (!isset($argv[1])) {
    echo "\nAlt virker. Der er ikke sendt nogen SMS.\n";
    echo "Vil du sende en rigtig test, så kør:\n";
    echo "  php tools/sms/proev-sms.php DIT_MOBILNUMMER\n";
    exit(0);
}

/* --- En rigtig besked --- */
echo "\n3. Sender én SMS til ", $nummer, "\n";

$svar = sms_send([$nummer], $tekst);

if (!$svar['ok']) {
    echo '   FEJLEDE: ', $svar['error'], "\n";
    exit(1);
}

echo '   OK — sendt. MessageID: ', implode(', ', $svar['ids']), "\n";
echo "\nKig på telefonen. Kommer den ikke frem, så tjek afsendernavnet:\n";
echo "det skal være højst 11 tegn uden mellemrum og specialtegn.\n";

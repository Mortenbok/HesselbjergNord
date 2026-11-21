<?php
/**
 * Admin — bestyrelsens samlede overblik over husstandene.
 *
 * Én husstand = én sommerhusadresse. Til husstanden hører rækkerne i
 * beboerkartoteket (ejer og medejere), adgangskoden i adgangskort og de
 * logins i users, der er oprettet på adressen.
 *
 * De tre tabeller skriver adressen hver på sin måde (kartoteket skriver
 * fx Hyldeskovvænget, koderne Hylleskovvænget), så de samles på en foldet
 * nøgle og ikke på teksten.
 */

require_once __DIR__ . '/adresser.php';
require_once __DIR__ . '/adgang.php';
require_once __DIR__ . '/beboer.php';
require_once __DIR__ . '/bfe.php';

/** Husstande pr. udsendelse. Resten venter til næste klik. */
const ADMIN_SEND_AD_GANGEN = 25;

/**
 * Nøglen, en adresse samles på.
 *
 * Foreningens egne veje får den rettede nøgle, så stavemåderne mødes.
 * Andre veje (Ristingevej) får vejnavnet, som det står. Kan adressen slet
 * ikke tydes, bruges teksten selv, så rækken i det mindste ikke forsvinder.
 */
function admin_noegle(string $adresse): string
{
    $n = adresse_noegle_af_fritekst($adresse);

    if ($n !== '') {
        return $n;
    }

    $n = adresse_noegle_fri($adresse);

    if ($n !== '') {
        return 'fri:' . $n;
    }

    return 'tekst:' . mb_strtolower(trim($adresse));
}

/**
 * Adressen på fast form, når en husstand skal have en kode for første
 * gang: "Hylleskovvænget 7" og ikke "hyldeskovvænget 7, 5932 Humble".
 */
function admin_kanonisk_adresse(string $adresse): string
{
    $ren = trim(preg_replace('/\s+/', ' ', $adresse) ?? '');
    $ren = preg_replace('/[,\s]+\d{4}\s+\p{L}+\s*$/u', '', $ren) ?? $ren;
    $ren = preg_replace('/[,\s]+\d{4}\s*$/u', '', $ren) ?? $ren;

    if (preg_match('/^(.*?)[\s,]*(\d{1,3}\s*[a-zA-ZæøåÆØÅ]?)$/u', $ren, $m)) {
        $vej = adresse_find_vej($m[1]);
        $nr = adresse_husnummer($m[2]);

        if ($vej !== null && $nr !== null) {
            return adresse_saml($vej, $nr);
        }
    }

    return $ren;
}

/**
 * Én persons mail som modtager af koden: mail, ellers mail2.
 *
 * Returnerer ['mail', 'navn', 'id'] — mail er tom streng, hvis personen
 * ikke har en brugbar mail.
 */
function admin_person_mail(array $p): array
{
    foreach (['mail', 'mail2'] as $felt) {
        $mail = beboer_mail((string)($p[$felt] ?? ''));

        if ($mail !== '') {
            return ['mail' => mb_strtolower($mail), 'navn' => trim((string)$p['kontaktperson']), 'id' => (int)$p['id']];
        }
    }

    return ['mail' => '', 'navn' => trim((string)($p['kontaktperson'] ?? '')), 'id' => (int)($p['id'] ?? 0)];
}

/**
 * Ejerens mail — den, koden sendes til, når den sendes fra forsiden.
 *
 * Første ejer-række på adressen med en brugbar mail. Fra Ret-siden kan
 * koden i stedet sendes til den person, der er åbnet — se admin_send_kode().
 */
function admin_ejer_mail(array $personer): array
{
    foreach ($personer as $p) {
        if ($p['forhold'] !== 'ejer') {
            continue;
        }

        $m = admin_person_mail($p);

        if ($m['mail'] !== '') {
            return $m;
        }
    }

    return ['mail' => '', 'navn' => '', 'id' => 0];
}

/**
 * Alle husstande, sorteret efter adresse.
 *
 * Hver husstand: noegle, adresse, personer (kartoteksrækker, ejer først),
 * kort (rækken i adgangskort eller null), logins (aktive brugere),
 * ejer (se admin_ejer_mail).
 */
function admin_husstande(PDO $pdo): array
{
    $huse = [];

    $nyt = static fn(string $noegle, string $adresse): array => [
        'noegle' => $noegle,
        'adresse' => $adresse,
        'personer' => [],
        'kort' => null,
        'logins' => [],
    ];

    $kort = $pdo->query('SELECT * FROM adgangskort')->fetchAll();

    foreach ($kort as $k) {
        $n = admin_noegle((string)$k['adresse']);
        $huse[$n] ??= $nyt($n, (string)$k['adresse']);
        $huse[$n]['kort'] = $k;
        // Kodens adresse er den rettede — den vises frem for kartotekets.
        $huse[$n]['adresse'] = (string)$k['adresse'];
    }

    $raekker = $pdo->query(
        "SELECT * FROM beboerkartotek ORDER BY forhold = 'ejer' DESC, id"
    )->fetchAll();

    foreach ($raekker as $r) {
        $n = admin_noegle((string)$r['sommerhusadresse']);
        $huse[$n] ??= $nyt($n, admin_kanonisk_adresse((string)$r['sommerhusadresse']));
        $huse[$n]['personer'][] = $r;
    }

    $brugere = $pdo->query(
        "SELECT id, username, display_name, role, address, last_login_at
           FROM users WHERE is_active = 1 AND address <> ''"
    )->fetchAll();

    foreach ($brugere as $u) {
        $n = admin_noegle((string)$u['address']);

        if (isset($huse[$n])) {
            $huse[$n]['logins'][] = $u;
        }
    }

    foreach ($huse as &$h) {
        $h['ejer'] = admin_ejer_mail($h['personer']);
    }
    unset($h);

    uasort($huse, static fn($a, $b) => strnatcasecmp($a['adresse'], $b['adresse']));

    return $huse;
}

/** Husstanden, en adresse hører til, eller null. */
function admin_hus(PDO $pdo, string $adresse): ?array
{
    return admin_husstande($pdo)[admin_noegle($adresse)] ?? null;
}

/**
 * Adressen, links i mailen peger på. Skemaet og værten tages fra den
 * aktuelle forespørgsel, så linket virker, hvor siden end ligger.
 */
function admin_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'hesselbjergnord.dk')
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), "/\\");
}

/**
 * Teksten i bekræftelsen, før en kode sendes: hvem får den, og hvilke
 * logins lukkes.
 */
function admin_send_spoergsmaal(array $hus, int $migSelv, ?array $modtager = null): string
{
    $modtager ??= $hus['ejer'];
    $andres = array_filter($hus['logins'], static fn($u) => (int)$u['id'] !== $migSelv);
    $lukkes = count($andres);

    return 'Send en ny kode til ' . $modtager['navn'] . ' (' . $modtager['mail'] . ')?' . "\n\n"
        . 'Den gamle kode til ' . $hus['adresse'] . ' holder op med at virke.'
        . ($lukkes > 0
            ? "\n" . $lukkes . ' login' . ($lukkes === 1 ? '' : 's') . ' på adressen lukkes: '
                . implode(', ', array_column($andres, 'username'))
            : '')
        . ($lukkes < count($hus['logins']) ? "\nDit eget login bliver ikke lukket." : '');
}

/** Passer husstanden på filteret? */
function admin_filter(array $h, string $vis): bool
{
    switch ($vis) {
        case 'ikke-oprettet':
            return $h['personer'] !== [] && $h['logins'] === [];
        case 'ingen-mail':
            return $h['personer'] !== [] && $h['ejer']['mail'] === '';
        case 'mangler':
            foreach ($h['personer'] as $p) {
                if ($p['status'] === 'afventer_oplysninger') { return true; }
            }
            return false;
        case 'problemer':
            foreach ($h['personer'] as $p) {
                if (beboer_problemer($p) !== []) { return true; }
            }
            return false;
        case 'bestyrelse':
            foreach ($h['personer'] as $p) {
                if ((int)$p['bestyrelse'] === 1) { return true; }
            }
            return false;
        case 'noter':
            foreach ($h['personer'] as $p) {
                if (trim((string)$p['noter']) !== '') { return true; }
            }
            return false;
        case 'uden-beboer':
            return $h['personer'] === [];
        default:
            return true;
    }
}

/** Passer husstanden på søgningen? Navn, adresse, mail, by og telefon. */
function admin_soeg(array $h, string $soeg): bool
{
    if ($soeg === '') {
        return true;
    }

    $s = mb_strtolower($soeg);

    if (str_contains(mb_strtolower($h['adresse']), $s)) {
        return true;
    }

    foreach ($h['personer'] as $p) {
        foreach (['kontaktperson', 'sommerhusadresse', 'mail', 'mail2',
                  'hjemmeadresse', 'by', 'telefon', 'telefon2'] as $felt) {
            if (str_contains(mb_strtolower((string)$p[$felt]), $s)) {
                return true;
            }
        }
    }

    return false;
}

/** En ledig, ny kode. */
function admin_ledig_kode(PDO $pdo): string
{
    $findes = $pdo->prepare('SELECT 1 FROM adgangskort WHERE kode = :k LIMIT 1');

    do {
        $kode = adgang_ny_kode();
        $findes->execute([':k' => $kode]);
    } while ($findes->fetchColumn());

    return $kode;
}

/**
 * Sender en ny kode til husstandens ejer — eller til $modtager (se
 * admin_person_mail), når den sendes fra en bestemt persons Ret-side.
 *
 * Den gamle kode holder op med at virke, og alle logins på adressen
 * lukkes — undtagen den, der trykker på knappen, så bestyrelsen aldrig
 * kan lukke sig selv ude. Ejeren opretter sit login igen via linket.
 *
 * Det hele sker i én transaktion, der først gemmes, når mailen er sendt.
 * Fejler mailen, er intet ændret.
 *
 * Returnerer ['ok' => bool, 'fejl' => string, 'mail' => string, 'lukkede' => int].
 */
function admin_send_kode(PDO $pdo, array $hus, string $base, int $migSelv, ?array $modtager = null): array
{
    $ejer = $modtager ?? $hus['ejer'];

    if ($ejer['mail'] === '') {
        return ['ok' => false, 'fejl' => ($modtager !== null ? $ejer['navn'] : $hus['adresse'] . ' (ejeren)') . ' har ingen brugbar mail.', 'mail' => '', 'lukkede' => 0];
    }

    $pdo->beginTransaction();

    try {
        $kode = admin_ledig_kode($pdo);

        if ($hus['kort'] !== null) {
            $kortId = (int)$hus['kort']['id'];
            $adresse = (string)$hus['kort']['adresse'];
            $pdo->prepare(
                'UPDATE adgangskort
                    SET kode = :k, spaerret = 0, antal_brug = 0, sidst_brugt = NULL,
                        sendt_at = NOW(), sendt_antal = sendt_antal + 1
                  WHERE id = :id'
            )->execute([':k' => $kode, ':id' => $kortId]);
        } else {
            $adresse = $hus['adresse'];
            $pdo->prepare(
                'INSERT INTO adgangskort (adresse, kode, sendt_at, sendt_antal) VALUES (:a, :k, NOW(), 1)'
            )->execute([':a' => $adresse, ':k' => $kode]);
        }

        $luk = [];

        foreach ($hus['logins'] as $u) {
            if ((int)$u['id'] !== $migSelv) {
                $luk[] = (int)$u['id'];
            }
        }

        if ($luk !== []) {
            $pladser = implode(',', array_fill(0, count($luk), '?'));
            $pdo->prepare("UPDATE users SET is_active = 0 WHERE id IN ($pladser)")->execute($luk);
        }

        $link = $base . '/adgang.php?k=' . $kode;
        $sendt = adgang_send_kode($adresse, $kode, $ejer['mail'], $ejer['navn'], $link);

        if (!$sendt) {
            $pdo->rollBack();

            return ['ok' => false, 'fejl' => 'Mailen til ' . $ejer['mail'] . ' kunne ikke sendes. Intet er ændret.', 'mail' => $ejer['mail'], 'lukkede' => 0];
        }

        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('admin_send_kode: ' . $t->getMessage());

        return ['ok' => false, 'fejl' => 'Noget gik galt for ' . $hus['adresse'] . '. Intet er ændret.', 'mail' => $ejer['mail'], 'lukkede' => 0];
    }

    return ['ok' => true, 'fejl' => '', 'mail' => $ejer['mail'], 'lukkede' => count($luk)];
}

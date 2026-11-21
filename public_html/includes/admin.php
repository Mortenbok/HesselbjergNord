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

/** Personens brugbare mails, med små bogstaver. */
function admin_person_mails(array $p): array
{
    $ud = [];

    foreach (['mail', 'mail2'] as $felt) {
        $mail = beboer_mail((string)($p[$felt] ?? ''));

        if ($mail !== '') {
            $ud[] = mb_strtolower($mail);
        }
    }

    return array_values(array_unique($ud));
}

/**
 * Ejerens mail. Bruges til filteret "ejer uden mail" og til at finde
 * ejeren, der fik husstandens fælles kode, før hver person fik sin egen.
 *
 * Første ejer-række på adressen med en brugbar mail.
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
 * kort (rækken i adgangskort eller null), logins (aktive brugere på
 * adressen), andre_logins (dem af logins, der ikke passer på en person i
 * kartoteket), ejer (se admin_ejer_mail).
 *
 * Hver person får desuden:
 *   'login'  den aktive bruger med personens mail (eller null) — søgt på
 *            alle adresser, så én mail på to huse ikke ser ud til at mangle
 *   'sendt'  hvornår personen sidst fik en kode (eller null). Ejeren, der
 *            fik husstandens fælles kode før de personlige koder, tæller med.
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

    $efterMail = [];

    foreach ($brugere as $u) {
        $efterMail[mb_strtolower((string)$u['username'])] = $u;
        $n = admin_noegle((string)$u['address']);

        if (isset($huse[$n])) {
            $huse[$n]['logins'][] = $u;
        }
    }

    foreach ($huse as &$h) {
        $h['ejer'] = admin_ejer_mail($h['personer']);
        $kendte = [];

        foreach ($h['personer'] as &$p) {
            $p['login'] = null;

            foreach (admin_person_mails($p) as $m) {
                if (isset($efterMail[$m])) {
                    $p['login'] = $efterMail[$m];
                    $kendte[(int)$efterMail[$m]['id']] = true;
                    break;
                }
            }

            $p['sendt'] = $p['kode_sendt_at'] ?? null;

            if ($p['sendt'] === null && (int)$p['id'] === $h['ejer']['id']
                && $h['kort'] !== null && $h['kort']['sendt_at']) {
                $p['sendt'] = $h['kort']['sendt_at'];
            }
        }
        unset($p);

        $h['andre_logins'] = array_values(array_filter(
            $h['logins'],
            static fn($u) => !isset($kendte[(int)$u['id']])
        ));
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
 * Personen med det id i husstanden — med 'login' og 'sendt' fra
 * admin_husstande() — eller null.
 */
function admin_hus_person(array $hus, int $id): ?array
{
    foreach ($hus['personer'] as $p) {
        if ((int)$p['id'] === $id) {
            return $p;
        }
    }

    return null;
}

/**
 * Teksten i bekræftelsen, før en kode sendes til én person: hvem får den,
 * og hvad sker der med personens login. De andre på adressen røres ikke.
 */
function admin_send_spoergsmaal(array $p, int $migSelv): string
{
    $m = admin_person_mail($p);
    $login = $p['login'] ?? null;

    return 'Send en ny kode til ' . $m['navn'] . ' (' . $m['mail'] . ')?' . "\n\n"
        . 'En kode, ' . $m['navn'] . ' har fået før, holder op med at virke.'
        . ($login !== null && (int)$login['id'] !== $migSelv
            ? "\n" . 'Loginnet ' . $login['username'] . ' lukkes, til den nye kode er brugt.'
            : '')
        . ($login !== null && (int)$login['id'] === $migSelv ? "\nDit eget login bliver ikke lukket." : '')
        . "\n" . 'De andre på adressen beholder deres login.';
}

/** Personer, der mangler et login, og som har en mail at sende koden til. */
function admin_mangler_login(array $h): array
{
    return array_values(array_filter(
        $h['personer'],
        static fn($p) => $p['login'] === null && admin_person_mails($p) !== []
    ));
}

/** Passer husstanden på filteret? */
function admin_filter(array $h, string $vis): bool
{
    switch ($vis) {
        case 'ikke-oprettet':
            return admin_mangler_login($h) !== [];
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

/** En ledig, ny kode — hverken brugt af en husstand eller af en person. */
function admin_ledig_kode(PDO $pdo): string
{
    $findes = $pdo->prepare(
        'SELECT 1 FROM adgangskort WHERE kode = ?
         UNION SELECT 1 FROM beboerkartotek WHERE kode = ? LIMIT 1'
    );

    do {
        $kode = adgang_ny_kode();
        $findes->execute([$kode, $kode]);
    } while ($findes->fetchColumn());

    return $kode;
}

/**
 * Sender en ny, personlig kode til én person i kartoteket — ejer eller
 * medejer. $p er personen fra admin_husstande() (med 'login').
 *
 * Personens tidligere kode holder op med at virke, og personens eget
 * login lukkes, til den nye kode er brugt — men aldrig den, der trykker på
 * knappen, så bestyrelsen ikke kan lukke sig selv ude. De andre på
 * adressen røres ikke: de har hver deres kode og login.
 *
 * Er det ejeren, der fik husstandens fælles kode fra før, dør den også.
 *
 * Det hele sker i én transaktion, der først gemmes, når mailen er sendt.
 * Fejler mailen, er intet ændret.
 *
 * Returnerer ['ok' => bool, 'fejl' => string, 'mail' => string, 'lukkede' => int].
 */
function admin_send_kode(PDO $pdo, array $hus, array $p, string $base, int $migSelv): array
{
    $m = admin_person_mail($p);

    if ($m['mail'] === '') {
        return ['ok' => false, 'fejl' => $m['navn'] . ' har ingen brugbar mail.', 'mail' => '', 'lukkede' => 0];
    }

    $luk = [];
    $login = $p['login'] ?? null;

    if ($login !== null && (int)$login['id'] !== $migSelv) {
        $luk[] = (int)$login['id'];
    }

    $pdo->beginTransaction();

    try {
        $kode = admin_ledig_kode($pdo);

        $pdo->prepare(
            'UPDATE beboerkartotek
                SET kode = :k, kode_sendt_at = NOW(), kode_sendt_antal = kode_sendt_antal + 1
              WHERE id = :id'
        )->execute([':k' => $kode, ':id' => (int)$p['id']]);

        // Den fælles husstandskode, der før blev sendt til ejeren. Får
        // ejeren nu sin egen, er den gamle overflødig — og den skal ikke
        // kunne bruges af en tidligere ejer, der stadig har mailen.
        if ($hus['kort'] !== null && (int)$p['id'] === $hus['ejer']['id']) {
            $pdo->prepare('UPDATE adgangskort SET kode = :k WHERE id = :id')
                ->execute([':k' => admin_ledig_kode($pdo), ':id' => (int)$hus['kort']['id']]);
        }

        if ($luk !== []) {
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute($luk);
        }

        $link = $base . '/adgang.php?k=' . $kode;
        $sendt = adgang_send_kode($hus['adresse'], $kode, $m['mail'], $m['navn'], $link);

        if (!$sendt) {
            $pdo->rollBack();

            return ['ok' => false, 'fejl' => 'Mailen til ' . $m['mail'] . ' kunne ikke sendes. Intet er ændret.', 'mail' => $m['mail'], 'lukkede' => 0];
        }

        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('admin_send_kode: ' . $t->getMessage());

        return ['ok' => false, 'fejl' => 'Noget gik galt for ' . $m['navn'] . '. Intet er ændret.', 'mail' => $m['mail'], 'lukkede' => 0];
    }

    return ['ok' => true, 'fejl' => '', 'mail' => $m['mail'], 'lukkede' => count($luk)];
}

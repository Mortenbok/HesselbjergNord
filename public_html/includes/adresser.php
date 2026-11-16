<?php
/**
 * Adresserne i Hesselbjerg Nord — ét sted at kontrollere, at en adresse
 * hører til foreningen, og at den skrives ens hver gang.
 *
 * Vejene er dem, der står i vedtægternes § 1. Bemærk, at vedtægterne skriver
 * "Hyldeskovvænget"; vejen hedder i virkeligheden Hylleskovvænget — se
 * fodnoten på vedtaegter.php og punkt 4 i TODO.md. Begge stavemåder tages
 * imod her, men det er den rigtige, der gemmes.
 */

/** Foreningens veje, som de skal staves. */
const HBN_VEJE = [
    'Ahornvænget',
    'Askevænget',
    'Birkevænget',
    'Blåbærvænget',
    'Duevænget',
    'Elmevænget',
    'Enebærvænget',
    'Fyrrevænget',
    'Granvænget',
    'Gulspurvevænget',
    'Hasselvænget',
    'Hylleskovvænget',
    'Klitrosevej',
    'Lindevænget',
    'Musvitvænget',
    'Rosenvænget',
    'Rønnevænget',
    'Syrenvænget',
    'Tjørnevænget',
    'Ørnevænget',
];

/** Postnummer og by for hele området. */
const HBN_POSTNR = '5932';
const HBN_BY = 'Humble';

/**
 * Stavemåder, der også tages imod. Nøglen er den forkerte skrivemåde i
 * sammenlignelig form, værdien er den rigtige vej.
 */
const HBN_VEJ_ALIAS = [
    'hyldeskovvanget' => 'Hylleskovvænget',  // stavemåden i vedtægterne fra 2017
    'hylleskovvej' => 'Hylleskovvænget',
    'klitrosevanget' => 'Klitrosevej',
];

/**
 * Gør et vejnavn sammenligneligt: små bogstaver, uden mellemrum, bindestreger
 * og punktummer, og med æ/ø/å foldet ud. Så rammer "KLITROSE VEJ",
 * "klitrosevej" og "Klitrose-vej" alle det samme.
 */
function adresse_fold(string $vej): string
{
    $vej = mb_strtolower(trim($vej));

    $erstat = [
        'æ' => 'a', 'ø' => 'o', 'å' => 'a',
        'ae' => 'a', 'oe' => 'o', 'aa' => 'a',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'é' => 'e',
    ];

    $vej = strtr($vej, $erstat);

    return preg_replace('/[^a-z0-9]/', '', $vej) ?? '';
}

/**
 * Finder den rigtige vej ud fra noget, brugeren har skrevet.
 *
 * Returnerer vejnavnet, eller null hvis det ikke kunne genkendes. Små
 * slåfejl tilgives: der er kun 20 veje, og de ligner ikke hinanden, så en
 * enkelt forkert bogstav kan trygt rettes til.
 */
function adresse_find_vej(string $input): ?string
{
    $foldet = adresse_fold($input);

    if ($foldet === '') {
        return null;
    }

    foreach (HBN_VEJE as $vej) {
        if (adresse_fold($vej) === $foldet) {
            return $vej;
        }
    }

    if (isset(HBN_VEJ_ALIAS[$foldet])) {
        return HBN_VEJ_ALIAS[$foldet];
    }

    // Slåfejl: nærmeste vej, hvis den er tæt nok på og står alene.
    $bedste = null;
    $bedsteAfstand = PHP_INT_MAX;
    $naestbedste = PHP_INT_MAX;

    foreach (HBN_VEJE as $vej) {
        $afstand = levenshtein($foldet, adresse_fold($vej));

        if ($afstand < $bedsteAfstand) {
            $naestbedste = $bedsteAfstand;
            $bedsteAfstand = $afstand;
            $bedste = $vej;
        } elseif ($afstand < $naestbedste) {
            $naestbedste = $afstand;
        }
    }

    // Højst to tegn galt, og mindst to tegn bedre end den næste — ellers er
    // gættet for usikkert til at rette automatisk.
    $graense = mb_strlen($foldet) <= 8 ? 1 : 2;

    if ($bedsteAfstand <= $graense && $naestbedste - $bedsteAfstand >= 2) {
        return $bedste;
    }

    return null;
}

/**
 * Kontrollerer et husnummer: 1-999, eventuelt med ét bogstav bagefter.
 * Returnerer nummeret på fast form ("18A"), eller null.
 */
function adresse_husnummer(string $input): ?string
{
    $input = preg_replace('/\s+/', '', trim($input)) ?? '';

    if (!preg_match('/^(\d{1,3})([a-zA-ZæøåÆØÅ]?)$/u', $input, $m)) {
        return null;
    }

    $nummer = (int)$m[1];

    if ($nummer < 1 || $nummer > 999) {
        return null;
    }

    return $nummer . mb_strtoupper($m[2]);
}

/** Adressen på fast form, fx "Klitrosevej 18A". */
function adresse_saml(string $vej, string $nummer): string
{
    return $vej . ' ' . $nummer;
}

/**
 * Nøglen, to adresser sammenlignes på: "klitrosevej18a".
 *
 * Bruges til at opdage, at den samme adresse er tilmeldt før — også selv om
 * den dengang blev skrevet anderledes.
 */
function adresse_noegle(string $vej, string $nummer): string
{
    return adresse_fold($vej) . mb_strtolower($nummer);
}

/**
 * Nøglen ud fra en adresse, UDEN at vejen skal være en af foreningens.
 *
 * adresse_noegle_af_fritekst() nedenfor retter stavemåden til den rigtige
 * vej og giver tom streng, hvis vejen ikke står i vedtægterne. Det er det
 * rigtige, når en ny beboer melder sig — men ikke, når vi bare skal kunne
 * slå en adresse op. Ristingevej 121 og 123 står i kartoteket uden at være
 * blandt de 20 veje, og de skal stadig kunne finde deres BFE-nummer.
 *
 * Vejnavnet foldes, som det er skrevet. Tom streng, hvis adressen slet
 * ikke kan deles i vej og husnummer.
 */
function adresse_noegle_fri(string $adresse): string
{
    $adresse = trim(preg_replace('/\s+/', ' ', $adresse) ?? '');
    $adresse = preg_replace('/[,\s]+\d{4}\s+\p{L}+\s*$/u', '', $adresse) ?? $adresse;
    $adresse = preg_replace('/[,\s]+\d{4}\s*$/u', '', $adresse) ?? $adresse;

    if (!preg_match('/^(.*?)[\s,]*(\d{1,3}\s*[a-zA-ZæøåÆØÅ]?)$/u', $adresse, $m)) {
        return '';
    }

    $vej = adresse_fold($m[1]);
    $nummer = adresse_husnummer($m[2]);

    if ($vej === '' || $nummer === null) {
        return '';
    }

    return $vej . mb_strtolower($nummer);
}

/**
 * Nøglen ud fra en adresse skrevet i fri tekst, som de ældre rækker i
 * databasen indeholder. Tom streng, hvis den ikke kan tydes.
 */
function adresse_noegle_af_fritekst(string $adresse): string
{
    $adresse = trim(preg_replace('/\s+/', ' ', $adresse) ?? '');

    // Postnummer og by skæres fra, hvis de står der.
    $adresse = preg_replace('/[,\s]+\d{4}\s+\p{L}+\s*$/u', '', $adresse) ?? $adresse;
    $adresse = preg_replace('/[,\s]+\d{4}\s*$/u', '', $adresse) ?? $adresse;

    // [\s,]* og ikke +: "Klitrosevej18" skal også kunne tydes.
    if (!preg_match('/^(.*?)[\s,]*(\d{1,3}\s*[a-zA-ZæøåÆØÅ]?)$/u', $adresse, $m)) {
        return '';
    }

    $vej = adresse_find_vej($m[1]);
    $nummer = adresse_husnummer($m[2]);

    if ($vej === null || $nummer === null) {
        return '';
    }

    return adresse_noegle($vej, $nummer);
}

/**
 * Er adressen tilmeldt før?
 *
 * Returnerer den fundne række, eller null. Sammenligningen sker i PHP og ikke
 * i SQL, fordi ældre rækker kan have adressen skrevet på en hvilken som helst
 * måde — "klitrosevej18" skal også fanges. Listen er på nogle få hundrede
 * rækker, så det er rigeligt hurtigt.
 *
 * Rækkerne gives med som array og hentes ikke herinde, så funktionen kan
 * afprøves uden en databaseforbindelse.
 */
function adresse_findes_i_forvejen(array $raekker, string $noegle): ?array
{
    if ($noegle === '') {
        return null;
    }

    foreach ($raekker as $raekke) {
        if (adresse_noegle_af_fritekst((string)($raekke['address'] ?? '')) === $noegle) {
            return $raekke;
        }
    }

    return null;
}

# BFE-numre

BFE står for **Bestemt Fast Ejendom** og er ejendommens nummer i
ejendomsregistret. Det er det eneste, der kan dannes et direkte link til
ejeroplysningerne ud af:

    https://nyt.ois.dk/search/3073473

Derfor har beboerkartoteket en Ejer-knap: står nummeret på rækken, går
knappen direkte til OIS, hvor ejerne står.

## Hvorfor numrene ligger i koden

Numrene er hentet én gang og skrevet ind i `public_html/includes/bfe.php`.
Hjemmesiden spørger altså ikke Datafordeleren, når bestyrelsen åbner
kartoteket. Det er et bevidst valg:

* BFE-numre er faste. De skifter kun, hvis en ejendom bliver delt eller
  lagt sammen.
* Der skal ingen API-nøgle ligge på serveren.
* Ingen ventetid, og kartoteket virker også den dag, Datafordeleren er nede.

## Sådan henter du numrene igen

Du skal bruge en API-nøgle til Datafordeleren. Den oprettes gratis på
[datafordeler.dk](https://datafordeler.dk) under Selvbetjening og skrives i
`public_html/includes/config.local.php`:

    'datafordeler_apikey' => 'DIN NØGLE HER',

Den fil ligger hverken i git eller på serveren — den bruges kun her.
Bemærk, at en ny nøgle først virker **cirka et kvarter** efter, den er
oprettet; indtil da svarer Datafordeleren 401 på skift.

Så:

    php tools/bfe/hent-bfe.php      henter numrene til tools/bfe/cache.json
    php tools/bfe/skriv-bfe.php     skriver public_html/includes/bfe.php

`cache.json` gør, at en afbrudt kørsel kan tages forfra uden at spørge om
det samme igen. Slet den, hvis du vil hente alt forfra.

Kommer der en adresse til, så skriv den i `public_html/includes/husstande.php`
og kør de to kommandoer igen.

## Hvordan opslaget virker

Der findes ikke ét opslag fra adresse til BFE, så kæden har tre led:

| # | Hvor | Fra | Til |
|---|------|-----|-----|
| 1 | `adressevaelger.dk/husnumre/soeg` | vejnavn + husnr + postnr | husnummerets UUID |
| 2 | `BBR_Grund` | husnummer-UUID | ejendommens UUID |
| 3 | `BBR_Ejendomsrelation` | ejendommens UUID | **BFE-nummer** |

Led 1 er gratis og kræver ingen nøgle — det er den samme offentlige
søgning, OIS selv bruger. Led 2 og 3 går gennem Datafordelerens
GraphQL-endepunkt `graphql.datafordeler.dk/flexible/v3`.

Værktøjet kører **samtidig** en helt anden vej som krydstjek:

| # | Hvor | Fra | Til |
|---|------|-----|-----|
| 2′ | `BBR_Bygning` | husnummer-UUID | jordstykkets id |
| 3′ | `MAT_Jordstykke` | jordstykkets id | BFE-nummer |

De to veje går gennem hvert sit register. Er de enige, skrives
`bekraeftet: true` i cachen. Er de uenige, springes adressen over og
nævnes i udskriften, så den kan slås op i hånden.

Den anden vej taber på ubebyggede grunde — uden en bygning er der intet
jordstykke at følge. Derfor er hovedvejen grunden og ikke bygningen.

## Hvad vi IKKE kan

**Ejernes navne kan ikke hentes.** De står i Ejerfortegnelsen (EJF).
Nøglen bliver godkendt på endepunktet, men selve dataene svarer

    403 — The current user is not authorized to access this resource.

Ejeroplysninger er fortrolige og kræver en anmodning hos Geodatastyrelsen,
MitID Erhverv og OAuth i stedet for en API-nøgle. Og selv med den adgang
udleverer EJF `ejendePersonPersonNr` — CPR-numre, ikke navne. Navnene, man
ser på OIS, vises under OIS' egen aftale.

Derfor stopper automatikken ved BFE-nummeret, og det sidste klik ud til
OIS er med vilje.

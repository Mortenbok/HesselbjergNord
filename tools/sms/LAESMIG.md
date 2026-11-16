# SMS gennem SureSMS

Beskeder sendes fra `besked.php`. Mail virker uden videre; SMS skal have et
login hos [SureSMS](https://suresms.com).

## Sådan sættes det op

1. Hent **Client Id** og **Secret Id** på din konto hos SureSMS.
2. Skriv dem i `public_html/includes/config.local.php`:

   ```php
   'suresms_client_id' => 'DIT CLIENT ID',
   'suresms_secret_id' => 'DIT SECRET ID',
   'sms_sender' => 'Hesselbjerg',
   ```

   Afsendernavnet er det, modtageren ser. Højst **11 tegn**, bogstaver og
   tal, ingen mellemrum.

3. Filen ligger hverken i git eller i FTP-overførslen — den skal lægges på
   serveren i hånden med FileZilla. Det er med vilje: den indeholder
   hemmeligheder, og depotet er offentligt.

4. Prøv opsætningen af:

   ```
   php tools/sms/proev-sms.php              henter token og spørger om prisen
   php tools/sms/proev-sms.php 12345678     sender ÉN rigtig SMS dertil
   ```

   Uden et nummer sender værktøjet **ingenting**. Det henter kun et token og
   spørger, hvad en besked ville koste — nok til at vise, at loginnet virker.

## Sådan virker det

| # | Hvad | Hvor |
|---|------|------|
| 1 | Hent token med Client Id og Secret Id | `POST /json/token` |
| 2 | Send beskeden med tokenet i en Bearer-header | `POST /json/api/Message` |
| – | Spørg om prisen uden at sende | `GET /json/api/Price/…` |

SureSMS kalder det en *password grant*: brugernavnet er Client Id'et, og
adgangskoden er Secret Id'et.

Tokenet holder et døgn, men vi henter et nyt for hver udsendelse. Det er ét
ekstra kald pr. hold på 25 — til gengæld ligger der ingen hemmeligheder i en
fil eller en session bagefter.

Flere modtagere sendes i **ét** kald; numrene adskilles med semikolon.
Derfor er svaret enten ja eller nej for hele holdet, og det er sådan,
`besked.php` mærker modtagerne i køen.

API-beskrivelsen: <https://apiv2.suresms.com/json/swagger/>

## Numre

`sms_msisdn()` tager imod `12345678`, `+45 12 34 56 78` og `0045 12345678`
og laver dem alle om til `4512345678`.

**Kun danske numre.** Et udenlandsk nummer bliver stille sprunget over, og
beboeren får ingen SMS. Kartoteket har en `land`-kolonne, så det kan
forekomme — se punktet i `TODO.md`.

## Længden af en besked

En SMS rummer 160 tegn fra GSM-alfabetet. Æ, ø og å er med i alfabetet og
fylder ét tegn; `€`, `{`, `}`, `[`, `]`, `\`, `^`, `|` og `~` fylder to.

Ét tegn uden for alfabetet — en emoji, en tankestreg, et citationstegn fra
Word — tvinger **hele** beskeden over i UCS-2, hvor der kun er plads til 70
tegn. Tælleren under tekstfeltet på `besked.php` siger det undervejs.

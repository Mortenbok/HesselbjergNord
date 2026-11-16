# SMS gennem SureSMS

Beskeder sendes fra `besked.php`. Mail virker uden videre; SMS skal have et
login hos [SureSMS](https://suresms.com).

## Sådan sættes det op

1. Lav en **API-nøgle** på <https://app.suresms.com/UserApi/Index>.

   Det skal være en API-nøgle. Et *Client Id* og et *Secret Id* virker
   ikke — hverken mod `apiv2.suresms.com/json/token`, mod
   `oauth2/api/Account/Gettoken` eller mod det gamle
   `Script/SendSMS.aspx`. Alle tre svarer "invalid credentials".

2. Skriv nøglen i `public_html/includes/config.local.php`:

   ```php
   'suresms_apikey' => 'DIN NØGLE',
   'sms_sender' => 'Hesselbjerg',
   ```

   Afsendernavnet er det, modtageren ser. Højst **11 tegn**, bogstaver og
   tal, ingen mellemrum — og det skal være godkendt under **Sender-ID** på
   kontoen, ellers afviser SureSMS beskeden.

3. Filen ligger hverken i git eller i FTP-overførslen — den skal lægges på
   serveren i hånden med FileZilla. Det er med vilje: den indeholder
   hemmeligheder, og depotet er offentligt.

4. Prøv opsætningen af:

   ```
   php tools/sms/proev-sms.php                    bytter nøglen til et token
   php tools/sms/proev-sms.php 12345678           sender ÉN SMS dertil
   php tools/sms/proev-sms.php 12345678 "Tekst"   med din egen tekst
   ```

   Uden et nummer sender værktøjet **ingenting**. Det henter kun et token
   og slår kontoen op — nok til at vise, at nøglen virker.

## Sådan virker det

| # | Hvad | Hvor |
|---|------|------|
| 1 | Byt API-nøglen til et token | `POST /oauth2/api/Account/Gettoken` |
| 2 | Send én besked med tokenet som Bearer | `POST /oauth2/api/Message/Send` |

Ved trin 1 skrives den faste tekst `+apikey` i feltet `accountPhoneNumber`
og selve nøglen i `accountPassword`. Det ser mærkeligt ud, men det er
SureSMS' egen opskrift — den står i deres API-beskrivelse.

Der sendes **ét kald pr. modtager**. SureSMS kan tage flere ad gangen, men
så kommer der ét samlet svar, og vi kan ikke se, hvem der faldt fra. Ét
kald pr. modtager giver et rigtigt svar pr. person, og det er dét, køen i
`besked.php` bygger på: hver modtager mærkes for sig, så en afbrudt
udsendelse kan tages op igen uden dubletter.

Tokenet hentes én gang pr. hold på 25 og gemmes ikke. Så ligger der ingen
hemmelighed i en fil eller en session bagefter.

API-beskrivelsen: <https://api.suresms.com/oauth2/swagger/docs/v1>
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

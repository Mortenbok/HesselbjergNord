# TODO — hesselbjergnord.dk

Sidst opdateret: 16. november 2026

---

## 1. SEO

Målet skal besluttes først: skal siden findes af **kommende beboere og
købere**, eller er den mest et opslagsværk for **nuværende medlemmer**?
Er det det sidste, kan næsten hele listen springes over — bortset fra
billederne, som alle har glæde af.

### 1.1 Billederne fylder alt for meget  ← størst teknisk gevinst
Sidehastighed tæller med i Googles placering, og de her filer er tunge
at hente på en telefon:

| Fil | Nu | Mål |
|---|---|---|
| `kontingent.jpg` | 5,7 MB | ~300 KB |
| `beach-bg.jpg` | 3,4 MB | ~300 KB |
| `vedtaegter.jpg` | 2,4 MB | ~250 KB |
| `bestyrelsen.jpg` | 1,7 MB | ~250 KB |
| `urlLogo.png` | 1,2 MB | ~100 KB |
| `aktiviteter.jpg` | 1,0 MB | ~200 KB |

Skaleres til 1920 px bred og gemmes som WebP med JPG som reserve.
Forventet: over 90 % mindre uden synlig forskel.

`omraade.jpg` (3,1 MB) er ikke længere i brug: den nye `omraade.html`
bruger `beach-bg.jpg` som baggrund. Filen kan slettes — både i git og på
serveren.

### 1.2 robots.txt og sitemap.xml mangler helt
Ingen af delene findes. Et sitemap betyder ekstra meget her, fordi to
sider nu er skjult i menuen (se punkt 3) — Google kan ikke længere finde
dem ved at følge links.

### 1.3 Canonical-adresser mangler
Siden svarer på både `hesselbjergnord.dk` og `www.hesselbjergnord.dk`.
Uden `<link rel="canonical">` kan Google se det som to sider med samme
indhold og dele placeringen mellem dem.

### 1.4 Open Graph mangler på alle sider
Deles siden på Facebook — som er den måde, en grundejerforening spreder
sig på — vises den som et nøgent link uden billede og beskrivelse.

### 1.5 Beskrivelse mangler på én side
`kontingent.html`.

### 1.6 Strukturerede data
`Organization`/`LocalBusiness` i JSON-LD. Lille gevinst, hurtigt gjort.

---

## 2. Én side er reelt tom  ← vigtigst af alt, og kun du kan gøre det

Målt synligt indhold:

| Side | Ord |
|---|---|
| `kontingent.html` | ~32 |
| `betalingsservice.html` | ~345 |
| `hjertestarter.html` | ~260 |
| `index.html` | ~101 |

`kontingent.html` står stadig som "Under opbygning". Ingen meta-tags kan
løfte en tom side — der skal tekst på. Det er skrivearbejde, ikke kodning.

`vedtaegter.html` er klaret: vedtægterne ligger nu på `vedtaegter.php`
bag login, med hele teksten og den underskrevne PDF vedhæftet. Den gamle
offentlige fil står tilbage som henvisning til den nye side.

`omraade.html` er klaret: siden er nu et kort over foreningens område med
alle 20 veje tegnet ind, en vejliste med længder og nøgletal (42 ha,
4,1 km vej). Den er tilbage i menuen på alle sider. Vejenes forløb er
hentet fra OpenStreetMap; kortet tegnes med Leaflet fra cdnjs, og der er
både almindeligt kort og luftfoto.

`aktiviteter.html` er klaret: aktiviteterne ligger nu i årshjulet på
`aarshjul.php`, som er offentligt og står i menuen. Den gamle fil er
`noindex` og sender videre. Indholdet ligger i databasen, så bestyrelsen
retter det i browseren — ikke i HTML.

---

## 3. De to skjulte sider er nu forældreløse

`kontingent` og `betalingsservice` er taget ud af menuen, men findes
stadig og svarer med 200. Ingen sider linker til dem længere.

(`vedtaegter.html` er afgjort: den har fået `noindex` og sender videre
til `vedtaegter.php` i medlemsområdet.)

Beslut for hver enkelt:
- **Skal findes** → den skal linkes fra et sted, ellers regner Google den
  for uvæsentlig
- **Skal ikke findes** → sæt `noindex` på, så siden ikke trækker
  helhedsindtrykket ned
- **Skal væk** → send den videre til forsiden eller lad den svare 404

---

## 4. Løse ender

- [ ] **SureSMS API-nøgle** ind i `includes/config.local.php` som
      `suresms_apikey`. Nøglen laves på
      <https://app.suresms.com/UserApi/Index>. Filen ligger hverken i
      git eller i FTP-overførslen, så den skal lægges på serveren i
      hånden. Prøv den af med `php tools/sms/proev-sms.php` — uden et
      nummer sender den intet. Mail virker allerede.
      Bemærk: et Client Id og et Secret Id virker IKKE — det skal
      være en API-nøgle. (Client Id'et ER bare teksten "APIKey".)
- [x] **"Hesselbjerg" er godkendt som Sender-ID hos SureSMS**
      (16. 11. 2026). `sms_sender` står nu til `Hesselbjerg`, så
      beskederne kommer fra foreningens navn og ikke fra et
      telefonnummer. Husk at rette linjen i `config.local.php` på
      SERVEREN også — den følger ikke med et push.
- [ ] **Syv bestyrelseslogins mangler.** Der er kun én bruger
      (`MortenBoKristensen`). De oprettes på `opret-medlemmer.php` —
      husk at sætte Rolle til **Bestyrelse**. Siden er ikke linket fra
      nogen menu; skriv adressen direkte. (`setup.php` er låst for
      altid og kan ikke bruges til det.)
- [ ] **Del adgangskortene ud.** 203 postkort, ét pr. husstand, hentet
      fra OpenStreetMap. Før de printes:
      1. Scan ét kort med en telefon, og gå hele vejen igennem.
         QR-koderne er afprøvet maskinelt, men ikke med et rigtigt
         kamera på rigtigt papir.
      2. Gennemgå listen på `adgangskort.php` mod jeres egen
         medlemsliste. OpenStreetMap er ikke et folkeregister:
         **Rosenvænget 500A** ser forkert ud, og huse, der mangler i
         OSM, har slet ikke fået et kort. Tilføj og fjern på siden.
      3. Print først derefter. Husk, at printfilen indeholder ALLE
         koder — den må ikke sendes rundt eller gemmes.
- [ ] **Overvej at slette `setup.php`** fra git og serveren. Den er
      låst og kan ikke misbruges, men den er overflødig.
- [ ] **Skift SMTP-adgangskoden.** Den har været skrevet i en chat.
      Rettes ét sted: `includes/config.local.php`.
- [ ] **Tilmeldingslinket til Betalingsservice mangler.**
      `betalingsservice.html` har stadig `INDSAET_TILMELDINGSLINK_HER`,
      så knappen virker ikke, og vejledningsboksen er synlig for
      besøgende. Linket dannes i BS Customer Portal.
- [x] **Uffe Gangelhof mangler telefon og adresse** i
      `includes/bestyrelse.php`. Udfyldt.
- [ ] **Læg beboerkartoteket ind.** Gå til `beboer-import.php` og upload
      `Adressekartotek 2026 - faerdig.csv` (213 rækker). Filen må ALDRIG
      lægges i git — depotet er offentligt, og filen rummer navne,
      hjemmeadresser, mails og telefonnumre på ~200 mennesker.
- [x] **BFE-numre.** Hentet 16. 11. 2026 hos Datafordeleren for alle
      205 adresser — foreningens 203 plus Ristingevej 121 og 123 — og
      lagt i `includes/bfe.php`. Ejer-knappen i kartoteket er derfor et
      direkte link til OIS på hver eneste række.
      Fyrrevænget 1A er den eneste, der er slået op i hånden: BBR har
      hverken grund eller bygning på adressen. Nummeret står i
      `BFE_HAANDHOLDT` i `tools/bfe/hent-bfe.php` og kontrolleres mod
      matriklen ved hver kørsel.
- [ ] **Udenlandske mobilnumre får ingen SMS.** `sms_msisdn()` tager kun
      imod danske numre (45 + otte cifre) og springer stille resten over.
      Kartoteket har en `land`-kolonne, så der kan sidde medlemmer med et
      tysk eller svensk nummer. SureSMS kan sende til udlandet — det er
      vores egen kontrol, der står i vejen. Tjek, om der overhovedet er
      nogen, før der bruges tid på det.
- [ ] **Et login kan ikke lukkes fra hjemmesiden.** At spærre en kode eller
      give en ny på `adgangskort.php` stopper kun selve koden. De logins,
      der allerede er oprettet på adressen, bliver ved med at virke, så en
      tidligere ejer kan stadig logge ind og se beboerkartoteket. Siden
      siger det nu ligeud, men der mangler en knap, der sætter
      `users.is_active = 0` for adressen. Indtil da skal det gøres i
      databasen.
- [ ] **Ristingevej 121 og 123 kan ikke få en kode.** `adgangskort.php`
      tilbyder kun vejene fra `HBN_VEJE`, når man opretter et kort, og
      `adgang_mail_pr_husstand()` springer adresser uden for de 20 veje
      over. De to husstande kan derfor hverken få et postkort eller en
      mail. Hænger sammen med spørgsmålet om, hvorvidt vejen hører til
      foreningen — se punktet længere nede.
- [ ] **Seks BFE-numre er kun fundet ad én vej** (Enebærvænget 9,
      Hylleskovvænget 7, Lindevænget 4, Rosenvænget 500A, Syrenvænget 26,
      Ørnevænget 2). Det er ubebyggede grunde, så kun grundvejen gav svar
      — krydstjekket gennem matriklen kunne ikke bekræfte dem. Tjek dem,
      når I alligevel er på OIS.
- [ ] **28 rækker i kartoteket har en note.** Se dem på
      `beboerkartotek.php` under "med note". De vigtigste:
      *Odese SV* og *Koldeing* er stavefejl; Birkevænget 5 og
      Gulspurvevænget 1 mangler postnummer; Duevænget 10 har en ugyldig
      mail; og 18 rækker har en **gættet kontaktperson**, fordi mailen
      ikke pegede på nogen af navnene (fx `wulff@wulffmetal.dk` for
      "Dorte S. og Torben H. Pedersen").
- [ ] **Vejnavnet: Hyldeskovvænget eller Hylleskovvænget?** Kartoteket og
      vedtægterne skriver *Hyldeskov*; OpenStreetMap og
      `includes/bestyrelse.php` skriver *Hylleskov*. Hjemmesiden retter i
      dag til *Hylleskov* (`includes/adresser.php`), og 17 af de 203
      adgangskort er trykt med den stavemåde. Find ud af, hvad der er
      rigtigt, før kortene printes.
- [ ] **Ristingevej 121 og 123 står i kartoteket**, men Ristingevej er
      ikke en af de 20 veje i vedtægternes § 1. Enten mangler vejen i
      vedtægterne, eller også hører de to huse ikke til foreningen.
      **Spørgsmålet er stadig uafklaret og skal afgøres på en
      generalforsamling** — det er vedtægterne, der skal rettes, ikke
      hjemmesiden.
      De to huse har nu deres BFE-numre (10067097 og 3073368), så
      Ejer-knappen virker på dem. Men `ny-beboer.php` afviser fortsat
      Ristingevej som "uden for foreningen", og det er med vilje: vejen
      kommer først ind i `HBN_VEJE`, når vedtægterne siger, den hører til.
- [ ] **`residents`-tabellen kan slettes.** Ingen kode læser den mere:
      `ny-beboer.php` skriver i `beboerkartotek`, og `besked.php` henter
      nu også sine modtagere derfra. Se de gamle rækker efter, og drop
      så tabellen. `beboere.php` skal stadig fjernes fra serveren (se
      ovenfor).
- [ ] **Årshjulet: bestyrelsesmøde i september "inden generalforsamlingen".**
      Generalforsamlingen er sidste lørdag i august, så et møde i
      september ligger EFTER den, ikke før. Enten er måneden forkert
      (august?), eller også skal teksten laves om. Posten står på
      `aarshjul.php` præcis som oplyst — ret den under "Ret i årshjulet",
      når I ved, hvad der er rigtigt.
- [ ] **Slåfejl i vedtægternes § 1: "Hyldeskovvænget".** Vejen hedder
      Hylleskovvænget — sådan står den i OpenStreetMap, og sådan er to
      bestyrelsesmedlemmers adresser skrevet i `includes/bestyrelse.php`.
      Teksten på `vedtaegter.php` er med vilje IKKE rettet: en
      vedtægtsændring hører til på en generalforsamling (§ 9). Indtil da
      står der en fodnote under § 1. Tag den med som forslag næste gang.
- [ ] **Slet på serveren:** `bestyrelsen.html` og `beboere.php`. FileZilla
      fjerner ikke filer, så de ligger der sandsynligvis endnu.
      `beboere.php` viser beboeres navne, adresser og telefonnumre.
- [ ] **Tjek arkivet efter omlægningen til mapper.** `documents.category`
      gik fra `('generalforsamling','regnskab')` til
      `('dagsorden','referat','regnskab')`. `includes/db.php` flytter
      automatisk gamle `generalforsamling`-rækker over i `referat` første
      gang en side vises. Lå der dagsordener i arkivet i forvejen, står de
      nu under Referat og skal flyttes i hånden — eller lægges op igen.
- [ ] **Tjek at `includes/db.php` giver 403** i browseren. Gør den ikke
      det, er `.htaccess` ikke kommet med op, og databasens adgangskode
      kan hentes.
- [ ] **Tjek at `dokumenter/vedtaegter-2017-02-19.pdf` giver 403** i
      browseren — og at den samme PDF kan åbnes fra `vedtaegter.php`,
      når man er logget ind. Giver den direkte adresse 200, er
      `dokumenter/.htaccess` ikke kommet med op.
- [ ] **Overvej at flytte databasens adgangskode** ud af
      `includes/db.php`, som ligger i git, og ind i
      `config.local.php`, som ikke gør.

---

## 5. Overvejelser

- **"Download alle billeder som zip"** på medlemsfotos, hvis galleriet
  vokser.
- **Vejlisten står nu to steder.** `includes/adresser.php` har den
  rigtige stavemåde og bruges til at kontrollere adresser; `vedtaegter.php`
  har sin egen liste, fordi den skal gengive vedtægterne ordret — med
  slåfejlen. De to lister skal derfor IKKE slås sammen, men kommer en vej
  til eller fra, skal begge rettes. Det samme gælder vejene i
  `tools/kort/veje.overpassql`.
- **Menuen bag login fylder meget** — Vedtægter, Medlemsfotos,
  Generalforsamling og Beskeder kunne samles under ét punkt. Menuen
  bryder stadig over to linjer på en bred skærm, når man er logget ind
  som bestyrelse. (Regnskab er taget ud og ligger nu som mappe under
  Generalforsamling, så der er ét punkt færre end før.)
- **Fælles stylesheet.** CSS'en står i dag som `<style>` i hver enkelt
  fil — omkring 2.900 linjer, hvor menuen alene findes i ti kopier.
  Et fælles `style.css` ville gøre alle senere rettelser til ét sted.
  Større oprydning, gemt til der er ro til den.

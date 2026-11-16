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

- [ ] **GatewayAPI-nøgle** ind i `includes/config.local.php`, så SMS kan
      sendes. Mail virker allerede.
- [ ] **Syv bestyrelseslogins mangler.** Der er kun én bruger
      (`MortenBoKristensen`). `setup.php` har låst sig selv, så resten
      skal oprettes direkte i databasen — eller vi bygger en
      "Brugere"-side til bestyrelsen.
- [ ] **Skift SMTP-adgangskoden.** Den har været skrevet i en chat.
      Rettes ét sted: `includes/config.local.php`.
- [ ] **Tilmeldingslinket til Betalingsservice mangler.**
      `betalingsservice.html` har stadig `INDSAET_TILMELDINGSLINK_HER`,
      så knappen virker ikke, og vejledningsboksen er synlig for
      besøgende. Linket dannes i BS Customer Portal.
- [ ] **Uffe Gangelhof mangler telefon og adresse** i
      `includes/bestyrelse.php`. De øvrige syv er på plads.
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

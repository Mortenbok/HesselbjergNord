# TODO — hesselbjergnord.dk

Sidst opdateret: 13. september 2026

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
| `omraade.jpg` | 3,1 MB | ~300 KB |
| `vedtaegter.jpg` | 2,4 MB | ~250 KB |
| `bestyrelsen.jpg` | 1,7 MB | ~250 KB |
| `urlLogo.png` | 1,2 MB | ~100 KB |
| `aktiviteter.jpg` | 1,0 MB | ~200 KB |

Skaleres til 1920 px bred og gemmes som WebP med JPG som reserve.
Forventet: over 90 % mindre uden synlig forskel.

### 1.2 robots.txt og sitemap.xml mangler helt
Ingen af delene findes. Et sitemap betyder ekstra meget her, fordi fem
sider nu er skjult i menuen (se punkt 3) — Google kan ikke længere finde
dem ved at følge links.

### 1.3 Canonical-adresser mangler
Siden svarer på både `hesselbjergnord.dk` og `www.hesselbjergnord.dk`.
Uden `<link rel="canonical">` kan Google se det som to sider med samme
indhold og dele placeringen mellem dem.

### 1.4 Open Graph mangler på alle sider
Deles siden på Facebook — som er den måde, en grundejerforening spreder
sig på — vises den som et nøgent link uden billede og beskrivelse.

### 1.5 Beskrivelser mangler på fire sider
`omraade.html`, `vedtaegter.html`, `kontingent.html`, `aktiviteter.html`.

### 1.6 Strukturerede data
`Organization`/`LocalBusiness` i JSON-LD. Lille gevinst, hurtigt gjort.

---

## 2. Fire sider er reelt tomme  ← vigtigst af alt, og kun du kan gøre det

Målt synligt indhold:

| Side | Ord |
|---|---|
| `omraade.html` | ~39 |
| `vedtaegter.html` | ~32 |
| `kontingent.html` | ~32 |
| `aktiviteter.html` | ~32 |
| `betalingsservice.html` | ~345 |
| `hjertestarter.html` | ~260 |
| `index.html` | ~101 |

De fire første står stadig som "Under opbygning". Ingen meta-tags kan
løfte en tom side — der skal tekst på. Det er skrivearbejde, ikke kodning.

---

## 3. De fem skjulte sider er nu forældreløse

`omraade`, `vedtaegter`, `kontingent`, `aktiviteter` og
`betalingsservice` er taget ud af menuen, men findes stadig og svarer
med 200. Ingen sider linker til dem længere.

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
- [ ] **Slet på serveren:** `bestyrelsen.html` og `beboere.php`. FileZilla
      fjerner ikke filer, så de ligger der sandsynligvis endnu.
      `beboere.php` viser beboeres navne, adresser og telefonnumre.
- [ ] **Tjek at `includes/db.php` giver 403** i browseren. Gør den ikke
      det, er `.htaccess` ikke kommet med op, og databasens adgangskode
      kan hentes.
- [ ] **Overvej at flytte databasens adgangskode** ud af
      `includes/db.php`, som ligger i git, og ind i
      `config.local.php`, som ikke gør.

---

## 5. Overvejelser

- **"Download alle billeder som zip"** på medlemsfotos, hvis galleriet
  vokser.
- **Menuen bag login fylder meget** — Medlemsfotos, Generalforsamling,
  Regnskab og Beskeder kunne samles under ét punkt.
- **Fælles stylesheet.** CSS'en står i dag som `<style>` i hver enkelt
  fil — omkring 2.900 linjer, hvor menuen alene findes i ti kopier.
  Et fælles `style.css` ville gøre alle senere rettelser til ét sted.
  Større oprydning, gemt til der er ro til den.

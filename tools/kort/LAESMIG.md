# Sådan bygges kortet på `omraade.html`

`public_html/omraade.html` er en **færdigbygget fil**. Vejenes koordinater
ligger inde i selve siden, så den ikke skal hente data for at kunne tegne
kortet. Filen her forklarer, hvordan den bliver til — og hvordan du bygger
den igen, hvis en vej kommer til, forsvinder eller skifter navn.

Mappen `tools/` lægges **ikke** op på serveren. Udgivelsen tager kun
`public_html/`.

## Hvornår skal kortet bygges om?

- En vej kommer til eller forsvinder i foreningen
- En vej skifter navn
- OpenStreetMap har fået rettet et vejforløb, du gerne vil have med

Ellers skal du ikke røre det. Filen virker, som den står.

## Fremgangsmåde

Du skal bruge `php` og `curl` i en terminal. Stå i denne mappe.

### 1. Hent vejene fra OpenStreetMap

`veje.overpassql` indeholder listen over de 20 vejnavne og det område på
Sydlangeland, der søges i. Skal en vej til eller fra, så ret listen her.

```sh
curl -s -A "HesselbjergNord-kort/1.0" \
  --data-urlencode "data@veje.overpassql" \
  https://overpass-api.de/api/interpreter -o osm-raa.json
```

Åbn `osm-raa.json` og se efter, at alle veje kom med. Mangler en, er den
enten ikke i OpenStreetMap, eller også er den stavet anderledes dér.

> **Bemærk:** vejen hedder **Hylleskovvænget** i OpenStreetMap og i
> bestyrelsens egen adresseliste. Vedtægterne fra 2017 skriver
> "Hyldeskovvænget" — det er en slåfejl i vedtægterne, ikke i kortet.
> Se punkt 4 i `TODO.md`.

### 2. Beregn området

```sh
php 1-beregn-omraade.php
```

Den lægger en zone på 65 m ud til hver side af hver vej og tegner omridset
af det hele. Resultatet lægges i `data.json`.

Et konvekst hylster om vejene blev prøvet først, men duede ikke: det lagde
et stort stykke skov og søen Piledyb sydvest for området ind under
foreningen. Zonen følger i stedet vejene, som den skal.

Vil du have området til at fylde mere eller mindre, så ret `BUFFER` øverst
i filen.

### 3. Byg siden

```sh
php 2-byg-side.php
```

Den skriver `omraade.html` med data lagt ind i filen. Kopiér den over i
`public_html/`:

```sh
cp omraade.html ../../public_html/omraade.html
```

### 4. Se efter, at den virker

Åbn siden i en browser. Kortet skal vise alle veje med gult og området med
en stiplet blå kant. Tjek også, at tallene øverst passer — de bliver
beregnet undervejs og skrevet ud af `1-beregn-omraade.php`.

## Hvad siden henter udefra

| Hvad | Hvorfra |
|---|---|
| Leaflet (kortbiblioteket) | cdnjs.cloudflare.com |
| Kortfliser | tile.openstreetmap.org |
| Luftfoto | server.arcgisonline.com (Esri) |

Vejenes forløb er fra OpenStreetMap og er på ODbL-licens — derfor står der
en kildeangivelse nederst på siden. Den skal blive stående.

Kortet er **vejledende**. Det viser, hvilke veje foreningen dækker, og ikke
en matrikulær afgrænsning af området.

<?php
/**
 * Bygger den færdige kortside med vejdata lagt ind direkte i filen,
 * så siden ikke skal hente en ekstra fil for at kunne tegne kortet.
 */

$data = json_decode(file_get_contents(__DIR__ . '/data.json'), true);
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$antal  = count($data['roads']);
$hektar = number_format($data['hektar'], 0, ',', '.');

$html = <<<HTML
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Kort over Grundejerforeningen Hesselbjerg Nords område på Sydlangeland — de {$antal} veje, foreningen dækker.">
<title>Området — Grundejerforeningen Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body {
    min-height: 100%;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover;
    position: relative;
    color: #fff;
  }
  body::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    z-index: 0;
  }
  nav {
    position: relative;
    z-index: 2;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 20px 15px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.45), transparent);
  }
  .nav-links {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    flex: 1;
  }
  nav a, .login-btn {
    color: #fff;
    text-decoration: none;
    font-size: 0.95rem;
    letter-spacing: 1px;
    padding: 8px 16px;
    border-radius: 20px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    transition: background 0.25s ease;
  }
  nav a:hover, .login-btn:hover, nav a.active {
    background: rgba(255,255,255,0.18);
  }
  .nav-account {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 10px;
  }
  .nav-user {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.85);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    white-space: nowrap;
  }
  .login-btn {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.4);
    white-space: nowrap;
    cursor: pointer;
    font-family: inherit;
  }

  .container {
    position: relative;
    z-index: 1;
    max-width: 1100px;
    margin: 0 auto;
    padding: 20px 20px 70px;
  }
  .panel {
    background: rgba(10, 20, 25, 0.42);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(4px);
  }
  h1 {
    font-size: clamp(2rem, 4vw, 2.8rem);
    margin-bottom: 10px;
  }
  .lead {
    font-weight: 300;
    color: rgba(255,255,255,0.9);
    max-width: 64ch;
    line-height: 1.55;
  }

  /* Nøgletal over kortet. */
  .fakta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 18px;
  }
  .fakta div {
    padding: 10px 16px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.16);
    border-radius: 12px;
  }
  .fakta b { display: block; font-size: 1.25rem; }
  .fakta span { font-size: 0.8rem; color: rgba(255,255,255,0.6); }

  /* Selve kortet. */
  #kort {
    height: clamp(380px, 62vh, 620px);
    margin-top: 22px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.22);
    background: #14202a;
    z-index: 1;
  }
  .leaflet-container { font: inherit; }
  .leaflet-popup-content { font-size: 0.95rem; }
  .kort-hjaelp {
    margin-top: 10px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.6);
  }

  /* Vejlisten under kortet. */
  .veje-titel {
    margin-top: 30px;
    padding-top: 22px;
    border-top: 1px solid rgba(255,255,255,0.16);
    font-size: 1.1rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(150, 210, 255, 0.95);
    margin-bottom: 14px;
  }
  .veje {
    list-style: none;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 8px;
  }
  .veje button {
    width: 100%;
    text-align: left;
    font: inherit;
    font-weight: 300;
    color: #fff;
    cursor: pointer;
    padding: 9px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.16);
    background: rgba(255,255,255,0.05);
    transition: background 0.2s ease, border-color 0.2s ease;
  }
  .veje button:hover, .veje button:focus-visible {
    background: rgba(150, 210, 255, 0.22);
    border-color: rgba(150, 210, 255, 0.6);
    outline: none;
  }
  .veje .laengde {
    display: block;
    font-size: 0.78rem;
    color: rgba(255,255,255,0.55);
  }

  .kilde {
    margin-top: 24px;
    font-size: 0.8rem;
    color: rgba(255,255,255,0.55);
    line-height: 1.5;
  }
  .kilde a { color: rgba(170, 215, 255, 0.9); }

  footer {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 10px 10px 30px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.8);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }
</style>
<link rel="stylesheet" href="mobile-nav.css">
<script src="mobile-nav.js" defer></script>
</head>
<body>
  <nav>
    <div class="nav-links">
      <a href="index.html">Forside</a>
      <a href="bestyrelsen.php">Bestyrelsen</a>
      <a href="hjertestarter.html">Hjertestarter</a>
      <a href="omraade.html" class="active">Området</a>
      <a href="ny-beboer.php" data-guest-only>Ny beboer</a>
      <a href="vedtaegter.php" data-members-only hidden>Vedtægter</a>
      <a href="medlemsfotos.php" data-members-only hidden>Medlemsfotos</a>
      <a href="generalforsamling.php" data-members-only hidden>Generalforsamling</a>
      <a href="regnskab.php" data-members-only hidden>Regnskab</a>
      <a href="besked.php" data-board-only hidden>Beskeder</a>
    </div>
    <div class="nav-account">
      <span class="nav-user" data-user-name hidden></span>
      <a href="index.html?login=1" class="login-btn" data-open-login>Login</a>
      <a href="logout.php" class="login-btn" data-logout hidden>Log ud</a>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Området</h1>
      <p class="lead">Grundejerforeningen Hesselbjerg Nord dækker {$antal} veje i
        sommerhusområdet nord for Hesselbjerg på Sydlangeland — mellem Ristinge
        Strand og Ristinge Klint. Kortet herunder viser, hvilke veje der hører
        til foreningen.</p>

      <div class="fakta">
        <div><b>{$antal}</b><span>veje i foreningen</span></div>
        <div><b>4,1 km</b><span>vej i alt</span></div>
        <div><b>{$hektar} ha</b><span>område i alt</span></div>
        <div><b>1,0 × 0,7 km</b><span>udstrækning</span></div>
      </div>

      <div id="kort"></div>
      <p class="kort-hjaelp">Tryk på en vej i kortet eller i listen for at se, hvor den ligger.
        Skift mellem kort og luftfoto øverst til højre i kortet.</p>

      <h2 class="veje-titel">Foreningens veje</h2>
      <ul class="veje" id="vejliste"></ul>

      <p class="kilde">
        Kortgrundlag og vejenes forløb: &copy;
        <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>-bidragydere
        (ODbL). Luftfoto: Esri, Maxar, Earthstar Geographics.
        Vejenes placering er hentet fra OpenStreetMap og er vejledende —
        den er ikke en matrikulær afgrænsning af foreningens område.
      </p>
    </div>
  </div>

  <footer>&copy; <span id="aar">2026</span> Grundejerforeningen Hesselbjerg Nord</footer>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
  <script>
  (function () {
    'use strict';

    document.getElementById('aar').textContent = new Date().getFullYear();

    var DATA = {$json};

    var kort = L.map('kort', { scrollWheelZoom: false });

    var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-bidragydere'
    });

    var luftfoto = L.tileLayer(
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      { maxZoom: 19, attribution: 'Luftfoto: Esri, Maxar, Earthstar Geographics' }
    );

    osm.addTo(kort);
    L.control.layers({ 'Kort': osm, 'Luftfoto': luftfoto }, null, { position: 'topright' }).addTo(kort);

    // Selve området: en zone på 65 m ud til hver side af vejene. Et konvekst
    // hylster ville lægge skoven og søen sydvest for området ind under
    // foreningen — zonen følger i stedet vejene, som den skal.
    DATA.area.forEach(function (ring) {
      L.polygon(ring.map(function (p) { return [p[1], p[0]]; }), {
        color: "#8ad2ff",
        weight: 2,
        opacity: 0.9,
        dashArray: "7 6",
        fillColor: "#8ad2ff",
        fillOpacity: 0.14,
        interactive: false
      }).addTo(kort);
    });

    // Vejene tegnes helt dækkende i deres eget lag, og gennemsigtigheden
    // lægges på HELE laget bagefter. Sætter man den på hver enkelt streg,
    // bliver krydsene mørkere end resten, fordi to halvgennemsigtige streger
    // lægger sig oven på hinanden — så ligner nettet lapper i stedet for én
    // sammenhængende streg.
    kort.createPane('veje');
    kort.getPane('veje').style.zIndex = 450;
    kort.getPane('veje').style.opacity = 0.72;

    var VEJ_FARVE = '#ffd24a';
    var AKTIV_FARVE = '#ff7a59';
    var lag = {};

    Object.keys(DATA.roads).forEach(function (navn) {
      var gruppe = L.layerGroup().addTo(kort);

      DATA.roads[navn].forEach(function (segment) {
        var streg = L.polyline(segment.map(function (p) { return [p[1], p[0]]; }), {
          pane: 'veje',
          color: VEJ_FARVE,
          weight: 6,
          opacity: 1,
          lineCap: 'round',
          lineJoin: 'round'
        }).addTo(gruppe);

        streg.bindPopup('<strong>' + navn + '</strong>');
        streg.on('click', function () { fremhaev(navn); });
      });

      lag[navn] = gruppe;
    });

    var aktiv = null;

    function fremhaev(navn) {
      // Hver gruppe indeholder nu kun vejstreger, så der skal ikke skelnes
      // mellem stregtykkelser for at finde den rigtige at rette.
      if (aktiv && lag[aktiv]) {
        lag[aktiv].eachLayer(function (l) {
          l.setStyle({ color: VEJ_FARVE, weight: 6 });
        });
      }

      aktiv = navn;

      lag[navn].eachLayer(function (l) {
        l.setStyle({ color: AKTIV_FARVE, weight: 8 });
      });

      kort.fitBounds(lag[navn].getLayers()[0].getBounds(), { maxZoom: 18, padding: [60, 60] });

      document.querySelectorAll('#vejliste button').forEach(function (b) {
        b.style.background = b.dataset.navn === navn ? 'rgba(255,122,89,0.3)' : '';
      });
    }

    // Vejlisten under kortet.
    var liste = document.getElementById('vejliste');
    var mLat = 111320;
    var mLon = 111320 * Math.cos(DATA.center[0] * Math.PI / 180);

    Object.keys(DATA.roads).sort(function (a, b) {
      return a.localeCompare(b, 'da');
    }).forEach(function (navn) {
      var meter = 0;

      DATA.roads[navn].forEach(function (segment) {
        for (var i = 1; i < segment.length; i++) {
          var dx = (segment[i][0] - segment[i-1][0]) * mLon;
          var dy = (segment[i][1] - segment[i-1][1]) * mLat;
          meter += Math.sqrt(dx * dx + dy * dy);
        }
      });

      var li = document.createElement('li');
      var knap = document.createElement('button');
      knap.type = 'button';
      knap.dataset.navn = navn;
      knap.innerHTML = navn + '<span class="laengde">' + Math.round(meter) + ' m</span>';
      knap.addEventListener('click', function () { fremhaev(navn); });
      li.appendChild(knap);
      liste.appendChild(li);
    });

    kort.fitBounds([[DATA.bounds[0][0], DATA.bounds[0][1]], [DATA.bounds[1][0], DATA.bounds[1][1]]],
      { padding: [30, 30] });

    // Rullehjulet skal ikke stjæle siden, når man scroller forbi kortet.
    kort.on('click', function () { kort.scrollWheelZoom.enable(); });
    kort.on('mouseout', function () { kort.scrollWheelZoom.disable(); });
  })();
  </script>

  <script>
    // Navigationen tilpasses efter, om den besøgende er logget ind.
    fetch('session-status.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (status) {
        if (!status || !status.loggedIn) return;

        if (status.role === 'bestyrelse') {
          document.querySelectorAll('[data-board-only]').forEach(function (el) { el.hidden = false; });
        }

        document.querySelectorAll('[data-guest-only]').forEach(function (el) { el.hidden = true; });
        document.querySelectorAll('[data-members-only], [data-logout]').forEach(function (el) { el.hidden = false; });
        document.querySelectorAll('[data-open-login]').forEach(function (el) { el.hidden = true; });
        document.querySelectorAll('[data-user-name]').forEach(function (el) {
          el.textContent = status.displayName || '';
          el.hidden = !status.displayName;
        });
      })
      .catch(function () {});
  </script>
</body>
</html>
HTML;

file_put_contents(__DIR__ . '/omraade.html', $html);
printf("omraade.html: %d bytes, %d veje\n", strlen($html), $antal);

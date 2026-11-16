/**
 * Mobilmenu — fælles for alle sider.
 *
 * Knappen bygges her i stedet for i HTML'en, så de ti sider, der hver har
 * deres egen kopi af menuen, ikke skal rettes enkeltvis.
 *
 * Uden JavaScript sker der ingenting: .has-toggle sættes aldrig, og menuen
 * står som før. Det er med vilje — en menu, der kun kan åbnes med JavaScript,
 * må ikke kunne skjule sig selv permanent.
 */
(function () {
  'use strict';

  var nav = document.querySelector('nav');
  if (!nav) return;

  var links = nav.querySelector('.nav-links');
  if (!links) return;

  if (!links.id) links.id = 'navLinks';

  var toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'nav-toggle';
  toggle.setAttribute('aria-controls', links.id);
  toggle.setAttribute('aria-expanded', 'false');
  toggle.setAttribute('aria-label', 'Menu');
  toggle.innerHTML = '<span></span><span></span><span></span>';

  nav.insertBefore(toggle, nav.firstChild);
  nav.classList.add('has-toggle');

  function setOpen(open) {
    nav.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  toggle.addEventListener('click', function () {
    setOpen(!nav.classList.contains('open'));
  });

  // Luk, når der vælges et punkt — ellers bliver menuen stående på den nye side.
  links.addEventListener('click', function (event) {
    if (event.target.closest('a')) setOpen(false);
  });

  // Luk ved tryk udenfor.
  document.addEventListener('click', function (event) {
    if (nav.classList.contains('open') && !nav.contains(event.target)) setOpen(false);
  });

  // Luk på Esc, og giv fokus tilbage til knappen.
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && nav.classList.contains('open')) {
      setOpen(false);
      toggle.focus();
    }
  });

  // Grænsen for, hvornår bjælken står på én linje. Bruges både af klodsen
  // herunder og af oprydningen nederst.
  var wide = window.matchMedia('(min-width: 761px)');

  /* -------------------------------------------------------------------
   * Linkene skal stå midt på siden — ikke midt i den plads, der er til
   * overs ved siden af login-boksen.
   *
   * mobile-nav.css lægger en tom klods til venstre for linkene. Dens
   * bredde kan CSS ikke regne ud: den skal være lige så bred som
   * login-boksen, og den vokser med brugernavnets længde. Så vi måler
   * boksen og giver klodsen målet med.
   *
   * Men klodsen koster linkene plads. Er bestyrelsen logget ind, er der
   * elleve links, og på en smal skærm ville de blive presset ned i seks
   * eller ni rækker, bare for at midten passer. Derfor PRØVER vi klodsen
   * af: bliver linklisten højere af den, ruller vi den tilbage. Så
   * centrerer bjælken sig selv, når der er råd, og står som før, når der
   * ikke er. Ingen fast grænse at gætte på.
   *
   * Uden JavaScript sker der ingenting: klodsen falder tilbage på 0, og
   * bjælken står som før. Skævt, men ikke i stykker.
   * ----------------------------------------------------------------- */
  var konto = nav.querySelector('.nav-account');

  if (konto) {
    var maaler = false;

    var maalKlods = function () {
      // Ingen klods, mens menuen er foldet sammen under bjælken.
      if (maaler || !wide.matches) {
        if (!wide.matches) nav.style.setProperty('--nav-klods', '0px');
        return;
      }

      maaler = true;

      var saet = function (px) { nav.style.setProperty('--nav-klods', px + 'px'); };

      saet(0);
      var hoejde = links.offsetHeight;          // antal rækker uden klods
      var oensket = Math.round(konto.offsetWidth);

      saet(oensket);

      if (links.offsetHeight > hoejde) {
        // Den fulde klods ville skubbe et link ned i en ny række. Find i
        // stedet den bredeste klods, der ikke koster noget: halv centrering
        // er bedre end ingen, og bjælken bliver ikke højere.
        var lav = 0, hoej = oensket;

        while (hoej - lav > 2) {
          var midt = Math.round((lav + hoej) / 2);
          saet(midt);

          if (links.offsetHeight > hoejde) { hoej = midt; } else { lav = midt; }
        }

        saet(lav);
      }

      maaler = false;
    };

    maalKlods();

    // Brugernavnet kommer fra session-status.php et øjeblik efter, siden er
    // hentet, og så bliver boksen bredere. ResizeObserver fanger det uden at
    // vi skal gætte på en forsinkelse.
    if (window.ResizeObserver) {
      new ResizeObserver(maalKlods).observe(konto);
    } else {
      window.addEventListener('load', maalKlods);
    }

    window.addEventListener('resize', maalKlods);
  }

  // Hvis skærmen bliver bred igen, skal menuen ikke stå "åben" i baggrunden.
  var onChange = function (event) { if (event.matches) setOpen(false); };
  if (wide.addEventListener) wide.addEventListener('change', onChange);
  else if (wide.addListener) wide.addListener(onChange);
})();

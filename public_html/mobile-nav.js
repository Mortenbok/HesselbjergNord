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

  // Hvis skærmen bliver bred igen, skal menuen ikke stå "åben" i baggrunden.
  var wide = window.matchMedia('(min-width: 761px)');
  var onChange = function (event) { if (event.matches) setOpen(false); };
  if (wide.addEventListener) wide.addEventListener('change', onChange);
  else if (wide.addListener) wide.addListener(onChange);
})();

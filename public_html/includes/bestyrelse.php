<?php
/**
 * Bestyrelsens sammensætning — ét sted at rette navne, poster og kontaktdata.
 *
 * bestyrelsen.php viser listen i to udgaver: uden login kun navn og post, med
 * login også telefon, mail og adresse. Derfor ligger oplysningerne her og ikke
 * i selve HTML'en.
 *
 * Sådan retter du listen:
 *   name      Fulde navn.
 *   role      Posten, som den skal stå på siden.
 *   initials  Vises i cirklen, indtil der er lagt et billede op.
 *   photo     Filnavn i mappen bestyrelse/ (kvadratisk, ca. 300x300 px).
 *             Tom streng = brug initialerne.
 *   phone     Telefonnummer med landekode, fx '+4512345678'. Tom = vises som —
 *   mail      Mailadresse. Tom = vises som —
 *   address   Adresse; brug '<br>' mellem vej og postnummer. Tom = vises som —
 *
 * Telefon, mail og adresse vises KUN for medlemmer, der er logget ind.
 */

const BOARD_MEMBERS = [
    [
        'name' => 'Uffe Gangelhof',
        'role' => 'Formand',
        'initials' => 'UG',
        'photo' => '',
        'phone' => '',
        'mail' => 'formand@hesselbjergnord.dk',
        'address' => '',
    ],
    [
        'name' => 'Morten Dupont',
        'role' => 'Næstformand',
        'initials' => 'MD',
        'photo' => '',
        'phone' => '',
        'mail' => 'naestformand@hesselbjergnord.dk',
        'address' => '',
    ],
    [
        'name' => 'Jette Hansen',
        'role' => 'Kasserer',
        'initials' => 'JH',
        'photo' => '',
        'phone' => '',
        'mail' => 'kasser@hesselbjergnord.dk',
        'address' => '',
    ],
    [
        'name' => 'Lars Klausen',
        'role' => 'Bestyrelseskoordinator',
        'initials' => 'LK',
        'photo' => '',
        'phone' => '',
        'mail' => 'bestyrelsen@hesselbjergnord.dk',
        'address' => '',
    ],
    [
        'name' => 'Tea Sose',
        'role' => 'Eventkoordinator',
        'initials' => 'TS',
        'photo' => '',
        'phone' => '',
        'mail' => 'bestyrelsen@hesselbjergnord.dk',
        'address' => '',
    ],
    [
        'name' => 'Morten Bo Kristensen',
        'role' => 'Vejudvalg',
        'initials' => 'MBK',
        'photo' => '',
        'phone' => '',
        'mail' => 'bestyrelsen@hesselbjergnord.dk',
        'address' => '',
    ],
    [
        'name' => 'Jesper Beck Holm',
        'role' => 'Vejudvalg',
        'initials' => 'JBH',
        'photo' => '',
        'phone' => '',
        'mail' => 'bestyrelsen@hesselbjergnord.dk',
        'address' => '',
    ],
    [
        'name' => 'Torben Holstebro',
        'role' => 'Vejudvalg',
        'initials' => 'TH',
        'photo' => '',
        'phone' => '',
        'mail' => 'bestyrelsen@hesselbjergnord.dk',
        'address' => '',
    ],
];

/** Kort for htmlspecialchars med sidens faste indstillinger. */
function board_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Pænt opsat telefonnummer: '+4512345678' vises som '12 34 56 78'. */
function board_format_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);

    if (strpos($digits, '45') === 0 && strlen($digits) === 10) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 8) {
        return implode(' ', str_split($digits, 2));
    }

    return $phone;
}

/**
 * Skriver bestyrelseskortene ud.
 *
 * $withContact = false giver kun navn og post — det er den udgave, alle kan se.
 * $withContact = true tilføjer telefon, mail og adresse til medlemmer, der er
 * logget ind.
 */
function board_render_cards(bool $withContact): void
{
    foreach (BOARD_MEMBERS as $member) {
        ?>
      <article class="member<?php echo $withContact ? '' : ' member-compact'; ?>">
        <div class="member-photo">
          <?php if ($member['photo'] !== ''): ?>
            <img src="bestyrelse/<?php echo board_e($member['photo']); ?>"
                 alt="<?php echo board_e($member['name']); ?>">
          <?php else: ?>
            <span class="initials"><?php echo board_e($member['initials']); ?></span>
          <?php endif; ?>
        </div>
        <div class="member-body">
          <p class="member-name"><?php echo board_e($member['name']); ?></p>
          <p class="member-role"><?php echo board_e($member['role']); ?></p>
          <?php if ($withContact): ?>
            <dl class="member-contact">
              <dt>Tlf.</dt>
              <dd>
                <?php if ($member['phone'] !== ''): ?>
                  <a href="tel:<?php echo board_e($member['phone']); ?>"><?php
                      echo board_e(board_format_phone($member['phone']));
                  ?></a>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </dd>

              <dt>Mail</dt>
              <dd>
                <?php if ($member['mail'] !== ''): ?>
                  <a href="mailto:<?php echo board_e($member['mail']); ?>"><?php
                      echo board_e($member['mail']);
                  ?></a>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </dd>

              <dt>Adresse</dt>
              <dd>
                <?php if ($member['address'] !== ''): ?>
                  <address><?php
                      // Kun <br> er tilladt i adressen; resten escapes.
                      echo str_replace('&lt;br&gt;', '<br>', board_e($member['address']));
                  ?></address>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </dd>
            </dl>
          <?php endif; ?>
        </div>
      </article>
        <?php
    }
}

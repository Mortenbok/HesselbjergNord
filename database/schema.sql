-- Hesselbjerg Nord — databaseskema
--
-- Importer denne fil i phpMyAdmin (eller via CLI):
--   mysql -h mysql37.unoeuro.com -u hesselbjergnord_dk -p hesselbjergnord_dk_db < database/schema.sql
--
-- Bemærk: includes/db.php opretter de samme tabeller automatisk ved første
-- sidevisning, så importen er kun nødvendig, hvis du vil oprette dem manuelt.
--
-- Denne fil ligger med vilje UDEN FOR public_html, så den ikke kan hentes
-- ned fra hjemmesiden.

-- ---------------------------------------------------------------------------
-- users — logins til medlems-/bestyrelsesområdet
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('bestyrelse', 'medlem') NOT NULL DEFAULT 'medlem',
  address VARCHAR(255) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- member_photos — medlemmernes uploadede billeder
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS member_photos (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NULL DEFAULT NULL,
  member_name VARCHAR(255) NOT NULL DEFAULT 'Medlem',
  file_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_member_photos_file (file_name),
  KEY idx_member_photos_user (user_id),
  KEY idx_member_photos_created (created_at),
  CONSTRAINT fk_member_photos_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- documents — dokumentarkivet bag login (generalforsamling.php)
-- Mapperne er dagsorden, referat og regnskab. Regnskab havde før sin egen
-- side; includes/db.php flytter gamle generalforsamling-rækker til referat.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
  id INT NOT NULL AUTO_INCREMENT,
  category ENUM('dagsorden', 'referat', 'regnskab') NOT NULL,
  doc_year SMALLINT NOT NULL,
  title VARCHAR(255) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL DEFAULT 'application/pdf',
  file_size INT NOT NULL DEFAULT 0,
  uploaded_by INT NULL DEFAULT NULL,
  uploader_name VARCHAR(255) NOT NULL DEFAULT 'Bestyrelsen',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_documents_file (file_name),
  KEY idx_documents_category (category, doc_year),
  KEY idx_documents_user (uploaded_by),
  CONSTRAINT fk_documents_user
    FOREIGN KEY (uploaded_by) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Opret den første bruger
-- ---------------------------------------------------------------------------
-- Adgangskoder gemmes ALDRIG i klartekst. Åbn https://hesselbjergnord.dk/setup.php
-- én gang for at oprette den første bruger — siden låser sig selv bagefter.
--
-- Alternativt: generér en hash i PHP og indsæt den manuelt:
--   php -r "echo password_hash('din-kode-her', PASSWORD_DEFAULT), PHP_EOL;"
--
-- INSERT INTO users (username, display_name, password_hash, role)
-- VALUES ('bestyrelsen', 'Bestyrelsen', '$2y$10$INDSÆT_HASH_HER', 'bestyrelse');

-- ---------------------------------------------------------------------------
-- adgangskort — ét postkort med QR-kode pr. husstand
-- ---------------------------------------------------------------------------
-- Kortet hører til adressen, ikke til personen. At kortet er afleveret i
-- postkassen er beviset for, at modtageren hører til adressen — derfor skal
-- bestyrelsen ikke godkende nogen i hånden.
--
-- Koden brænder ikke ved brug: begge ejere kan oprette hver deres login, og
-- et glemt kodeord klares ved at scanne igen. Skifter huset ejer, får kortet
-- en ny kode fra adgangskort.php, og det gamle postkort dør.
--
-- includes/db.php laver ét kort pr. adresse i includes/husstande.php, første
-- gang tabellen er tom.
CREATE TABLE IF NOT EXISTS adgangskort (
  id INT NOT NULL AUTO_INCREMENT,
  adresse VARCHAR(255) NOT NULL,
  kode CHAR(8) NOT NULL,
  spaerret TINYINT(1) NOT NULL DEFAULT 0,
  antal_brug INT NOT NULL DEFAULT 0,
  sidst_brugt DATETIME NULL DEFAULT NULL,
  sendt_at DATETIME NULL DEFAULT NULL,
  sendt_antal INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_adgangskort_kode (kode),
  UNIQUE KEY uniq_adgangskort_adresse (adresse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- aarshjul — indholdet på aarshjul.php (Årshjul / Aktiviteter)
-- ---------------------------------------------------------------------------
-- Siden er offentlig, men bestyrelsen retter den i browseren. Derfor ligger
-- teksterne her og ikke i HTML. includes/db.php fylder tabellen ud med
-- bestyrelsens oplysninger, første gang den er tom.
--
-- beregning: tom = datoen skrives i hånden i tidspunkt.
--   sidste_loerdag_august      generalforsamlingen
--   loerdag_foer_palmesoendag  arbejdsdagen (følger påsken)
-- Begge regnes ud for det år, siden vises i, og skal aldrig rettes.
CREATE TABLE IF NOT EXISTS aarshjul (
  id INT NOT NULL AUTO_INCREMENT,
  kategori ENUM('bestyrelsen', 'generalforsamling', 'socialt', 'andet') NOT NULL,
  titel VARCHAR(200) NOT NULL,
  tidspunkt VARCHAR(120) NOT NULL DEFAULT '',
  maaned TINYINT NULL DEFAULT NULL,
  beregning VARCHAR(40) NOT NULL DEFAULT '',
  beskrivelse TEXT NOT NULL,
  sortering SMALLINT NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_aarshjul_sort (sortering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- beboerkartotek — hvem bor hvor
-- ---------------------------------------------------------------------------
-- Fyldes først med bestyrelsens regneark via beboer-import.php (filen
-- uploades; den må IKKE ligge i git, da depotet er offentligt), og derefter
-- af ny-beboer.php, når nye beboere tilmelder sig.
--
-- Tilmeldingen spørger kun om det nødvendige. Beboeren får en mail med et
-- link (token), og udfylder hjemmeadressen på beboer-oplysninger.php.
-- Når det er gjort, ryddes token, og status bliver aktiv.
--
-- INDEHOLDER PERSONOPLYSNINGER. Kun bestyrelsen må se tabellen.
CREATE TABLE IF NOT EXISTS beboerkartotek (
  id INT NOT NULL AUTO_INCREMENT,
  forhold ENUM('ejer', 'medejer') NOT NULL DEFAULT 'ejer',
  sommerhusadresse VARCHAR(255) NOT NULL,
  vej VARCHAR(100) NOT NULL DEFAULT '',
  nr VARCHAR(10) NOT NULL DEFAULT '',
  bestyrelse TINYINT(1) NOT NULL DEFAULT 0,
  kontaktperson VARCHAR(255) NOT NULL,
  hjemmeadresse VARCHAR(255) NOT NULL DEFAULT '',
  sted VARCHAR(100) NOT NULL DEFAULT '',
  postnr VARCHAR(12) NOT NULL DEFAULT '',
  `by` VARCHAR(100) NOT NULL DEFAULT '',
  land VARCHAR(80) NOT NULL DEFAULT '',
  mail VARCHAR(255) NOT NULL DEFAULT '',
  mail2 VARCHAR(255) NOT NULL DEFAULT '',
  telefon VARCHAR(32) NOT NULL DEFAULT '',
  telefon2 VARCHAR(32) NOT NULL DEFAULT '',
  rolle VARCHAR(60) NOT NULL DEFAULT '',
  flyttet_ind DATE NULL DEFAULT NULL,
  noter TEXT NULL DEFAULT NULL,
  kilde ENUM('kartotek', 'ny-beboer') NOT NULL DEFAULT 'kartotek',
  status ENUM('aktiv', 'afventer_oplysninger') NOT NULL DEFAULT 'aktiv',
  token CHAR(32) NULL DEFAULT NULL,
  token_udloeb DATETIME NULL DEFAULT NULL,
  bekraeftet_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_beboer_token (token),
  KEY idx_beboer_adresse (sommerhusadresse),
  KEY idx_beboer_mail (mail),
  KEY idx_beboer_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- residents — tilmeldinger fra "Ny beboer"-formularen
-- ---------------------------------------------------------------------------
-- Formularen er offentlig, så alle kan sende oplysninger ind. Selve listen
-- kan kun ses af bestyrelsen på beboere.php.
--
-- Bemærk: her ligger personoplysninger om navngivne mennesker. Slet rækker,
-- når de er skrevet ind i medlemslisten — se GDPR-noten i beboere.php.
CREATE TABLE IF NOT EXISTS residents (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  address VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NOT NULL DEFAULT '',
  moved_in DATE NULL DEFAULT NULL,
  note TEXT NULL DEFAULT NULL,
  consent TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('ny', 'behandlet') NOT NULL DEFAULT 'ny',
  handled_by INT NULL DEFAULT NULL,
  handled_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_residents_status (status, created_at),
  KEY idx_residents_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
-- documents — dokumentarkivet bag login (generalforsamling.php, regnskab.php)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
  id INT NOT NULL AUTO_INCREMENT,
  category ENUM('generalforsamling', 'regnskab') NOT NULL,
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

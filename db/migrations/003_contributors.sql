-- ---------------------------------------------------------------------------
-- 003_contributors — sign-in by SMS code, and articles written by readers
--
-- A contributor is someone who chose to make an account, not a visitor: the
-- public site still keeps no per-visitor row and sets no cookie. Even so the
-- phone number is never stored. What is kept is an HMAC of it under a secret
-- pepper from config.local.php, which is enough to recognise a returning
-- number and useless for contacting or identifying anyone without the pepper.
--
-- MariaDB 10.3: no JSON type (LONGTEXT), no RETURNING, ADD COLUMN IF NOT
-- EXISTS is available.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `contributors` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone_hash`     CHAR(64) NOT NULL COMMENT 'HMAC-SHA256 of the E.164 number under security.phone_pepper',
  `display_name`   VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'the byline they chose; may be empty',
  `status`         ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `approved_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `rejected_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_contributors_phone` (`phone_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contributor_sessions` (
  `id`             CHAR(64) NOT NULL COMMENT 'sha256 of the cookie value; the value itself is never stored',
  `contributor_id` INT UNSIGNED NOT NULL,
  `ua_hash`        CHAR(64) NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`   DATETIME NULL,
  `expires_at`     INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_csess_contributor` (`contributor_id`),
  KEY `idx_csess_expiry` (`expires_at`),
  CONSTRAINT `fk_csess_contributor` FOREIGN KEY (`contributor_id`) REFERENCES `contributors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per code sent. Times are integer Unix seconds written by PHP.
-- Rows older than a day are swept; nothing here outlives its purpose.
CREATE TABLE IF NOT EXISTS `otp_requests` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone_hash`  CHAR(64) NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'sha256 of the random value the browser holds for this request',
  `code_hash`   CHAR(64) NOT NULL,
  `ip_hash`     CHAR(64) NOT NULL COMMENT 'daily-salted, as everywhere else',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `consumed`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  INT UNSIGNED NOT NULL,
  `expires_at`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_otp_phone` (`phone_hash`, `created_at`),
  KEY `idx_otp_ip` (`ip_hash`, `created_at`),
  KEY `idx_otp_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submissions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contributor_id` INT UNSIGNED NOT NULL,
  `field_id`       INT UNSIGNED NOT NULL,
  `kind`           ENUM('recipe','guide','topic') NOT NULL DEFAULT 'recipe',
  `title_fa`       VARCHAR(250) NOT NULL,
  `summary_fa`     TEXT NULL,
  `doc`            LONGTEXT NOT NULL COMMENT 'composer/1 document',
  `hero_media_id`  INT UNSIGNED NULL,
  `status`         ENUM('pending','needs_changes','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  `findings`       LONGTEXT NULL COMMENT 'citation check at submission, JSON',
  `judge_verdict`  ENUM('approve','revise','reject') NULL,
  `judge_score`    TINYINT UNSIGNED NULL,
  `judge_notes`    LONGTEXT NULL COMMENT 'JSON: summary, issues',
  `judge_model`    VARCHAR(80) NULL,
  `judged_at`      DATETIME NULL,
  `decided_by`     ENUM('owner','judge') NULL,
  `reviewer_note`  TEXT NULL COMMENT 'shown to the contributor',
  `reviewed_by`    INT UNSIGNED NULL,
  `reviewed_at`    DATETIME NULL,
  `article_id`     INT UNSIGNED NULL,
  `revision`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub_status` (`status`, `created_at`),
  KEY `idx_sub_contributor` (`contributor_id`, `status`),
  CONSTRAINT `fk_sub_contributor` FOREIGN KEY (`contributor_id`) REFERENCES `contributors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_field` FOREIGN KEY (`field_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The byline on a contributed article, and who uploaded an image (for the
-- per-contributor upload allowance).
ALTER TABLE `articles`
  ADD COLUMN IF NOT EXISTS `author_contributor_id` INT UNSIGNED NULL AFTER `ai_job_id`,
  ADD COLUMN IF NOT EXISTS `author_display` VARCHAR(80) NULL AFTER `author_contributor_id`;

ALTER TABLE `media`
  ADD COLUMN IF NOT EXISTS `contributor_id` INT UNSIGNED NULL AFTER `source_url`;

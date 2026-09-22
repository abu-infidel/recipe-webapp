-- ---------------------------------------------------------------------------
-- 001_init — core schema
--
-- Target: MariaDB 10.3 (what the cPanel host runs).
-- Deliberately avoided: native JSON type, INSERT..RETURNING, JSON_TABLE,
-- ALTER..RENAME COLUMN. JSON payloads are LONGTEXT and parsed in PHP.
--
-- Long paths are looked up by SHA-1 hash rather than by a unique index on the
-- path itself: a 8-deep Persian path exceeds InnoDB's 3072-byte index limit.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `migrations` (
  `version`    VARCHAR(20) NOT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- content tree

CREATE TABLE IF NOT EXISTS `fields` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`     INT UNSIGNED NULL,
  `slug`          VARCHAR(140) NOT NULL,
  `path`          VARCHAR(1000) NOT NULL COMMENT 'materialised: cooking/persian/stews',
  `path_hash`     CHAR(40) NOT NULL COMMENT 'sha1(path) — path itself is too long to index',
  `depth`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `title_fa`      VARCHAR(200) NOT NULL,
  `blurb_fa`      VARCHAR(400) NULL,
  `icon`          VARCHAR(40) NULL,
  `accent_color`  CHAR(7) NULL,
  `sort_order`    INT NOT NULL DEFAULT 0,
  `is_published`  TINYINT(1) NOT NULL DEFAULT 0,
  `article_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'published articles directly in this field',
  `subtree_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'published articles in this field and below',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fields_path` (`path_hash`),
  KEY `idx_fields_parent` (`parent_id`, `sort_order`),
  KEY `idx_fields_path_prefix` (`path`(191)),
  KEY `idx_fields_published` (`is_published`, `depth`),
  CONSTRAINT `fk_fields_parent` FOREIGN KEY (`parent_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- articles

CREATE TABLE IF NOT EXISTS `articles` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_id`        INT UNSIGNED NOT NULL,
  `slug`            VARCHAR(140) NOT NULL,
  `kind`            ENUM('recipe','guide','topic') NOT NULL DEFAULT 'guide',
  `status`          ENUM('draft','in_review','published','archived') NOT NULL DEFAULT 'draft',
  `title_fa`        VARCHAR(250) NOT NULL,
  `summary_fa`      TEXT NULL,
  `body_html`       LONGTEXT NULL COMMENT 'rendered, auto-linked, ready to serve',
  `body_json`       LONGTEXT NULL COMMENT 'structured source of truth from the pipeline',
  `toc_json`        LONGTEXT NULL COMMENT 'generated at publish from the headings',
  `recipe_json`     LONGTEXT NULL COMMENT 'ingredients, yield, times — recipes only',
  `hero_media_id`   INT UNSIGNED NULL,
  `reading_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `quality_flags`   LONGTEXT NULL COMMENT 'unresolved validator warnings, JSON array',
  `ai_model`        VARCHAR(80) NULL,
  `ai_job_id`       INT UNSIGNED NULL,
  `view_count`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'aggregate only — never per visitor',
  `version`         INT UNSIGNED NOT NULL DEFAULT 1,
  `published_at`    DATETIME NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_articles_field_slug` (`field_id`, `slug`),
  KEY `idx_articles_status` (`status`, `published_at`),
  KEY `idx_articles_field_status` (`field_id`, `status`),
  KEY `idx_articles_kind` (`kind`, `status`),
  CONSTRAINT `fk_articles_field` FOREIGN KEY (`field_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `article_versions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` INT UNSIGNED NOT NULL,
  `version`    INT UNSIGNED NOT NULL,
  `snapshot`   LONGTEXT NOT NULL COMMENT 'full article row as JSON — every publish is reversible',
  `editor_id`  INT UNSIGNED NULL,
  `note`       VARCHAR(400) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_article_version` (`article_id`, `version`),
  CONSTRAINT `fk_versions_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- provenance
-- Every page the worker fetched. The References section is generated from
-- these rows, and the citation validator checks drafts against them.

CREATE TABLE IF NOT EXISTS `sources` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `url`            VARCHAR(2048) NOT NULL,
  `url_hash`       CHAR(40) NOT NULL COMMENT 'sha1(url) — dedupes refetches',
  `domain`         VARCHAR(255) NOT NULL,
  `title`          VARCHAR(500) NULL,
  `author`         VARCHAR(255) NULL,
  `published_date` DATE NULL,
  `lang`           VARCHAR(10) NULL,
  `http_status`    SMALLINT UNSIGNED NULL,
  `content_hash`   CHAR(40) NULL COMMENT 'sha1(extracted_text) — detects silent edits',
  `extracted_text` LONGTEXT NULL COMMENT 'Readability output, kept so review can show the quote',
  `trust_tier`     TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1 best .. 5 worst',
  `fetched_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sources_url` (`url_hash`),
  KEY `idx_sources_domain` (`domain`),
  KEY `idx_sources_trust` (`trust_tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The numbered bibliography shown on the References page.
CREATE TABLE IF NOT EXISTS `article_sources` (
  `article_id` INT UNSIGNED NOT NULL,
  `source_id`  INT UNSIGNED NOT NULL,
  `marker`     SMALLINT UNSIGNED NOT NULL COMMENT 'the [n] shown in the text',
  `accessed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`article_id`, `source_id`),
  UNIQUE KEY `uniq_article_marker` (`article_id`, `marker`),
  CONSTRAINT `fk_artsrc_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_artsrc_source` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paragraph-level claim -> source links. Drives the review screen's
-- click-a-citation-to-see-the-supporting-text behaviour.
CREATE TABLE IF NOT EXISTS `citations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` INT UNSIGNED NOT NULL,
  `source_id`  INT UNSIGNED NOT NULL,
  `anchor`     VARCHAR(120) NOT NULL COMMENT 'id of the paragraph making the claim',
  `quote`      TEXT NULL COMMENT 'the supporting excerpt from the source',
  `verified`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'quote was found verbatim in the source',
  PRIMARY KEY (`id`),
  KEY `idx_citations_article` (`article_id`, `anchor`),
  CONSTRAINT `fk_citations_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_citations_source` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- linking

CREATE TABLE IF NOT EXISTS `topic_aliases` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` INT UNSIGNED NOT NULL,
  `alias`      VARCHAR(160) NOT NULL COMMENT 'as written',
  `alias_norm` VARCHAR(160) NOT NULL COMMENT 'PersianText::normalize(alias)',
  `priority`   SMALLINT NOT NULL DEFAULT 0,
  `is_auto`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'derived from the title vs added by hand',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_alias_norm` (`alias_norm`) COMMENT 'one winner per phrase; priority decides',
  KEY `idx_alias_article` (`article_id`),
  CONSTRAINT `fk_alias_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `article_links` (
  `from_article_id` INT UNSIGNED NOT NULL,
  `to_article_id`   INT UNSIGNED NOT NULL,
  `relation`        ENUM('related','mentions','prerequisite','variant') NOT NULL DEFAULT 'related',
  `weight`          FLOAT NOT NULL DEFAULT 1,
  PRIMARY KEY (`from_article_id`, `to_article_id`, `relation`),
  KEY `idx_links_to` (`to_article_id`),
  CONSTRAINT `fk_links_from` FOREIGN KEY (`from_article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_to` FOREIGN KEY (`to_article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------- search
-- A hand-built inverted index. InnoDB FULLTEXT is not usable here: its
-- tokeniser has a 3-character minimum, an English stopword list, and no
-- concept of ZWNJ or Arabic/Persian character variants.

CREATE TABLE IF NOT EXISTS `search_tokens` (
  `token`      VARCHAR(64) NOT NULL,
  `zone`       TINYINT UNSIGNED NOT NULL COMMENT '1 title, 2 summary, 3 heading, 4 body, 5 alias',
  `article_id` INT UNSIGNED NOT NULL,
  `tf`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`token`, `zone`, `article_id`),
  KEY `idx_tokens_article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `search_docs` (
  `article_id`   INT UNSIGNED NOT NULL,
  `token_count`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'for length normalisation',
  `indexed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`article_id`),
  CONSTRAINT `fk_searchdocs_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------- media

CREATE TABLE IF NOT EXISTS `media` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `path`            VARCHAR(400) NOT NULL COMMENT 'relative to public/media',
  `kind`            ENUM('hero','inline','step','ad') NOT NULL DEFAULT 'inline',
  `mime`            VARCHAR(80) NOT NULL,
  `width`           SMALLINT UNSIGNED NULL,
  `height`          SMALLINT UNSIGNED NULL,
  `bytes`           INT UNSIGNED NOT NULL DEFAULT 0,
  `alt_fa`          VARCHAR(400) NULL,
  `caption_fa`      VARCHAR(500) NULL,
  `is_ai_generated` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'labelled on the page — readers are told',
  `ai_prompt`       TEXT NULL,
  `license`         VARCHAR(120) NULL,
  `attribution`     VARCHAR(400) NULL,
  `source_url`      VARCHAR(2048) NULL,
  `placeholder`     VARCHAR(120) NULL COMMENT 'tiny inline blur shown before load',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ glossary
-- Locked term pairs so cooking vocabulary stays identical across every
-- article the pipeline writes.

CREATE TABLE IF NOT EXISTS `glossary` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term_en` VARCHAR(160) NOT NULL,
  `term_fa` VARCHAR(160) NOT NULL,
  `notes`   VARCHAR(400) NULL,
  `locked`  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'the model may not substitute a synonym',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_glossary_en` (`term_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- job queue
-- The site owns the queue; the VPS worker pulls from it. Leases mean a
-- crashed worker's jobs return to the queue without manual intervention.

CREATE TABLE IF NOT EXISTS `jobs` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`            VARCHAR(40) NOT NULL COMMENT 'plan|search|fetch|synthesize|validate|persian|image|link|push',
  `article_id`      INT UNSIGNED NULL,
  `parent_job_id`   INT UNSIGNED NULL,
  `topic`           VARCHAR(300) NULL,
  `payload`         LONGTEXT NULL,
  `result`          LONGTEXT NULL,
  `status`          ENUM('queued','leased','done','failed','cancelled') NOT NULL DEFAULT 'queued',
  `priority`        TINYINT NOT NULL DEFAULT 5 COMMENT 'lower runs first',
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `claimed_by`      VARCHAR(64) NULL,
  `lease_expires_at` DATETIME NULL,
  `error`           TEXT NULL,
  `cost_micros`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'millionths of a dollar',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_claim` (`status`, `priority`, `id`),
  KEY `idx_jobs_lease` (`status`, `lease_expires_at`),
  KEY `idx_jobs_article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`     INT UNSIGNED NOT NULL,
  `stage`      VARCHAR(40) NOT NULL,
  `level`      ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  `message`    VARCHAR(1000) NOT NULL,
  `meta`       LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_job` (`job_id`, `id`),
  CONSTRAINT `fk_events_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ monetise

CREATE TABLE IF NOT EXISTS `ad_slots` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_name`   VARCHAR(60) NOT NULL,
  `label`      VARCHAR(120) NOT NULL,
  `provider`   ENUM('none','house','network') NOT NULL DEFAULT 'none',
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `notes`      VARCHAR(400) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slot_key` (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ad_creatives` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slot_id`    INT UNSIGNED NOT NULL,
  `title_fa`   VARCHAR(200) NOT NULL,
  `body_fa`    VARCHAR(400) NULL,
  `media_id`   INT UNSIGNED NULL,
  `target_url` VARCHAR(2048) NOT NULL,
  `field_id`   INT UNSIGNED NULL COMMENT 'contextual targeting — topic only, never the visitor',
  `weight`     SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `starts_at`  DATETIME NULL,
  `ends_at`    DATETIME NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_creatives_slot` (`slot_id`, `is_active`),
  CONSTRAINT `fk_creatives_slot` FOREIGN KEY (`slot_id`) REFERENCES `ad_slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily aggregates only. There is no per-visitor row anywhere in this schema.
CREATE TABLE IF NOT EXISTS `ad_stats_daily` (
  `creative_id` INT UNSIGNED NOT NULL,
  `day`         DATE NOT NULL,
  `impressions` INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`creative_id`, `day`),
  CONSTRAINT `fk_adstats_creative` FOREIGN KEY (`creative_id`) REFERENCES `ad_creatives` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------- admin

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(190) NOT NULL,
  `name`            VARCHAR(120) NOT NULL,
  `password_hash`   VARCHAR(255) NOT NULL COMMENT 'Argon2id',
  `totp_secret`     VARCHAR(64) NULL,
  `role`            ENUM('owner','editor') NOT NULL DEFAULT 'editor',
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`    DATETIME NULL,
  `last_login_at`   DATETIME NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `id`           CHAR(64) NOT NULL COMMENT 'sha256 of the cookie value, never the value itself',
  `user_id`      INT UNSIGNED NOT NULL,
  `ip_hash`      CHAR(64) NOT NULL,
  `ua_hash`      CHAR(64) NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_expiry` (`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NULL,
  `action`     VARCHAR(80) NOT NULL,
  `entity`     VARCHAR(60) NULL,
  `entity_id`  INT UNSIGNED NULL,
  `meta`       LONGTEXT NULL,
  `ip_hash`    CHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`, `id`),
  KEY `idx_audit_entity` (`entity`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL,
  `token_hash`   CHAR(64) NOT NULL COMMENT 'sha256 — the plaintext is shown once at creation',
  `scopes`       VARCHAR(255) NOT NULL DEFAULT 'worker',
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `last_used_at` DATETIME NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ defences
-- IPs are stored only as a salted hash, and the salt rotates daily, so a row
-- here stops being linkable to a visitor after 24 hours.

CREATE TABLE IF NOT EXISTS `rate_limit_buckets` (
  `bucket_key`   CHAR(64) NOT NULL COMMENT 'sha256(daily_salt + ip + class)',
  `window_start` INT UNSIGNED NOT NULL,
  `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_buckets_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blocklist` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`       CHAR(64) NOT NULL,
  `reason`        VARCHAR(200) NOT NULL,
  `blocked_until` DATETIME NOT NULL,
  `hits`          INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_block_ip` (`ip_hash`),
  KEY `idx_block_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- routing

CREATE TABLE IF NOT EXISTS `redirects` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_path`  VARCHAR(1000) NOT NULL,
  `from_hash`  CHAR(40) NOT NULL,
  `to_path`    VARCHAR(1000) NOT NULL,
  `status`     SMALLINT UNSIGNED NOT NULL DEFAULT 301,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_redirect_from` (`from_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `name`       VARCHAR(100) NOT NULL,
  `value`      LONGTEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ad_slots` (`key_name`, `label`, `provider`, `is_active`) VALUES
  ('article-top',    'Article — above the summary', 'none', 1),
  ('article-mid',    'Article — mid body',          'none', 1),
  ('article-bottom', 'Article — below references',  'none', 1),
  ('sidebar',        'Sidebar — under the contents','none', 1),
  ('field-footer',   'Field page — footer',         'none', 1);

-- Media references are added after the fact because `media` is created later
-- in this file. SET NULL rather than RESTRICT: deleting an image should never
-- be blocked by an article, it should just leave the article without a hero.
ALTER TABLE `articles`
  ADD CONSTRAINT `fk_articles_hero` FOREIGN KEY (`hero_media_id`)
  REFERENCES `media` (`id`) ON DELETE SET NULL;

ALTER TABLE `ad_creatives`
  ADD CONSTRAINT `fk_creatives_media` FOREIGN KEY (`media_id`)
  REFERENCES `media` (`id`) ON DELETE SET NULL;

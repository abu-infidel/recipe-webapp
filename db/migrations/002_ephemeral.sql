-- ---------------------------------------------------------------------------
-- 002_ephemeral — short-lived values with an expiry
--
-- Proof-of-work nonces, worker replay nonces and crawler-verification results
-- used to live in `settings`, which has no expiry. Unsolved challenge nonces
-- were written on every challenge render and never removed, so anyone could
-- grow the table without bound on a host with a 4 GB database cap.
--
-- Expiry is an integer Unix time written by PHP, so it is immune to any
-- difference between the database and PHP timezones.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ephemeral` (
  `k`          VARCHAR(190) NOT NULL,
  `v`          VARCHAR(1000) NOT NULL DEFAULT '',
  `expires_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`k`),
  KEY `idx_ephemeral_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM `settings`
WHERE `name` LIKE 'pow:%'
   OR `name` LIKE 'powpass:%'
   OR `name` LIKE 'nonce:%'
   OR `name` LIKE 'crawler:%';

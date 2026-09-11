-- Collation Fix audit log.
CREATE TABLE IF NOT EXISTS `civicrm_collationfix_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(190) NOT NULL COMMENT 'Table that was processed',
  `statement` longtext COMMENT 'ALTER statement that was executed',
  `collation_before` varchar(64) DEFAULT NULL,
  `collation_after` varchar(64) DEFAULT NULL,
  `duration_ms` int unsigned DEFAULT 0 COMMENT 'Execution time in milliseconds',
  `status` varchar(16) NOT NULL DEFAULT 'skipped' COMMENT 'success|error|skipped',
  `error_message` text,
  `created_id` int unsigned DEFAULT NULL COMMENT 'FK to civicrm_contact of the operator',
  `created_date` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `index_table_name` (`table_name`),
  KEY `index_created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

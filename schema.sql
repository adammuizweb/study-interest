CREATE TABLE IF NOT EXISTS `study_interest_tests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(80) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_study_interest_tests_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_test_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `test_id` INT UNSIGNED NOT NULL,
  `version_code` VARCHAR(80) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `algorithm_version` VARCHAR(80) NOT NULL,
  `published_at` DATETIME NULL,
  `retired_at` DATETIME NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_study_interest_version_code` (`version_code`),
  KEY `idx_study_interest_versions_test_status` (`test_id`, `status`),
  FOREIGN KEY (`test_id`) REFERENCES `study_interest_tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_dimensions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `version_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_study_interest_dimension` (`version_id`, `code`),
  FOREIGN KEY (`version_id`) REFERENCES `study_interest_test_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_sections` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `version_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `weight` DECIMAL(8,5) NOT NULL DEFAULT 0,
  `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_study_interest_section` (`version_id`, `code`),
  FOREIGN KEY (`version_id`) REFERENCES `study_interest_test_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_questions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `version_id` INT UNSIGNED NOT NULL,
  `section_id` INT UNSIGNED NOT NULL,
  `question_code` VARCHAR(40) NOT NULL,
  `prompt` LONGTEXT NOT NULL,
  `question_type` VARCHAR(30) NOT NULL,
  `is_reverse` TINYINT(1) NOT NULL DEFAULT 0,
  `required` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_study_interest_question` (`version_id`, `question_code`),
  KEY `idx_study_interest_questions_section` (`section_id`, `display_order`),
  FOREIGN KEY (`version_id`) REFERENCES `study_interest_test_versions` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`section_id`) REFERENCES `study_interest_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_options` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `question_id` INT UNSIGNED NOT NULL,
  `option_code` VARCHAR(40) NOT NULL,
  `label` TEXT NOT NULL,
  `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_study_interest_option` (`question_id`, `option_code`),
  FOREIGN KEY (`question_id`) REFERENCES `study_interest_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_option_scores` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `option_id` INT UNSIGNED NOT NULL,
  `dimension_id` INT UNSIGNED NOT NULL,
  `score` DECIMAL(10,4) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_study_interest_option_score` (`option_id`, `dimension_id`),
  FOREIGN KEY (`option_id`) REFERENCES `study_interest_options` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`dimension_id`) REFERENCES `study_interest_dimensions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_programs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `version_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `recommendation_type` VARCHAR(40) NOT NULL DEFAULT 'DIRECT_ENTRY',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_study_interest_program` (`version_id`, `code`),
  FOREIGN KEY (`version_id`) REFERENCES `study_interest_test_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_program_weights` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `program_id` INT UNSIGNED NOT NULL,
  `dimension_id` INT UNSIGNED NOT NULL,
  `weight` DECIMAL(8,5) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_study_interest_program_weight` (`program_id`, `dimension_id`),
  FOREIGN KEY (`program_id`) REFERENCES `study_interest_programs` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`dimension_id`) REFERENCES `study_interest_dimensions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_sessions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `public_id` CHAR(36) NOT NULL,
  `test_id` INT UNSIGNED NOT NULL,
  `version_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'started',
  `consent_at` DATETIME NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `contact_json` TEXT NULL,
  `result_snapshot_json` LONGTEXT NULL,
  UNIQUE KEY `uq_study_interest_session_public_id` (`public_id`),
  KEY `idx_study_interest_sessions_version` (`version_id`, `status`),
  FOREIGN KEY (`test_id`) REFERENCES `study_interest_tests` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`version_id`) REFERENCES `study_interest_test_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_answers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NULL,
  `answer_value` VARCHAR(255) NULL,
  `answered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_study_interest_answer` (`session_id`, `question_id`),
  FOREIGN KEY (`session_id`) REFERENCES `study_interest_sessions` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `study_interest_questions` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`option_id`) REFERENCES `study_interest_options` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_interest_audit_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `actor_id` INT UNSIGNED NULL,
  `action` VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(80) NOT NULL,
  `entity_id` VARCHAR(80) NULL,
  `before_json` LONGTEXT NULL,
  `after_json` LONGTEXT NULL,
  `request_id` VARCHAR(80) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_study_interest_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_study_interest_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

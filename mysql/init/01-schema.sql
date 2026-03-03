-- DEPRECATED: Superseded by Doctrine Migrations (see backend/migrations/).
CREATE TABLE IF NOT EXISTS `owasp_categories` (
    `code` VARCHAR(10) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `github_id` INT NOT NULL UNIQUE,
    `username` VARCHAR(255) NOT NULL,
    `avatar_url` VARCHAR(500) DEFAULT NULL,
    `github_token` VARCHAR(500) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `repository_url` VARCHAR(500) NOT NULL,
    `main_branch` VARCHAR(100) NOT NULL DEFAULT 'main',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scans` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `project_id` CHAR(36) NOT NULL,
    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `global_score` DECIMAL(5,2) DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
    `workdir` VARCHAR(500) DEFAULT NULL,
    CONSTRAINT `fk_scans_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `findings` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `scan_id` CHAR(36) NOT NULL,
    `tool_source` VARCHAR(100) NOT NULL,
    `severity` VARCHAR(50) NOT NULL,
    `owasp_category` VARCHAR(10) DEFAULT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `line_number` INT DEFAULT NULL,
    `description` TEXT NOT NULL,
    `raw_code` TEXT DEFAULT NULL,
    CONSTRAINT `fk_findings_scan` FOREIGN KEY (`scan_id`) REFERENCES `scans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_findings_owasp` FOREIGN KEY (`owasp_category`) REFERENCES `owasp_categories`(`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remediations` (
    `finding_id` CHAR(36) NOT NULL PRIMARY KEY,
    `proposed_fix` TEXT DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
    `git_branch_name` VARCHAR(255) DEFAULT NULL,
    `pr_url` VARCHAR(500) DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_remediations_finding` FOREIGN KEY (`finding_id`) REFERENCES `findings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

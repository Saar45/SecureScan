<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial migration: creates all tables and seeds OWASP categories.
 */
final class Version20260303000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create owasp_categories, users, projects, scans, findings, remediations tables and seed OWASP Top 10';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE `owasp_categories` (
                `code` VARCHAR(10) NOT NULL PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `description` TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `users` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `github_id` INT NOT NULL UNIQUE,
                `username` VARCHAR(255) NOT NULL,
                `avatar_url` VARCHAR(500) DEFAULT NULL,
                `github_token` VARCHAR(500) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `projects` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `repository_url` VARCHAR(500) NOT NULL,
                `main_branch` VARCHAR(100) NOT NULL DEFAULT 'main',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `scans` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `project_id` CHAR(36) NOT NULL,
                `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `global_score` DECIMAL(5,2) DEFAULT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
                `workdir` VARCHAR(500) DEFAULT NULL,
                CONSTRAINT `fk_scans_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `findings` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `remediations` (
                `finding_id` CHAR(36) NOT NULL PRIMARY KEY,
                `proposed_fix` TEXT DEFAULT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
                `git_branch_name` VARCHAR(255) DEFAULT NULL,
                `pr_url` VARCHAR(500) DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_remediations_finding` FOREIGN KEY (`finding_id`) REFERENCES `findings`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO `owasp_categories` (`code`, `name`, `description`) VALUES
            ('A01', 'Broken Access Control', 'Restrictions on what authenticated users are allowed to do are often not properly enforced.'),
            ('A02', 'Security Misconfiguration', 'Missing appropriate security hardening across any part of the application stack, or improperly configured permissions.'),
            ('A03', 'Software Supply Chain Failures', 'Vulnerabilities introduced through third-party components, libraries, dependencies, or compromised build pipelines.'),
            ('A04', 'Cryptographic Failures', 'Failures related to cryptography which often lead to sensitive data exposure or system compromise.'),
            ('A05', 'Injection', 'Hostile data is sent to an interpreter as part of a command or query (SQL, XSS, OS command, etc.), leading to unintended commands or data access.'),
            ('A06', 'Insecure Design', 'Missing or ineffective security controls and architectural flaws that cannot be fixed by a perfect implementation.'),
            ('A07', 'Authentication Failures', 'Weaknesses in authentication and session management that allow attackers to compromise passwords, keys, or session tokens.'),
            ('A08', 'Software and Data Integrity Failures', 'Code and infrastructure that does not protect against integrity violations, including insecure CI/CD pipelines and auto-updates.'),
            ('A09', 'Logging and Alerting Failures', 'Insufficient logging, detection, monitoring, and active alerting that delays or prevents incident response.'),
            ('A10', 'Mishandling of Exceptional Conditions', 'Improper handling of errors, exceptions, and edge cases that can lead to crashes, information leaks, or security bypasses.')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `remediations`');
        $this->addSql('DROP TABLE IF EXISTS `findings`');
        $this->addSql('DROP TABLE IF EXISTS `scans`');
        $this->addSql('DROP TABLE IF EXISTS `projects`');
        $this->addSql('DROP TABLE IF EXISTS `users`');
        $this->addSql('DROP TABLE IF EXISTS `owasp_categories`');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_id (owner) column to projects table for IDOR protection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `projects` ADD `user_id` CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE `projects` ADD CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `projects` DROP FOREIGN KEY `fk_projects_user`');
        $this->addSql('ALTER TABLE `projects` DROP COLUMN `user_id`');
    }
}

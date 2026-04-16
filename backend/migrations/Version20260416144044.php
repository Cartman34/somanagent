<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416144044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent_action ADD allowed_effects JSON NOT NULL');
        $this->addSql('ALTER TABLE project ALTER dispatch_mode TYPE VARCHAR(255)');
        $this->addSql('ALTER INDEX idx_project_default_ticket_role RENAME TO IDX_2FB3D0EE5E471BBC');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent_action DROP allowed_effects');
        $this->addSql('ALTER TABLE project ALTER dispatch_mode TYPE VARCHAR(20)');
        $this->addSql('ALTER INDEX idx_2fb3d0ee5e471bbc RENAME TO idx_project_default_ticket_role');
    }
}

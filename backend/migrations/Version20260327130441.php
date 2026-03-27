<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260327130441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F1: Add project.team_id (ManyToOne → team). F3: Add workflow_step.story_status_trigger for story lifecycle mapping.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project ADD team_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2FB3D0EE296CD8AE ON project (team_id)');
        $this->addSql('ALTER TABLE workflow_step ADD story_status_trigger VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE296CD8AE');
        $this->addSql('DROP INDEX IDX_2FB3D0EE296CD8AE');
        $this->addSql('ALTER TABLE project DROP team_id');
        $this->addSql('ALTER TABLE workflow_step DROP story_status_trigger');
    }
}

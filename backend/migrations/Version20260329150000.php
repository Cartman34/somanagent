<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attach technical tasks to an optional workflow step for board-stage rendering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD workflow_step_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB251C2C8B07 FOREIGN KEY (workflow_step_id) REFERENCES workflow_step (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_527EDB251C2C8B07 ON task (workflow_step_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB251C2C8B07');
        $this->addSql('DROP INDEX IDX_527EDB251C2C8B07');
        $this->addSql('ALTER TABLE task DROP workflow_step_id');
    }
}

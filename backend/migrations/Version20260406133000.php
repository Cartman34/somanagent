<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406133000 extends AbstractMigration
{
    /**
     * Describes the schema change applied by this migration.
     */
    public function getDescription(): string
    {
        return 'Add immutable execution resource snapshots to agent task executions';
    }

    /**
     * Adds the per-attempt execution resource snapshot column.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_task_execution_attempt ADD resource_snapshot JSON DEFAULT NULL');
    }

    /**
     * Removes the per-attempt execution resource snapshot column.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_task_execution_attempt DROP resource_snapshot');
    }
}

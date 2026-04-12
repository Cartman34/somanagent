<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412100000 extends AbstractMigration
{
    /**
     * Describes the schema change applied by this migration.
     */
    public function getDescription(): string
    {
        return 'Add default_ticket_role_id to project to make the automatic role assignment on ticket creation configurable';
    }

    /**
     * Adds the default_ticket_role_id column and foreign key to the project table.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD default_ticket_role_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_project_default_ticket_role FOREIGN KEY (default_ticket_role_id) REFERENCES role(id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_project_default_ticket_role ON project (default_ticket_role_id)');
    }

    /**
     * Removes the default_ticket_role_id column and its foreign key from the project table.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_project_default_ticket_role');
        $this->addSql('DROP INDEX IDX_project_default_ticket_role ON project');
        $this->addSql('ALTER TABLE project DROP default_ticket_role_id');
    }
}

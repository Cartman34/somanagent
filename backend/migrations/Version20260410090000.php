<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410090000 extends AbstractMigration
{
    /**
     * Describes the schema change applied by this migration.
     */
    public function getDescription(): string
    {
        return 'Add initial_request column to ticket to preserve the original user request';
    }

    /**
     * Adds the initial_request column to the ticket table.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD initial_request LONGTEXT DEFAULT NULL');
    }

    /**
     * Removes the initial_request column from the ticket table.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket DROP initial_request');
    }
}

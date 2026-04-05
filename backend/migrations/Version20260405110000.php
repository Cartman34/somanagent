<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project dispatch mode with auto default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE project ADD dispatch_mode VARCHAR(20) DEFAULT 'auto' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP dispatch_mode');
    }
}

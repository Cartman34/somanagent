<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make token_usage.model nullable to allow connectors that use their default model';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE token_usage ALTER model DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE token_usage SET model = \'\' WHERE model IS NULL');
        $this->addSql('ALTER TABLE token_usage ALTER model SET NOT NULL');
    }
}

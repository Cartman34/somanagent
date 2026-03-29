<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add translation metadata columns to persisted log events and occurrences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE log_event ADD title_domain VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_event ADD title_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_event ADD title_parameters JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE log_event ADD message_domain VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_event ADD message_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_event ADD message_parameters JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE log_occurrence ADD title_domain VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_occurrence ADD title_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_occurrence ADD title_parameters JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE log_occurrence ADD message_domain VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_occurrence ADD message_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE log_occurrence ADD message_parameters JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE log_event DROP title_domain');
        $this->addSql('ALTER TABLE log_event DROP title_key');
        $this->addSql('ALTER TABLE log_event DROP title_parameters');
        $this->addSql('ALTER TABLE log_event DROP message_domain');
        $this->addSql('ALTER TABLE log_event DROP message_key');
        $this->addSql('ALTER TABLE log_event DROP message_parameters');

        $this->addSql('ALTER TABLE log_occurrence DROP title_domain');
        $this->addSql('ALTER TABLE log_occurrence DROP title_key');
        $this->addSql('ALTER TABLE log_occurrence DROP title_parameters');
        $this->addSql('ALTER TABLE log_occurrence DROP message_domain');
        $this->addSql('ALTER TABLE log_occurrence DROP message_key');
        $this->addSql('ALTER TABLE log_occurrence DROP message_parameters');
    }
}

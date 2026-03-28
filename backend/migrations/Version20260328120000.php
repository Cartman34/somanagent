<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend task logs with comment conversation metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE task_log ADD kind VARCHAR(20) NOT NULL DEFAULT 'event'");
        $this->addSql('ALTER TABLE task_log ADD author_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_log ADD author_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_log ADD requires_answer BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE task_log ADD reply_to_log_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task_log ADD metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_log DROP kind');
        $this->addSql('ALTER TABLE task_log DROP author_type');
        $this->addSql('ALTER TABLE task_log DROP author_name');
        $this->addSql('ALTER TABLE task_log DROP requires_answer');
        $this->addSql('ALTER TABLE task_log DROP reply_to_log_id');
        $this->addSql('ALTER TABLE task_log DROP metadata');
    }
}

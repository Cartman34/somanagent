<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add exchange metadata to chat messages for agent conversations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE chat_message ADD exchange_id VARCHAR(36) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE chat_message ADD is_error BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE chat_message ADD metadata JSON DEFAULT NULL');
        $this->addSql("UPDATE chat_message SET exchange_id = id::text WHERE exchange_id = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message DROP exchange_id');
        $this->addSql('ALTER TABLE chat_message DROP is_error');
        $this->addSql('ALTER TABLE chat_message DROP metadata');
    }
}

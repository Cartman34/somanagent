<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260331171155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_message ADD reply_to_message_id UUID DEFAULT NULL');
        $this->addSql('ALTER INDEX idx_2fb3d0ee7a66e13f RENAME TO IDX_2FB3D0EE2C7C2CBA');
        $this->addSql('ALTER TABLE workflow_step ALTER transition_mode DROP DEFAULT');
        $this->addSql('ALTER INDEX idx_c0b3d7ecf41e4dab RENAME TO IDX_3CFCAA1571FE882C');
        $this->addSql('ALTER INDEX idx_c0b3d7ec376f0d9e RENAME TO IDX_3CFCAA156A667671');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_message DROP reply_to_message_id');
        $this->addSql('ALTER INDEX idx_2fb3d0ee2c7c2cba RENAME TO idx_2fb3d0ee7a66e13f');
        $this->addSql('ALTER TABLE workflow_step ALTER transition_mode SET DEFAULT \'manual\'');
        $this->addSql('ALTER INDEX idx_3cfcaa1571fe882c RENAME TO idx_c0b3d7ecf41e4dab');
        $this->addSql('ALTER INDEX idx_3cfcaa156a667671 RENAME TO idx_c0b3d7ec376f0d9e');
    }
}

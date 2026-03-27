<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260327102025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE task_dependency (id UUID NOT NULL, task_id UUID NOT NULL, depends_on_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_334B1EAA8DB60186 ON task_dependency (task_id)');
        $this->addSql('CREATE INDEX IDX_334B1EAA1E088F8 ON task_dependency (depends_on_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_334B1EAA8DB601861E088F8 ON task_dependency (task_id, depends_on_id)');
        $this->addSql('ALTER TABLE task_dependency ADD CONSTRAINT FK_334B1EAA8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_dependency ADD CONSTRAINT FK_334B1EAA1E088F8 FOREIGN KEY (depends_on_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD story_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD branch_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD assigned_role_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD added_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25DC9B9A23 FOREIGN KEY (assigned_role_id) REFERENCES role (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB2555B127A4 FOREIGN KEY (added_by_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_527EDB25DC9B9A23 ON task (assigned_role_id)');
        $this->addSql('CREATE INDEX IDX_527EDB2555B127A4 ON task (added_by_id)');
        $this->addSql('ALTER TABLE workflow ADD status VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE workflow DROP is_active');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task_dependency DROP CONSTRAINT FK_334B1EAA8DB60186');
        $this->addSql('ALTER TABLE task_dependency DROP CONSTRAINT FK_334B1EAA1E088F8');
        $this->addSql('DROP TABLE task_dependency');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25DC9B9A23');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB2555B127A4');
        $this->addSql('DROP INDEX IDX_527EDB25DC9B9A23');
        $this->addSql('DROP INDEX IDX_527EDB2555B127A4');
        $this->addSql('ALTER TABLE task DROP story_status');
        $this->addSql('ALTER TABLE task DROP branch_name');
        $this->addSql('ALTER TABLE task DROP assigned_role_id');
        $this->addSql('ALTER TABLE task DROP added_by_id');
        $this->addSql('ALTER TABLE workflow ADD is_active BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE workflow DROP status');
    }
}

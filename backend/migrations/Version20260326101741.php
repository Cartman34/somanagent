<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326101741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE chat_message (id UUID NOT NULL, author VARCHAR(255) NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, agent_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FAB3FC16166D1F9C ON chat_message (project_id)');
        $this->addSql('CREATE INDEX IDX_FAB3FC163414710B ON chat_message (agent_id)');
        $this->addSql('CREATE TABLE external_reference (id UUID NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id UUID NOT NULL, system VARCHAR(255) NOT NULL, external_id VARCHAR(255) NOT NULL, external_url VARCHAR(512) DEFAULT NULL, metadata JSON DEFAULT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_external_ref ON external_reference (entity_type, entity_id, system)');
        $this->addSql('CREATE TABLE feature (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1FD77566166D1F9C ON feature (project_id)');
        $this->addSql('CREATE TABLE role_skill (role_id UUID NOT NULL, skill_id UUID NOT NULL, PRIMARY KEY (role_id, skill_id))');
        $this->addSql('CREATE INDEX IDX_A9E10C58D60322AC ON role_skill (role_id)');
        $this->addSql('CREATE INDEX IDX_A9E10C585585C142 ON role_skill (skill_id)');
        $this->addSql('CREATE TABLE task (id UUID NOT NULL, type VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, priority VARCHAR(255) NOT NULL, progress SMALLINT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, feature_id UUID DEFAULT NULL, parent_id UUID DEFAULT NULL, assigned_agent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_527EDB25166D1F9C ON task (project_id)');
        $this->addSql('CREATE INDEX IDX_527EDB2560E4B879 ON task (feature_id)');
        $this->addSql('CREATE INDEX IDX_527EDB25727ACA70 ON task (parent_id)');
        $this->addSql('CREATE INDEX IDX_527EDB2549197702 ON task (assigned_agent_id)');
        $this->addSql('CREATE TABLE task_log (id UUID NOT NULL, action VARCHAR(100) NOT NULL, content TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, task_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E0BD90428DB60186 ON task_log (task_id)');
        $this->addSql('CREATE TABLE agent_team (team_id UUID NOT NULL, agent_id UUID NOT NULL, PRIMARY KEY (team_id, agent_id))');
        $this->addSql('CREATE INDEX IDX_697B1CFC296CD8AE ON agent_team (team_id)');
        $this->addSql('CREATE INDEX IDX_697B1CFC3414710B ON agent_team (agent_id)');
        $this->addSql('CREATE TABLE token_usage (id UUID NOT NULL, model VARCHAR(100) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, duration_ms INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, agent_id UUID DEFAULT NULL, task_id UUID DEFAULT NULL, workflow_step_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_73355A313414710B ON token_usage (agent_id)');
        $this->addSql('CREATE INDEX IDX_73355A318DB60186 ON token_usage (task_id)');
        $this->addSql('CREATE INDEX IDX_73355A3171FE882C ON token_usage (workflow_step_id)');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC16166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC163414710B FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature ADD CONSTRAINT FK_1FD77566166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE role_skill ADD CONSTRAINT FK_A9E10C58D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_skill ADD CONSTRAINT FK_A9E10C585585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB2560E4B879 FOREIGN KEY (feature_id) REFERENCES feature (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25727ACA70 FOREIGN KEY (parent_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB2549197702 FOREIGN KEY (assigned_agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_log ADD CONSTRAINT FK_E0BD90428DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_697B1CFC296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_697B1CFC3414710B FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE token_usage ADD CONSTRAINT FK_73355A313414710B FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE token_usage ADD CONSTRAINT FK_73355A318DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE token_usage ADD CONSTRAINT FK_73355A3171FE882C FOREIGN KEY (workflow_step_id) REFERENCES workflow_step (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent ALTER connector TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE agent ALTER is_active DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN agent.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN agent.role_id IS \'\'');
        $this->addSql('ALTER INDEX idx_agent_role RENAME TO IDX_268B9C9DD60322AC');
        $this->addSql('ALTER TABLE audit_log ALTER action TYPE VARCHAR(255)');
        $this->addSql('COMMENT ON COLUMN audit_log.id IS \'\'');
        $this->addSql('ALTER TABLE module ALTER status TYPE VARCHAR(255)');
        $this->addSql('COMMENT ON COLUMN module.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN module.project_id IS \'\'');
        $this->addSql('ALTER INDEX idx_module_project RENAME TO IDX_C242628166D1F9C');
        $this->addSql('ALTER TABLE project ADD repository_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN project.id IS \'\'');
        $this->addSql('ALTER TABLE role DROP CONSTRAINT fk_role_team');
        $this->addSql('DROP INDEX idx_role_team');
        // Ajout du slug nullable d'abord pour pouvoir migrer les données existantes
        $this->addSql('ALTER TABLE role ADD slug VARCHAR(100) DEFAULT NULL');
        // Génère un slug depuis le nom pour les lignes existantes
        $this->addSql("UPDATE role SET slug = LOWER(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(name, ' ', '-'), '/', '-'), '_', '-'), '[^a-z0-9-]', '', 'g')) || '-' || SUBSTRING(id::text, 1, 8) WHERE slug IS NULL");
        // Maintenant on passe à NOT NULL
        $this->addSql('ALTER TABLE role ALTER slug SET NOT NULL');
        $this->addSql('ALTER TABLE role DROP team_id');
        $this->addSql('ALTER TABLE role DROP skill_slug');
        $this->addSql('COMMENT ON COLUMN role.id IS \'\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_57698A6A989D9B62 ON role (slug)');
        $this->addSql('ALTER TABLE skill ALTER source TYPE VARCHAR(255)');
        $this->addSql('COMMENT ON COLUMN skill.id IS \'\'');
        $this->addSql('ALTER INDEX uniq_skill_slug RENAME TO UNIQ_5E3DE477989D9B62');
        $this->addSql('COMMENT ON COLUMN team.id IS \'\'');
        $this->addSql('ALTER TABLE workflow ALTER trigger TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE workflow ALTER is_active DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN workflow.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN workflow.team_id IS \'\'');
        $this->addSql('ALTER INDEX idx_workflow_team RENAME TO IDX_65C59816296CD8AE');
        $this->addSql('ALTER TABLE workflow_step ALTER input_config DROP DEFAULT');
        $this->addSql('ALTER TABLE workflow_step ALTER status TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE workflow_step ALTER status DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN workflow_step.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN workflow_step.workflow_id IS \'\'');
        $this->addSql('ALTER INDEX idx_workflow_step_workflow RENAME TO IDX_626EE072C7C2CBA');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_message DROP CONSTRAINT FK_FAB3FC16166D1F9C');
        $this->addSql('ALTER TABLE chat_message DROP CONSTRAINT FK_FAB3FC163414710B');
        $this->addSql('ALTER TABLE feature DROP CONSTRAINT FK_1FD77566166D1F9C');
        $this->addSql('ALTER TABLE role_skill DROP CONSTRAINT FK_A9E10C58D60322AC');
        $this->addSql('ALTER TABLE role_skill DROP CONSTRAINT FK_A9E10C585585C142');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25166D1F9C');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB2560E4B879');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25727ACA70');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB2549197702');
        $this->addSql('ALTER TABLE task_log DROP CONSTRAINT FK_E0BD90428DB60186');
        $this->addSql('ALTER TABLE agent_team DROP CONSTRAINT FK_697B1CFC296CD8AE');
        $this->addSql('ALTER TABLE agent_team DROP CONSTRAINT FK_697B1CFC3414710B');
        $this->addSql('ALTER TABLE token_usage DROP CONSTRAINT FK_73355A313414710B');
        $this->addSql('ALTER TABLE token_usage DROP CONSTRAINT FK_73355A318DB60186');
        $this->addSql('ALTER TABLE token_usage DROP CONSTRAINT FK_73355A3171FE882C');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE external_reference');
        $this->addSql('DROP TABLE feature');
        $this->addSql('DROP TABLE role_skill');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE task_log');
        $this->addSql('DROP TABLE agent_team');
        $this->addSql('DROP TABLE token_usage');
        $this->addSql('ALTER TABLE agent ALTER connector TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE agent ALTER is_active SET DEFAULT true');
        $this->addSql('COMMENT ON COLUMN agent.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN agent.role_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX idx_268b9c9dd60322ac RENAME TO idx_agent_role');
        $this->addSql('ALTER TABLE audit_log ALTER action TYPE VARCHAR(100)');
        $this->addSql('COMMENT ON COLUMN audit_log.id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE module ALTER status TYPE VARCHAR(50)');
        $this->addSql('COMMENT ON COLUMN module.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN module.project_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX idx_c242628166d1f9c RENAME TO idx_module_project');
        $this->addSql('ALTER TABLE project DROP repository_url');
        $this->addSql('COMMENT ON COLUMN project.id IS \'(DC2Type:uuid)\'');
        $this->addSql('DROP INDEX UNIQ_57698A6A989D9B62');
        $this->addSql('ALTER TABLE role ADD team_id UUID NOT NULL');
        $this->addSql('ALTER TABLE role ADD skill_slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE role DROP slug');
        $this->addSql('COMMENT ON COLUMN role.team_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN role.id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE role ADD CONSTRAINT fk_role_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_role_team ON role (team_id)');
        $this->addSql('ALTER TABLE skill ALTER source TYPE VARCHAR(50)');
        $this->addSql('COMMENT ON COLUMN skill.id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX uniq_5e3de477989d9b62 RENAME TO uniq_skill_slug');
        $this->addSql('COMMENT ON COLUMN team.id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE workflow ALTER trigger TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE workflow ALTER is_active SET DEFAULT true');
        $this->addSql('COMMENT ON COLUMN workflow.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN workflow.team_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX idx_65c59816296cd8ae RENAME TO idx_workflow_team');
        $this->addSql('ALTER TABLE workflow_step ALTER input_config SET DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE workflow_step ALTER status TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE workflow_step ALTER status SET DEFAULT \'pending\'');
        $this->addSql('COMMENT ON COLUMN workflow_step.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN workflow_step.workflow_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX idx_626ee072c7c2cba RENAME TO idx_workflow_step_workflow');
    }
}

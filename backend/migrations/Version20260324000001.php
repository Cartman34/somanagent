<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration initiale — schéma complet SoManAgent
 * Tables : project, module, team, role, agent, skill, workflow, workflow_step, audit_log
 */
final class Version20260324000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial SoManAgent v1';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE project (
                id          UUID         NOT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT         DEFAULT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN project.id IS '(DC2Type:uuid)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE module (
                id             UUID         NOT NULL,
                project_id     UUID         NOT NULL,
                name           VARCHAR(255) NOT NULL,
                description    TEXT         DEFAULT NULL,
                repository_url VARCHAR(512) DEFAULT NULL,
                stack          VARCHAR(255) DEFAULT NULL,
                status         VARCHAR(50)  NOT NULL,
                created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN module.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN module.project_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_module_project ON module (project_id)');
        $this->addSql('ALTER TABLE module ADD CONSTRAINT fk_module_project FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE team (
                id          UUID         NOT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT         DEFAULT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN team.id IS '(DC2Type:uuid)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE role (
                id          UUID         NOT NULL,
                team_id     UUID         NOT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT         DEFAULT NULL,
                skill_slug  VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN role.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN role.team_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_role_team ON role (team_id)');
        $this->addSql('ALTER TABLE role ADD CONSTRAINT fk_role_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE agent (
                id          UUID         NOT NULL,
                role_id     UUID         DEFAULT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT         DEFAULT NULL,
                connector   VARCHAR(50)  NOT NULL,
                config      JSON         NOT NULL,
                is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN agent.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN agent.role_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_agent_role ON agent (role_id)');
        $this->addSql('ALTER TABLE agent ADD CONSTRAINT fk_agent_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE skill (
                id              UUID         NOT NULL,
                slug            VARCHAR(255) NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT         DEFAULT NULL,
                source          VARCHAR(50)  NOT NULL,
                original_source VARCHAR(255) DEFAULT NULL,
                content         TEXT         NOT NULL,
                file_path       VARCHAR(512) NOT NULL,
                created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN skill.id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE UNIQUE INDEX uniq_skill_slug ON skill (slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE workflow (
                id          UUID         NOT NULL,
                team_id     UUID         DEFAULT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT         DEFAULT NULL,
                trigger     VARCHAR(50)  NOT NULL,
                is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN workflow.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN workflow.team_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_workflow_team ON workflow (team_id)');
        $this->addSql('ALTER TABLE workflow ADD CONSTRAINT fk_workflow_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_step (
                id           UUID         NOT NULL,
                workflow_id  UUID         NOT NULL,
                step_order   INT          NOT NULL,
                name         VARCHAR(255) NOT NULL,
                role_slug    VARCHAR(255) DEFAULT NULL,
                skill_slug   VARCHAR(255) DEFAULT NULL,
                input_config JSON         NOT NULL DEFAULT '{}',
                output_key   VARCHAR(255) NOT NULL,
                condition    TEXT         DEFAULT NULL,
                status       VARCHAR(50)  NOT NULL DEFAULT 'pending',
                last_output  TEXT         DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN workflow_step.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN workflow_step.workflow_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_workflow_step_workflow ON workflow_step (workflow_id)');
        $this->addSql('ALTER TABLE workflow_step ADD CONSTRAINT fk_step_workflow FOREIGN KEY (workflow_id) REFERENCES workflow (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
                id          UUID         NOT NULL,
                action      VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(36)  DEFAULT NULL,
                data        JSON         DEFAULT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN audit_log.id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_audit_action ON audit_log (action)');
        $this->addSql('CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_created_at ON audit_log (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step DROP CONSTRAINT fk_step_workflow');
        $this->addSql('ALTER TABLE workflow DROP CONSTRAINT fk_workflow_team');
        $this->addSql('ALTER TABLE module DROP CONSTRAINT fk_module_project');
        $this->addSql('ALTER TABLE agent DROP CONSTRAINT fk_agent_role');
        $this->addSql('ALTER TABLE role DROP CONSTRAINT fk_role_team');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE workflow_step');
        $this->addSql('DROP TABLE workflow');
        $this->addSql('DROP TABLE skill');
        $this->addSql('DROP TABLE agent');
        $this->addSql('DROP TABLE role');
        $this->addSql('DROP TABLE team');
        $this->addSql('DROP TABLE module');
        $this->addSql('DROP TABLE project');
    }
}

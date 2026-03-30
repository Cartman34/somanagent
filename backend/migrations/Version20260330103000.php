<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workflow activation flag, move workflow assignment to projects, and refactor workflow steps to actions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow ADD is_active BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE project ADD workflow_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_2FB3D0EE7A66E13F ON project (workflow_id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE7A66E13F FOREIGN KEY (workflow_id) REFERENCES workflow (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow DROP CONSTRAINT FK_65C59816296CD8AE');
        $this->addSql('DROP INDEX IDX_65C59816296CD8AE');
        $this->addSql('ALTER TABLE workflow DROP team_id');
        $this->addSql("ALTER TABLE workflow_step ADD transition_mode VARCHAR(255) DEFAULT 'manual' NOT NULL");
        $this->addSql('ALTER TABLE workflow_step DROP role_slug');
        $this->addSql('ALTER TABLE workflow_step DROP skill_slug');
        $this->addSql('ALTER TABLE workflow_step DROP story_status_trigger');
        $this->addSql('ALTER TABLE ticket DROP story_status');
        $this->addSql('CREATE TABLE workflow_step_action (id UUID NOT NULL, workflow_step_id UUID NOT NULL, agent_action_id UUID NOT NULL, create_with_ticket BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C0B3D7ECF41E4DAB ON workflow_step_action (workflow_step_id)');
        $this->addSql('CREATE INDEX IDX_C0B3D7EC376F0D9E ON workflow_step_action (agent_action_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_step_action_step_action ON workflow_step_action (workflow_step_id, agent_action_id)');
        $this->addSql('ALTER TABLE workflow_step_action ADD CONSTRAINT FK_C0B3D7ECF41E4DAB FOREIGN KEY (workflow_step_id) REFERENCES workflow_step (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_step_action ADD CONSTRAINT FK_C0B3D7EC376F0D9E FOREIGN KEY (agent_action_id) REFERENCES agent_action (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step_action DROP CONSTRAINT FK_C0B3D7ECF41E4DAB');
        $this->addSql('ALTER TABLE workflow_step_action DROP CONSTRAINT FK_C0B3D7EC376F0D9E');
        $this->addSql('DROP TABLE workflow_step_action');
        $this->addSql('ALTER TABLE workflow_step ADD role_slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_step ADD skill_slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_step ADD story_status_trigger VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket ADD story_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_step DROP transition_mode');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE7A66E13F');
        $this->addSql('DROP INDEX IDX_2FB3D0EE7A66E13F');
        $this->addSql('ALTER TABLE project DROP workflow_id');
        $this->addSql('ALTER TABLE workflow DROP is_active');
        $this->addSql('ALTER TABLE workflow ADD team_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow ADD CONSTRAINT FK_65C59816296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_65C59816296CD8AE ON workflow (team_id)');
    }
}

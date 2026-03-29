<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task execution runs and attempts for independent async execution tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_execution (id UUID NOT NULL, task_id UUID NOT NULL, trace_ref VARCHAR(64) NOT NULL, trigger_type VARCHAR(255) NOT NULL, workflow_step_key VARCHAR(255) DEFAULT NULL, skill_slug VARCHAR(255) DEFAULT NULL, requested_agent_id UUID DEFAULT NULL, effective_agent_id UUID DEFAULT NULL, status VARCHAR(255) NOT NULL, current_attempt SMALLINT NOT NULL, max_attempts SMALLINT NOT NULL, request_ref VARCHAR(64) DEFAULT NULL, last_error_message TEXT DEFAULT NULL, last_error_scope VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_execution_trace_ref ON task_execution (trace_ref)');
        $this->addSql('CREATE INDEX idx_task_execution_task ON task_execution (task_id)');
        $this->addSql('CREATE INDEX idx_task_execution_trace_ref ON task_execution (trace_ref)');
        $this->addSql('CREATE INDEX idx_task_execution_status ON task_execution (status)');
        $this->addSql('CREATE INDEX IDX_726D2E574C9D0CD ON task_execution (requested_agent_id)');
        $this->addSql('CREATE INDEX IDX_726D2E5EAF14A5A ON task_execution (effective_agent_id)');
        $this->addSql('ALTER TABLE task_execution ADD CONSTRAINT FK_726D2E58DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_execution ADD CONSTRAINT FK_726D2E574C9D0CD FOREIGN KEY (requested_agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_execution ADD CONSTRAINT FK_726D2E5EAF14A5A FOREIGN KEY (effective_agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE task_execution_attempt (id UUID NOT NULL, execution_id UUID NOT NULL, attempt_number SMALLINT NOT NULL, agent_id UUID DEFAULT NULL, messenger_receiver VARCHAR(64) DEFAULT NULL, request_ref VARCHAR(64) DEFAULT NULL, status VARCHAR(255) NOT NULL, will_retry BOOLEAN NOT NULL DEFAULT FALSE, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, error_scope VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_task_execution_attempt_execution ON task_execution_attempt (execution_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_execution_attempt_execution_number ON task_execution_attempt (execution_id, attempt_number)');
        $this->addSql('CREATE INDEX IDX_3961DC8345A7E1B6 ON task_execution_attempt (agent_id)');
        $this->addSql('ALTER TABLE task_execution_attempt ADD CONSTRAINT FK_3961DC835DE6D518 FOREIGN KEY (execution_id) REFERENCES task_execution (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_execution_attempt ADD CONSTRAINT FK_3961DC8345A7E1B6 FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_execution_attempt DROP CONSTRAINT FK_3961DC835DE6D518');
        $this->addSql('ALTER TABLE task_execution_attempt DROP CONSTRAINT FK_3961DC8345A7E1B6');
        $this->addSql('ALTER TABLE task_execution DROP CONSTRAINT FK_726D2E58DB60186');
        $this->addSql('ALTER TABLE task_execution DROP CONSTRAINT FK_726D2E574C9D0CD');
        $this->addSql('ALTER TABLE task_execution DROP CONSTRAINT FK_726D2E5EAF14A5A');
        $this->addSql('DROP TABLE task_execution_attempt');
        $this->addSql('DROP TABLE task_execution');
    }
}

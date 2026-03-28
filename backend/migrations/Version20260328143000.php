<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add centralized log events and aggregated log occurrences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE log_event (id UUID NOT NULL, source VARCHAR(20) NOT NULL, category VARCHAR(20) NOT NULL, level VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, fingerprint VARCHAR(64) DEFAULT NULL, project_id UUID DEFAULT NULL, task_id UUID DEFAULT NULL, agent_id UUID DEFAULT NULL, exchange_ref VARCHAR(64) DEFAULT NULL, request_ref VARCHAR(64) DEFAULT NULL, trace_ref VARCHAR(64) DEFAULT NULL, context JSON DEFAULT NULL, stack TEXT DEFAULT NULL, origin VARCHAR(255) DEFAULT NULL, raw_payload JSON DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_log_event_occurred_at ON log_event (occurred_at)');
        $this->addSql('CREATE INDEX idx_log_event_source ON log_event (source)');
        $this->addSql('CREATE INDEX idx_log_event_category ON log_event (category)');
        $this->addSql('CREATE INDEX idx_log_event_level ON log_event (level)');
        $this->addSql('CREATE INDEX idx_log_event_fingerprint ON log_event (fingerprint)');
        $this->addSql('CREATE INDEX idx_log_event_project ON log_event (project_id)');
        $this->addSql('CREATE INDEX idx_log_event_task ON log_event (task_id)');
        $this->addSql('CREATE INDEX idx_log_event_agent ON log_event (agent_id)');
        $this->addSql('CREATE INDEX idx_log_event_request_ref ON log_event (request_ref)');
        $this->addSql('CREATE INDEX idx_log_event_exchange_ref ON log_event (exchange_ref)');
        $this->addSql('CREATE INDEX idx_log_event_trace_ref ON log_event (trace_ref)');

        $this->addSql('CREATE TABLE log_occurrence (id UUID NOT NULL, category VARCHAR(20) NOT NULL, level VARCHAR(20) NOT NULL, fingerprint VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, source VARCHAR(20) NOT NULL, project_id UUID DEFAULT NULL, task_id UUID DEFAULT NULL, agent_id UUID DEFAULT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, occurrence_count INT NOT NULL, status VARCHAR(20) NOT NULL, last_log_event_id UUID DEFAULT NULL, context_snapshot JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_log_occurrence_category_level_fingerprint ON log_occurrence (category, level, fingerprint)');
        $this->addSql('CREATE INDEX idx_log_occurrence_source ON log_occurrence (source)');
        $this->addSql('CREATE INDEX idx_log_occurrence_status ON log_occurrence (status)');
        $this->addSql('CREATE INDEX idx_log_occurrence_project ON log_occurrence (project_id)');
        $this->addSql('CREATE INDEX idx_log_occurrence_task ON log_occurrence (task_id)');
        $this->addSql('CREATE INDEX idx_log_occurrence_agent ON log_occurrence (agent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE log_occurrence');
        $this->addSql('DROP TABLE log_event');
    }
}

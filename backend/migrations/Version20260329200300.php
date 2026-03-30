<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260329200300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agent (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, connector VARCHAR(255) NOT NULL, config JSON NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, role_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_268B9C9DD60322AC ON agent (role_id)');
        $this->addSql('CREATE TABLE agent_action (id UUID NOT NULL, action_key VARCHAR(255) NOT NULL, label VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, role_id UUID DEFAULT NULL, skill_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_192BDBB4D60322AC ON agent_action (role_id)');
        $this->addSql('CREATE INDEX IDX_192BDBB45585C142 ON agent_action (skill_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_agent_action_key ON agent_action (action_key)');
        $this->addSql('CREATE TABLE agent_task_execution (id UUID NOT NULL, trace_ref VARCHAR(64) NOT NULL, trigger_type VARCHAR(255) NOT NULL, action_key VARCHAR(255) NOT NULL, action_label VARCHAR(255) DEFAULT NULL, role_slug VARCHAR(255) DEFAULT NULL, skill_slug VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, current_attempt SMALLINT DEFAULT 0 NOT NULL, max_attempts SMALLINT NOT NULL, request_ref VARCHAR(64) DEFAULT NULL, last_error_message TEXT DEFAULT NULL, last_error_scope VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, agent_action_id UUID DEFAULT NULL, requested_agent_id UUID DEFAULT NULL, effective_agent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2883FC132F002A47 ON agent_task_execution (trace_ref)');
        $this->addSql('CREATE INDEX IDX_2883FC136A667671 ON agent_task_execution (agent_action_id)');
        $this->addSql('CREATE INDEX IDX_2883FC134567389B ON agent_task_execution (requested_agent_id)');
        $this->addSql('CREATE INDEX IDX_2883FC13491C04CF ON agent_task_execution (effective_agent_id)');
        $this->addSql('CREATE INDEX idx_agent_task_execution_trace_ref ON agent_task_execution (trace_ref)');
        $this->addSql('CREATE INDEX idx_agent_task_execution_status ON agent_task_execution (status)');
        $this->addSql('CREATE TABLE agent_task_execution_attempt (id UUID NOT NULL, attempt_number SMALLINT NOT NULL, messenger_receiver VARCHAR(64) DEFAULT NULL, request_ref VARCHAR(64) DEFAULT NULL, status VARCHAR(255) NOT NULL, will_retry BOOLEAN DEFAULT false NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, error_scope VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, execution_id UUID NOT NULL, agent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_54DB87CD3414710B ON agent_task_execution_attempt (agent_id)');
        $this->addSql('CREATE INDEX idx_agent_task_execution_attempt_execution ON agent_task_execution_attempt (execution_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_agent_task_execution_attempt_execution_number ON agent_task_execution_attempt (execution_id, attempt_number)');
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, action VARCHAR(255) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id VARCHAR(36) DEFAULT NULL, data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_action ON audit_log (action)');
        $this->addSql('CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_created_at ON audit_log (created_at)');
        $this->addSql('CREATE TABLE chat_message (id UUID NOT NULL, author VARCHAR(255) NOT NULL, content TEXT NOT NULL, exchange_id VARCHAR(36) NOT NULL, is_error BOOLEAN NOT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, agent_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FAB3FC16166D1F9C ON chat_message (project_id)');
        $this->addSql('CREATE INDEX IDX_FAB3FC163414710B ON chat_message (agent_id)');
        $this->addSql('CREATE TABLE external_reference (id UUID NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id UUID NOT NULL, system VARCHAR(255) NOT NULL, external_id VARCHAR(255) NOT NULL, external_url VARCHAR(512) DEFAULT NULL, metadata JSON DEFAULT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_external_ref ON external_reference (entity_type, entity_id, system)');
        $this->addSql('CREATE TABLE feature (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1FD77566166D1F9C ON feature (project_id)');
        $this->addSql('CREATE TABLE log_event (id UUID NOT NULL, source VARCHAR(20) NOT NULL, category VARCHAR(20) NOT NULL, level VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, title_domain VARCHAR(64) DEFAULT NULL, title_key VARCHAR(255) DEFAULT NULL, title_parameters JSON DEFAULT NULL, message TEXT NOT NULL, message_domain VARCHAR(64) DEFAULT NULL, message_key VARCHAR(255) DEFAULT NULL, message_parameters JSON DEFAULT NULL, fingerprint VARCHAR(64) DEFAULT NULL, project_id UUID DEFAULT NULL, task_id UUID DEFAULT NULL, agent_id UUID DEFAULT NULL, exchange_ref VARCHAR(64) DEFAULT NULL, request_ref VARCHAR(64) DEFAULT NULL, trace_ref VARCHAR(64) DEFAULT NULL, context JSON DEFAULT NULL, stack TEXT DEFAULT NULL, origin VARCHAR(255) DEFAULT NULL, raw_payload JSON DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
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
        $this->addSql('CREATE TABLE log_occurrence (id UUID NOT NULL, category VARCHAR(20) NOT NULL, level VARCHAR(20) NOT NULL, fingerprint VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, title_domain VARCHAR(64) DEFAULT NULL, title_key VARCHAR(255) DEFAULT NULL, title_parameters JSON DEFAULT NULL, message TEXT NOT NULL, message_domain VARCHAR(64) DEFAULT NULL, message_key VARCHAR(255) DEFAULT NULL, message_parameters JSON DEFAULT NULL, source VARCHAR(20) NOT NULL, project_id UUID DEFAULT NULL, task_id UUID DEFAULT NULL, agent_id UUID DEFAULT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, occurrence_count INT NOT NULL, status VARCHAR(20) NOT NULL, last_log_event_id UUID DEFAULT NULL, context_snapshot JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_log_occurrence_source ON log_occurrence (source)');
        $this->addSql('CREATE INDEX idx_log_occurrence_status ON log_occurrence (status)');
        $this->addSql('CREATE INDEX idx_log_occurrence_project ON log_occurrence (project_id)');
        $this->addSql('CREATE INDEX idx_log_occurrence_task ON log_occurrence (task_id)');
        $this->addSql('CREATE INDEX idx_log_occurrence_agent ON log_occurrence (agent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_log_occurrence_category_level_fingerprint ON log_occurrence (category, level, fingerprint)');
        $this->addSql('CREATE TABLE module (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, repository_url VARCHAR(512) DEFAULT NULL, stack VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C242628166D1F9C ON module (project_id)');
        $this->addSql('CREATE TABLE project (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, repository_url VARCHAR(512) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, team_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2FB3D0EE296CD8AE ON project (team_id)');
        $this->addSql('CREATE TABLE role (id UUID NOT NULL, slug VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_57698A6A989D9B62 ON role (slug)');
        $this->addSql('CREATE TABLE role_skill (role_id UUID NOT NULL, skill_id UUID NOT NULL, PRIMARY KEY (role_id, skill_id))');
        $this->addSql('CREATE INDEX IDX_A9E10C58D60322AC ON role_skill (role_id)');
        $this->addSql('CREATE INDEX IDX_A9E10C585585C142 ON role_skill (skill_id)');
        $this->addSql('CREATE TABLE skill (id UUID NOT NULL, slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, source VARCHAR(255) NOT NULL, original_source VARCHAR(255) DEFAULT NULL, content TEXT NOT NULL, file_path VARCHAR(512) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E3DE477989D9B62 ON skill (slug)');
        $this->addSql('CREATE TABLE team (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE agent_team (team_id UUID NOT NULL, agent_id UUID NOT NULL, PRIMARY KEY (team_id, agent_id))');
        $this->addSql('CREATE INDEX IDX_697B1CFC296CD8AE ON agent_team (team_id)');
        $this->addSql('CREATE INDEX IDX_697B1CFC3414710B ON agent_team (agent_id)');
        $this->addSql('CREATE TABLE ticket (id UUID NOT NULL, type VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, story_status VARCHAR(255) DEFAULT NULL, priority VARCHAR(255) NOT NULL, progress SMALLINT DEFAULT 0 NOT NULL, branch_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, feature_id UUID DEFAULT NULL, workflow_step_id UUID DEFAULT NULL, assigned_agent_id UUID DEFAULT NULL, assigned_role_id UUID DEFAULT NULL, added_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_97A0ADA3166D1F9C ON ticket (project_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA360E4B879 ON ticket (feature_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA371FE882C ON ticket (workflow_step_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA349197702 ON ticket (assigned_agent_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA3DC9B9A23 ON ticket (assigned_role_id)');
        $this->addSql('CREATE INDEX IDX_97A0ADA355B127A4 ON ticket (added_by_id)');
        $this->addSql('CREATE TABLE ticket_log (id UUID NOT NULL, action VARCHAR(100) NOT NULL, content TEXT DEFAULT NULL, kind VARCHAR(20) DEFAULT \'event\' NOT NULL, author_type VARCHAR(20) DEFAULT NULL, author_name VARCHAR(255) DEFAULT NULL, requires_answer BOOLEAN DEFAULT false NOT NULL, reply_to_log_id UUID DEFAULT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ticket_id UUID NOT NULL, ticket_task_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8C46B3E9700047D2 ON ticket_log (ticket_id)');
        $this->addSql('CREATE INDEX IDX_8C46B3E9817A58D4 ON ticket_log (ticket_task_id)');
        $this->addSql('CREATE TABLE ticket_task (id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, priority VARCHAR(255) NOT NULL, progress SMALLINT DEFAULT 0 NOT NULL, branch_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ticket_id UUID NOT NULL, parent_id UUID DEFAULT NULL, workflow_step_id UUID DEFAULT NULL, agent_action_id UUID NOT NULL, assigned_agent_id UUID DEFAULT NULL, assigned_role_id UUID DEFAULT NULL, added_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_60A5CE1D700047D2 ON ticket_task (ticket_id)');
        $this->addSql('CREATE INDEX IDX_60A5CE1D727ACA70 ON ticket_task (parent_id)');
        $this->addSql('CREATE INDEX IDX_60A5CE1D71FE882C ON ticket_task (workflow_step_id)');
        $this->addSql('CREATE INDEX IDX_60A5CE1D6A667671 ON ticket_task (agent_action_id)');
        $this->addSql('CREATE INDEX IDX_60A5CE1D49197702 ON ticket_task (assigned_agent_id)');
        $this->addSql('CREATE INDEX IDX_60A5CE1DDC9B9A23 ON ticket_task (assigned_role_id)');
        $this->addSql('CREATE INDEX IDX_60A5CE1D55B127A4 ON ticket_task (added_by_id)');
        $this->addSql('CREATE TABLE ticket_task_agent_task_execution (ticket_task_id UUID NOT NULL, agent_task_execution_id UUID NOT NULL, PRIMARY KEY (ticket_task_id, agent_task_execution_id))');
        $this->addSql('CREATE INDEX IDX_5452AE6A817A58D4 ON ticket_task_agent_task_execution (ticket_task_id)');
        $this->addSql('CREATE INDEX IDX_5452AE6AB499A41A ON ticket_task_agent_task_execution (agent_task_execution_id)');
        $this->addSql('CREATE TABLE ticket_task_dependency (id UUID NOT NULL, ticket_task_id UUID NOT NULL, depends_on_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_31DF75BB817A58D4 ON ticket_task_dependency (ticket_task_id)');
        $this->addSql('CREATE INDEX IDX_31DF75BB1E088F8 ON ticket_task_dependency (depends_on_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_31DF75BB817A58D41E088F8 ON ticket_task_dependency (ticket_task_id, depends_on_id)');
        $this->addSql('CREATE TABLE token_usage (id UUID NOT NULL, model VARCHAR(100) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, duration_ms INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, agent_id UUID DEFAULT NULL, ticket_id UUID DEFAULT NULL, ticket_task_id UUID DEFAULT NULL, workflow_step_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_73355A313414710B ON token_usage (agent_id)');
        $this->addSql('CREATE INDEX IDX_73355A31700047D2 ON token_usage (ticket_id)');
        $this->addSql('CREATE INDEX IDX_73355A31817A58D4 ON token_usage (ticket_task_id)');
        $this->addSql('CREATE INDEX IDX_73355A3171FE882C ON token_usage (workflow_step_id)');
        $this->addSql('CREATE TABLE workflow (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, trigger VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, team_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_65C59816296CD8AE ON workflow (team_id)');
        $this->addSql('CREATE TABLE workflow_step (id UUID NOT NULL, step_order INT NOT NULL, name VARCHAR(255) NOT NULL, role_slug VARCHAR(255) DEFAULT NULL, skill_slug VARCHAR(255) DEFAULT NULL, input_config JSON NOT NULL, output_key VARCHAR(255) NOT NULL, condition TEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, story_status_trigger VARCHAR(255) DEFAULT NULL, last_output TEXT DEFAULT NULL, workflow_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_626EE072C7C2CBA ON workflow_step (workflow_id)');
        $this->addSql('ALTER TABLE agent ADD CONSTRAINT FK_268B9C9DD60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_action ADD CONSTRAINT FK_192BDBB4D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_action ADD CONSTRAINT FK_192BDBB45585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_task_execution ADD CONSTRAINT FK_2883FC136A667671 FOREIGN KEY (agent_action_id) REFERENCES agent_action (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_task_execution ADD CONSTRAINT FK_2883FC134567389B FOREIGN KEY (requested_agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_task_execution ADD CONSTRAINT FK_2883FC13491C04CF FOREIGN KEY (effective_agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_task_execution_attempt ADD CONSTRAINT FK_54DB87CD57125544 FOREIGN KEY (execution_id) REFERENCES agent_task_execution (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_task_execution_attempt ADD CONSTRAINT FK_54DB87CD3414710B FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC16166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC163414710B FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature ADD CONSTRAINT FK_1FD77566166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE module ADD CONSTRAINT FK_C242628166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE role_skill ADD CONSTRAINT FK_A9E10C58D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_skill ADD CONSTRAINT FK_A9E10C585585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_697B1CFC296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_697B1CFC3414710B FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA360E4B879 FOREIGN KEY (feature_id) REFERENCES feature (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA371FE882C FOREIGN KEY (workflow_step_id) REFERENCES workflow_step (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA349197702 FOREIGN KEY (assigned_agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3DC9B9A23 FOREIGN KEY (assigned_role_id) REFERENCES role (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA355B127A4 FOREIGN KEY (added_by_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_log ADD CONSTRAINT FK_8C46B3E9700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_log ADD CONSTRAINT FK_8C46B3E9817A58D4 FOREIGN KEY (ticket_task_id) REFERENCES ticket_task (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D727ACA70 FOREIGN KEY (parent_id) REFERENCES ticket_task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D71FE882C FOREIGN KEY (workflow_step_id) REFERENCES workflow_step (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D6A667671 FOREIGN KEY (agent_action_id) REFERENCES agent_action (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D49197702 FOREIGN KEY (assigned_agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1DDC9B9A23 FOREIGN KEY (assigned_role_id) REFERENCES role (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task ADD CONSTRAINT FK_60A5CE1D55B127A4 FOREIGN KEY (added_by_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task_agent_task_execution ADD CONSTRAINT FK_5452AE6A817A58D4 FOREIGN KEY (ticket_task_id) REFERENCES ticket_task (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_task_agent_task_execution ADD CONSTRAINT FK_5452AE6AB499A41A FOREIGN KEY (agent_task_execution_id) REFERENCES agent_task_execution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_task_dependency ADD CONSTRAINT FK_31DF75BB817A58D4 FOREIGN KEY (ticket_task_id) REFERENCES ticket_task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ticket_task_dependency ADD CONSTRAINT FK_31DF75BB1E088F8 FOREIGN KEY (depends_on_id) REFERENCES ticket_task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE token_usage ADD CONSTRAINT FK_73355A313414710B FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE token_usage ADD CONSTRAINT FK_73355A31700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE token_usage ADD CONSTRAINT FK_73355A31817A58D4 FOREIGN KEY (ticket_task_id) REFERENCES ticket_task (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE token_usage ADD CONSTRAINT FK_73355A3171FE882C FOREIGN KEY (workflow_step_id) REFERENCES workflow_step (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow ADD CONSTRAINT FK_65C59816296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_step ADD CONSTRAINT FK_626EE072C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflow (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent DROP CONSTRAINT FK_268B9C9DD60322AC');
        $this->addSql('ALTER TABLE agent_action DROP CONSTRAINT FK_192BDBB4D60322AC');
        $this->addSql('ALTER TABLE agent_action DROP CONSTRAINT FK_192BDBB45585C142');
        $this->addSql('ALTER TABLE agent_task_execution DROP CONSTRAINT FK_2883FC136A667671');
        $this->addSql('ALTER TABLE agent_task_execution DROP CONSTRAINT FK_2883FC134567389B');
        $this->addSql('ALTER TABLE agent_task_execution DROP CONSTRAINT FK_2883FC13491C04CF');
        $this->addSql('ALTER TABLE agent_task_execution_attempt DROP CONSTRAINT FK_54DB87CD57125544');
        $this->addSql('ALTER TABLE agent_task_execution_attempt DROP CONSTRAINT FK_54DB87CD3414710B');
        $this->addSql('ALTER TABLE chat_message DROP CONSTRAINT FK_FAB3FC16166D1F9C');
        $this->addSql('ALTER TABLE chat_message DROP CONSTRAINT FK_FAB3FC163414710B');
        $this->addSql('ALTER TABLE feature DROP CONSTRAINT FK_1FD77566166D1F9C');
        $this->addSql('ALTER TABLE module DROP CONSTRAINT FK_C242628166D1F9C');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE296CD8AE');
        $this->addSql('ALTER TABLE role_skill DROP CONSTRAINT FK_A9E10C58D60322AC');
        $this->addSql('ALTER TABLE role_skill DROP CONSTRAINT FK_A9E10C585585C142');
        $this->addSql('ALTER TABLE agent_team DROP CONSTRAINT FK_697B1CFC296CD8AE');
        $this->addSql('ALTER TABLE agent_team DROP CONSTRAINT FK_697B1CFC3414710B');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA3166D1F9C');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA360E4B879');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA371FE882C');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA349197702');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA3DC9B9A23');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA355B127A4');
        $this->addSql('ALTER TABLE ticket_log DROP CONSTRAINT FK_8C46B3E9700047D2');
        $this->addSql('ALTER TABLE ticket_log DROP CONSTRAINT FK_8C46B3E9817A58D4');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D700047D2');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D727ACA70');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D71FE882C');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D6A667671');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D49197702');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1DDC9B9A23');
        $this->addSql('ALTER TABLE ticket_task DROP CONSTRAINT FK_60A5CE1D55B127A4');
        $this->addSql('ALTER TABLE ticket_task_agent_task_execution DROP CONSTRAINT FK_5452AE6A817A58D4');
        $this->addSql('ALTER TABLE ticket_task_agent_task_execution DROP CONSTRAINT FK_5452AE6AB499A41A');
        $this->addSql('ALTER TABLE ticket_task_dependency DROP CONSTRAINT FK_31DF75BB817A58D4');
        $this->addSql('ALTER TABLE ticket_task_dependency DROP CONSTRAINT FK_31DF75BB1E088F8');
        $this->addSql('ALTER TABLE token_usage DROP CONSTRAINT FK_73355A313414710B');
        $this->addSql('ALTER TABLE token_usage DROP CONSTRAINT FK_73355A31700047D2');
        $this->addSql('ALTER TABLE token_usage DROP CONSTRAINT FK_73355A31817A58D4');
        $this->addSql('ALTER TABLE token_usage DROP CONSTRAINT FK_73355A3171FE882C');
        $this->addSql('ALTER TABLE workflow DROP CONSTRAINT FK_65C59816296CD8AE');
        $this->addSql('ALTER TABLE workflow_step DROP CONSTRAINT FK_626EE072C7C2CBA');
        $this->addSql('DROP TABLE agent');
        $this->addSql('DROP TABLE agent_action');
        $this->addSql('DROP TABLE agent_task_execution');
        $this->addSql('DROP TABLE agent_task_execution_attempt');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE external_reference');
        $this->addSql('DROP TABLE feature');
        $this->addSql('DROP TABLE log_event');
        $this->addSql('DROP TABLE log_occurrence');
        $this->addSql('DROP TABLE module');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE role');
        $this->addSql('DROP TABLE role_skill');
        $this->addSql('DROP TABLE skill');
        $this->addSql('DROP TABLE team');
        $this->addSql('DROP TABLE agent_team');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE ticket_log');
        $this->addSql('DROP TABLE ticket_task');
        $this->addSql('DROP TABLE ticket_task_agent_task_execution');
        $this->addSql('DROP TABLE ticket_task_dependency');
        $this->addSql('DROP TABLE token_usage');
        $this->addSql('DROP TABLE workflow');
        $this->addSql('DROP TABLE workflow_step');
    }
}

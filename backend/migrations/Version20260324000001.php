<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration initiale : création de toutes les tables SoManAgent
 */
final class Version20260324000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial SoManAgent : projets, modules, équipes, rôles, agents, skills, workflows, audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS "uuid-ossp"
        SQL);

        // ── Projects ────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE projects (
                id            UUID NOT NULL DEFAULT gen_random_uuid(),
                name          VARCHAR(255) NOT NULL,
                description   TEXT DEFAULT NULL,
                created_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // ── Modules ─────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE modules (
                id                UUID NOT NULL DEFAULT gen_random_uuid(),
                project_id        UUID NOT NULL,
                name              VARCHAR(255) NOT NULL,
                description       TEXT DEFAULT NULL,
                tech_stack        VARCHAR(255) DEFAULT NULL,
                repository_url    VARCHAR(512) DEFAULT NULL,
                repository_branch VARCHAR(255) DEFAULT 'main',
                status            VARCHAR(50) NOT NULL DEFAULT 'active',
                created_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_module_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_modules_project ON modules (project_id)');

        // ── Teams ────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE teams (
                id          UUID NOT NULL DEFAULT gen_random_uuid(),
                name        VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // ── Roles ────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE roles (
                id          UUID NOT NULL DEFAULT gen_random_uuid(),
                team_id     UUID NOT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                skill_slug  VARCHAR(255) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_role_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_roles_team ON roles (team_id)');

        // ── Agents ───────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE agents (
                id             UUID NOT NULL DEFAULT gen_random_uuid(),
                name           VARCHAR(255) NOT NULL,
                connector_name VARCHAR(100) NOT NULL,
                config         JSONB NOT NULL DEFAULT '{}',
                created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // ── Skills ───────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE skills (
                id          UUID NOT NULL DEFAULT gen_random_uuid(),
                slug        VARCHAR(255) NOT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                content     TEXT NOT NULL,
                metadata    JSONB NOT NULL DEFAULT '{}',
                source      VARCHAR(50) NOT NULL DEFAULT 'custom',
                origin_ref  VARCHAR(255) DEFAULT NULL,
                local_path  VARCHAR(512) NOT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uq_skill_slug UNIQUE (slug)
            )
        SQL);

        // ── Workflows ────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE workflows (
                id          UUID NOT NULL DEFAULT gen_random_uuid(),
                name        VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                trigger     VARCHAR(50) NOT NULL DEFAULT 'manual',
                steps       JSONB NOT NULL DEFAULT '[]',
                is_dry_run  BOOLEAN NOT NULL DEFAULT FALSE,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // ── Audit Logs ───────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_logs (
                id            UUID NOT NULL DEFAULT gen_random_uuid(),
                action        VARCHAR(100) NOT NULL,
                entity_type   VARCHAR(100) NOT NULL,
                entity_id     VARCHAR(255) DEFAULT NULL,
                context       JSONB NOT NULL DEFAULT '{}',
                result        VARCHAR(50) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                occurred_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_audit_action ON audit_logs (action)');
        $this->addSql('CREATE INDEX idx_audit_entity ON audit_logs (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_occurred ON audit_logs (occurred_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_logs');
        $this->addSql('DROP TABLE IF EXISTS workflows');
        $this->addSql('DROP TABLE IF EXISTS skills');
        $this->addSql('DROP TABLE IF EXISTS agents');
        $this->addSql('DROP TABLE IF EXISTS roles');
        $this->addSql('DROP TABLE IF EXISTS teams');
        $this->addSql('DROP TABLE IF EXISTS modules');
        $this->addSql('DROP TABLE IF EXISTS projects');
    }
}

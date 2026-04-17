<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416144044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $defaultEffects = '["log_agent_response","ask_clarification","complete_current_task"]';
        $productEffects = '["log_agent_response","ask_clarification","complete_current_task","rewrite_ticket","complete_ticket"]';
        $planningEffects = '["log_agent_response","ask_clarification","complete_current_task","replace_planning_tasks","create_subtasks","prepare_branch","update_ticket_progress"]';

        $this->addSql(sprintf('ALTER TABLE agent_action ADD allowed_effects JSON NOT NULL DEFAULT \'%s\'', $defaultEffects));
        $this->addSql(sprintf(
            <<<'SQL'
                UPDATE agent_action
                SET allowed_effects = CASE action_key
                    WHEN 'product.specify' THEN '%s'
                    WHEN 'tech.plan' THEN '%s'
                    ELSE '%s'
                END
            SQL,
            $productEffects,
            $planningEffects,
            $defaultEffects,
        ));
        $this->addSql('ALTER TABLE agent_action ALTER allowed_effects DROP DEFAULT');
        $this->addSql('ALTER TABLE project ALTER dispatch_mode TYPE VARCHAR(255)');
        $this->addSql('ALTER INDEX idx_project_default_ticket_role RENAME TO IDX_2FB3D0EE5E471BBC');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_action DROP allowed_effects');
        $this->addSql('ALTER TABLE project ALTER dispatch_mode TYPE VARCHAR(20)');
        $this->addSql('ALTER INDEX idx_2fb3d0ee5e471bbc RENAME TO idx_project_default_ticket_role');
    }
}

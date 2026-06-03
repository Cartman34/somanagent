<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Command;

use Sowapps\SoManAgent\Entity\Role;
use Sowapps\SoManAgent\Entity\Skill;
use Sowapps\SoManAgent\Entity\Team;
use Sowapps\SoManAgent\ValueObject\ConnectorConfig;
use Sowapps\SoManAgent\Entity\Agent;
use Sowapps\SoManAgent\Enum\ConnectorType;
use Sowapps\SoManAgent\Entity\AgentAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to seed default web development roles, skills, agents, and team.
 */
#[AsCommand(
    name: 'somanagent:seed:web-team',
    description: 'Creates the default web development roles and team.',
)]
class SeedWebTeamCommand extends Command
{
    private const ROLE_PRODUCT_OWNER  = 'product-owner';
    private const ROLE_SCRUM_MASTER   = 'scrum-master';
    private const ROLE_LEAD_TECH      = 'lead-tech';
    private const ROLE_DEV_PHP        = 'dev-php';
    private const ROLE_DEV_FRONTEND   = 'dev-frontend';
    private const ROLE_QA_TESTER      = 'qa-tester';
    private const ROLE_UI_UX_DESIGNER = 'ui-ux-designer';
    private const ROLE_TECH_WRITER    = 'tech-writer';
    private const ROLE_DEVOPS         = 'devops';

    private const ROLES = [
        ['slug' => self::ROLE_PRODUCT_OWNER,  'name' => 'Product Owner',      'description' => 'Defines user stories, prioritizes the backlog, and accepts deliveries.'],
        ['slug' => self::ROLE_SCRUM_MASTER,   'name' => 'Scrum Master',       'description' => 'Facilitates agile ceremonies and removes blockers.'],
        ['slug' => self::ROLE_LEAD_TECH,      'name' => 'Lead Tech',          'description' => 'Handles technical architecture, code review, and mentoring.'],
        ['slug' => self::ROLE_DEV_PHP,        'name' => 'PHP Developer',      'description' => 'Builds the PHP/Symfony backend.'],
        ['slug' => self::ROLE_DEV_FRONTEND,   'name' => 'Frontend Developer', 'description' => 'Builds React, TypeScript, and CSS interfaces.'],
        ['slug' => self::ROLE_QA_TESTER,      'name' => 'QA Tester',          'description' => 'Runs functional tests, reports issues, and validates releases.'],
        ['slug' => self::ROLE_UI_UX_DESIGNER, 'name' => 'UI/UX Designer',     'description' => 'Produces mockups, design system assets, and user experience guidance.'],
        ['slug' => self::ROLE_TECH_WRITER,    'name' => 'Technical Writer',   'description' => 'Writes technical and functional documentation.'],
        ['slug' => self::ROLE_DEVOPS,         'name' => 'DevOps',             'description' => 'Owns CI/CD, infrastructure, monitoring, and deployments.'],
    ];

    private const AGENTS = [
        ['name' => 'PO - Alice',     'slug' => self::ROLE_PRODUCT_OWNER,  'description' => 'Product Owner agent. Writes user stories and prioritizes the backlog.'],
        ['name' => 'Scrum - Bob',    'slug' => self::ROLE_SCRUM_MASTER,   'description' => 'Scrum Master agent. Facilitates the team and keeps work synchronized.'],
        ['name' => 'Lead - Clara',   'slug' => self::ROLE_LEAD_TECH,      'description' => 'Lead Tech agent. Reviews architecture and code decisions.'],
        ['name' => 'PHP - David',    'slug' => self::ROLE_DEV_PHP,        'description' => 'PHP developer agent. Implements backend features.'],
        ['name' => 'Front - Emma',   'slug' => self::ROLE_DEV_FRONTEND,   'description' => 'Frontend developer agent. Implements React interfaces.'],
        ['name' => 'QA - Felix',     'slug' => self::ROLE_QA_TESTER,      'description' => 'QA agent. Writes and executes tests, then reports issues.'],
        ['name' => 'Design - Grace', 'slug' => self::ROLE_UI_UX_DESIGNER, 'description' => 'UI/UX designer agent. Produces mockups and design system assets.'],
        ['name' => 'Doc - Hugo',     'slug' => self::ROLE_TECH_WRITER,    'description' => 'Technical writer agent. Produces technical documentation.'],
        ['name' => 'DevOps - Iris',  'slug' => self::ROLE_DEVOPS,         'description' => 'DevOps agent. Manages infrastructure and deployments.'],
    ];

    private const ACTIONS = [
        ['key' => 'product.specify', 'label' => 'Product specification', 'role' => self::ROLE_PRODUCT_OWNER,  'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task', 'rewrite_ticket', 'complete_ticket']],
        ['key' => 'tech.plan', 'label' => 'Technical planning', 'role' => self::ROLE_LEAD_TECH,       'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task', 'replace_planning_tasks', 'create_subtasks', 'prepare_branch', 'update_ticket_progress']],
        ['key' => 'design.ui_mockup', 'label' => 'UI mockup', 'role' => self::ROLE_UI_UX_DESIGNER,   'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task']],
        ['key' => 'dev.backend.implement', 'label' => 'Backend implementation', 'role' => self::ROLE_DEV_PHP,  'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task']],
        ['key' => 'dev.frontend.implement', 'label' => 'Frontend implementation', 'role' => self::ROLE_DEV_FRONTEND, 'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task']],
        ['key' => 'review.code', 'label' => 'Code review', 'role' => self::ROLE_LEAD_TECH,            'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task']],
        ['key' => 'qa.validate', 'label' => 'QA validation', 'role' => self::ROLE_QA_TESTER,          'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task']],
        ['key' => 'docs.write', 'label' => 'Documentation writing', 'role' => self::ROLE_TECH_WRITER, 'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task']],
        ['key' => 'ops.configure', 'label' => 'Infrastructure configuration', 'role' => self::ROLE_DEVOPS, 'skill' => null, 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task']],
    ];

    /**
     * Initializes the command with the entity manager used to persist the seed dataset.
     */
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    /**
     * Declares the optional force flag used to recreate the dataset.
     */
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Recreate the dataset even if roles already exist.');
    }

    /**
     * Seeds default roles, agents, actions, and team records for a web development setup.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $existing = $this->em->getRepository(Role::class)->findAll();
        if (count($existing) > 0 && !$force) {
            $io->warning('Roles already exist. Use --force to recreate them.');
            return Command::SUCCESS;
        }

        $io->title('SoManAgent — Web Development Team Seed');

        // Roles
        $roles = [];
        foreach (self::ROLES as $def) {
            $role = new Role($def['slug'], $def['name'], $def['description']);
            $this->em->persist($role);
            $roles[$def['slug']] = $role;
            $io->text("  Role: <info>{$def['name']}</info>");
        }

        $skills = [];
        foreach ($this->em->getRepository(Skill::class)->findAll() as $skill) {
            $skills[$skill->getSlug()] = $skill;
        }

        // Team
        $team = new Team('Web Team', 'Full-stack web development team.');
        $this->em->persist($team);
        $io->text('  Team: <info>Web Team</info>');

        // Agents
        $config = ConnectorConfig::fromArray(['model' => 'claude-sonnet-4-6']);
        foreach (self::AGENTS as $def) {
            $agent = new Agent($def['name'], ConnectorType::ClaudeApi, $config, $def['description']);
            $agent->setRole($roles[$def['slug']]);
            $this->em->persist($agent);
            $team->addAgent($agent);
            $io->text("  Agent: <comment>{$def['name']}</comment> [{$def['slug']}]");
        }

        foreach (self::ACTIONS as $def) {
            $action = new AgentAction($def['key'], $def['label']);
            $action->setRole($roles[$def['role']]);
            $action->setSkill(null);
            $action->setAllowedEffects($def['effects']);
            $this->em->persist($action);
            $io->text("  Action: <fg=cyan>{$def['key']}</>");
        }

        $this->em->flush();

        $io->success(sprintf('%d roles, %d agents, and team "%s" created.', count(self::ROLES), count(self::AGENTS), $team->getName()));
        return Command::SUCCESS;
    }
}

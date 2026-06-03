<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\DataFixtures;

use Sowapps\SoManAgent\Entity\Skill;
use Sowapps\SoManAgent\Enum\SkillSource;
use Sowapps\SoManAgent\Entity\Role;
use Sowapps\SoManAgent\Entity\AgentAction;
use Sowapps\SoManAgent\ValueObject\ConnectorConfig;
use Sowapps\SoManAgent\Entity\Agent;
use Sowapps\SoManAgent\Enum\ConnectorType;
use Sowapps\SoManAgent\Entity\Team;
use Sowapps\SoManAgent\Entity\Workflow;
use Sowapps\SoManAgent\Enum\WorkflowTrigger;
use Sowapps\SoManAgent\Enum\WorkflowStepTransitionMode;
use Sowapps\SoManAgent\Entity\WorkflowStep;
use Sowapps\SoManAgent\Entity\WorkflowStepAction;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Seeds a complete web-development team with roles, skills, agents, and a workflow template.
 */
class DevTeamFixture extends Fixture
{
    private const SKILLS_BASE_PATH = '/var/www/skills/custom';
    private const TRANSLATION_DOMAIN = 'fixtures';

    private const ROLE_PRODUCT_OWNER  = 'product-owner';
    private const ROLE_LEAD_TECH      = 'lead-tech';
    private const ROLE_PHP_DEV        = 'php-dev';
    private const ROLE_FRONTEND_DEV   = 'frontend-dev';
    private const ROLE_UI_UX_DESIGNER = 'ui-ux-designer';
    private const ROLE_SCRUM_MASTER   = 'scrum-master';
    private const ROLE_TECH_WRITER    = 'tech-writer';
    private const ROLE_DEVOPS         = 'devops';

    private const SKILL_TECH_PLANNING          = 'tech-planning';
    private const SKILL_SPEC_WRITER            = 'spec-writer';
    private const SKILL_CODE_REVIEWER          = 'code-reviewer';
    private const SKILL_PHP_BACKEND_DEV        = 'php-backend-dev';
    private const SKILL_JS_FRONTEND_DEV        = 'js-frontend-dev';
    private const SKILL_UI_DESIGN              = 'ui-design';
    private const SKILL_TEST_WRITING           = 'test-writing';
    private const SKILL_BUG_REPORTING          = 'bug-reporting';
    private const SKILL_DOCUMENTATION_WRITING  = 'documentation-writing';
    private const SKILL_CI_CD_SETUP            = 'ci-cd-setup';

    /**
     * Injects the translator used to resolve fixture-specific labels.
     */
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Loads the demo team, its agents, and its workflow fixtures into the database.
     */
    public function load(ObjectManager $manager): void
    {
        $skills = $this->loadSkills($manager);
        $roles  = $this->createRoles($manager, $skills);
        $actions = $this->createAgentActions($manager, $roles, $skills);
        $agents = $this->createAgents($manager, $roles);
        $team   = $this->createTeam($manager, $agents);
        $this->createWorkflowTemplate($manager, $actions);

        $manager->flush();
    }

    /**
     * @return array<string, Skill>
     */
    private function loadSkills(ObjectManager $manager): array
    {
        $definitions = [
            self::ROLE_PRODUCT_OWNER, self::SKILL_TECH_PLANNING, self::SKILL_SPEC_WRITER, self::SKILL_CODE_REVIEWER,
            self::SKILL_PHP_BACKEND_DEV, self::SKILL_JS_FRONTEND_DEV, self::SKILL_UI_DESIGN,
            self::SKILL_TEST_WRITING, self::SKILL_BUG_REPORTING, self::SKILL_DOCUMENTATION_WRITING, self::SKILL_CI_CD_SETUP,
        ];

        $skills = [];
        foreach ($definitions as $slug) {
            $path    = self::SKILLS_BASE_PATH . '/' . $slug . '/SKILL.md';
            $content = file_exists($path) ? (string) file_get_contents($path) : '';
            $name    = $this->extractFrontmatterField($content, 'name') ?? $slug;

            $skill = new Skill($slug, $name, $content, $path, SkillSource::Custom);
            $manager->persist($skill);
            $skills[$slug] = $skill;
        }

        return $skills;
    }

    /**
     * @param array<string, Skill> $skills
     * @return array<string, Role>
     */
    private function createRoles(ObjectManager $manager, array $skills): array
    {
        $definitions = [
            self::ROLE_PRODUCT_OWNER  => ['name' => $this->translator->trans('fixtures.role.product_owner.name', [], self::TRANSLATION_DOMAIN),  'desc' => $this->translator->trans('fixtures.role.product_owner.description', [], self::TRANSLATION_DOMAIN),  'skills' => [self::ROLE_PRODUCT_OWNER, self::SKILL_BUG_REPORTING]],
            self::ROLE_LEAD_TECH      => ['name' => $this->translator->trans('fixtures.role.lead_tech.name', [], self::TRANSLATION_DOMAIN),      'desc' => $this->translator->trans('fixtures.role.lead_tech.description', [], self::TRANSLATION_DOMAIN),      'skills' => [self::SKILL_TECH_PLANNING, self::SKILL_SPEC_WRITER, self::SKILL_CODE_REVIEWER]],
            self::ROLE_PHP_DEV        => ['name' => $this->translator->trans('fixtures.role.php_dev.name', [], self::TRANSLATION_DOMAIN),        'desc' => $this->translator->trans('fixtures.role.php_dev.description', [], self::TRANSLATION_DOMAIN),        'skills' => [self::SKILL_PHP_BACKEND_DEV]],
            self::ROLE_FRONTEND_DEV   => ['name' => $this->translator->trans('fixtures.role.frontend_dev.name', [], self::TRANSLATION_DOMAIN),   'desc' => $this->translator->trans('fixtures.role.frontend_dev.description', [], self::TRANSLATION_DOMAIN),   'skills' => [self::SKILL_JS_FRONTEND_DEV]],
            self::ROLE_UI_UX_DESIGNER => ['name' => $this->translator->trans('fixtures.role.ui_ux_designer.name', [], self::TRANSLATION_DOMAIN), 'desc' => $this->translator->trans('fixtures.role.ui_ux_designer.description', [], self::TRANSLATION_DOMAIN), 'skills' => [self::SKILL_UI_DESIGN]],
            'tester'                  => ['name' => $this->translator->trans('fixtures.role.tester.name', [], self::TRANSLATION_DOMAIN),         'desc' => $this->translator->trans('fixtures.role.tester.description', [], self::TRANSLATION_DOMAIN),         'skills' => [self::SKILL_TEST_WRITING, self::SKILL_BUG_REPORTING]],
            self::ROLE_SCRUM_MASTER   => ['name' => $this->translator->trans('fixtures.role.scrum_master.name', [], self::TRANSLATION_DOMAIN),   'desc' => $this->translator->trans('fixtures.role.scrum_master.description', [], self::TRANSLATION_DOMAIN),   'skills' => []],
            self::ROLE_TECH_WRITER    => ['name' => $this->translator->trans('fixtures.role.tech_writer.name', [], self::TRANSLATION_DOMAIN),    'desc' => $this->translator->trans('fixtures.role.tech_writer.description', [], self::TRANSLATION_DOMAIN),    'skills' => [self::SKILL_DOCUMENTATION_WRITING]],
            self::ROLE_DEVOPS         => ['name' => $this->translator->trans('fixtures.role.devops.name', [], self::TRANSLATION_DOMAIN),         'desc' => $this->translator->trans('fixtures.role.devops.description', [], self::TRANSLATION_DOMAIN),         'skills' => [self::SKILL_CI_CD_SETUP]],
        ];

        $roles = [];
        foreach ($definitions as $slug => $def) {
            $role = new Role($slug, $def['name'], $def['desc']);
            foreach ($def['skills'] as $skillSlug) {
                if (isset($skills[$skillSlug])) {
                    $role->addSkill($skills[$skillSlug]);
                }
            }
            $manager->persist($role);
            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * @param array<string, Role> $roles
     * @param array<string, Skill> $skills
     * @return array<string, AgentAction>
     */
    private function createAgentActions(ObjectManager $manager, array $roles, array $skills): array
    {
        $baseEffects = [
            'log_agent_response',
            'ask_clarification',
            'complete_current_task',
        ];

        $definitions = [
            ['key' => 'product.specify',        'label' => 'Product specification',         'role' => self::ROLE_PRODUCT_OWNER,  'skill' => self::ROLE_PRODUCT_OWNER,        'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task', 'rewrite_ticket', 'complete_ticket']],
            ['key' => 'tech.plan',              'label' => 'Technical planning',            'role' => self::ROLE_LEAD_TECH,      'skill' => self::SKILL_TECH_PLANNING,        'effects' => [...$baseEffects, 'replace_planning_tasks', 'create_subtasks', 'prepare_branch', 'update_ticket_progress']],
            ['key' => 'design.ui_mockup',       'label' => 'UI mockup',                    'role' => self::ROLE_UI_UX_DESIGNER, 'skill' => self::SKILL_UI_DESIGN,            'effects' => $baseEffects],
            ['key' => 'dev.backend.implement',  'label' => 'Backend implementation',       'role' => self::ROLE_PHP_DEV,        'skill' => self::SKILL_PHP_BACKEND_DEV,      'effects' => $baseEffects],
            ['key' => 'dev.frontend.implement', 'label' => 'Frontend implementation',      'role' => self::ROLE_FRONTEND_DEV,   'skill' => self::SKILL_JS_FRONTEND_DEV,      'effects' => $baseEffects],
            ['key' => 'review.code',            'label' => 'Code review',                  'role' => self::ROLE_LEAD_TECH,      'skill' => self::SKILL_CODE_REVIEWER,        'effects' => $baseEffects],
            ['key' => 'qa.validate',            'label' => 'QA validation',                'role' => 'tester',                  'skill' => self::SKILL_TEST_WRITING,         'effects' => $baseEffects],
            ['key' => 'docs.write',             'label' => 'Documentation writing',        'role' => self::ROLE_TECH_WRITER,    'skill' => self::SKILL_DOCUMENTATION_WRITING,'effects' => $baseEffects],
            ['key' => 'ops.configure',          'label' => 'Infrastructure configuration', 'role' => self::ROLE_DEVOPS,         'skill' => self::SKILL_CI_CD_SETUP,          'effects' => $baseEffects],
        ];

        $actions = [];
        foreach ($definitions as $definition) {
            $action = new AgentAction($definition['key'], $definition['label']);
            $action->setRole($roles[$definition['role']] ?? null);
            $action->setSkill($skills[$definition['skill']] ?? null);
            $action->setAllowedEffects($definition['effects']);
            $manager->persist($action);
            $actions[$definition['key']] = $action;
        }

        return $actions;
    }

    /**
     * @param array<string, Role> $roles
     * @return array<string, Agent>
     */
    private function createAgents(ObjectManager $manager, array $roles): array
    {
        $config = ConnectorConfig::default();

        $definitions = [
            'po-alice'    => ['name' => $this->translator->trans('fixtures.agent.po_alice.name', [], self::TRANSLATION_DOMAIN),    'role' => self::ROLE_PRODUCT_OWNER],
            'lt-bob'      => ['name' => $this->translator->trans('fixtures.agent.lt_bob.name', [], self::TRANSLATION_DOMAIN),      'role' => self::ROLE_LEAD_TECH],
            'php-charlie' => ['name' => $this->translator->trans('fixtures.agent.php_charlie.name', [], self::TRANSLATION_DOMAIN), 'role' => self::ROLE_PHP_DEV],
            'front-diana' => ['name' => $this->translator->trans('fixtures.agent.front_diana.name', [], self::TRANSLATION_DOMAIN), 'role' => self::ROLE_FRONTEND_DEV],
            'design-eve'  => ['name' => $this->translator->trans('fixtures.agent.design_eve.name', [], self::TRANSLATION_DOMAIN),  'role' => self::ROLE_UI_UX_DESIGNER],
            'qa-frank'    => ['name' => $this->translator->trans('fixtures.agent.qa_frank.name', [], self::TRANSLATION_DOMAIN),    'role' => 'tester'],
            'sm-grace'    => ['name' => $this->translator->trans('fixtures.agent.sm_grace.name', [], self::TRANSLATION_DOMAIN),    'role' => self::ROLE_SCRUM_MASTER],
            'doc-henry'   => ['name' => $this->translator->trans('fixtures.agent.doc_henry.name', [], self::TRANSLATION_DOMAIN),   'role' => self::ROLE_TECH_WRITER],
            'ops-iris'    => ['name' => $this->translator->trans('fixtures.agent.ops_iris.name', [], self::TRANSLATION_DOMAIN),    'role' => self::ROLE_DEVOPS],
        ];

        $agents = [];
        foreach ($definitions as $key => $def) {
            $agent = new Agent($def['name'], ConnectorType::ClaudeCli, $config);
            $agent->setRole($roles[$def['role']]);
            $manager->persist($agent);
            $agents[$key] = $agent;
        }

        return $agents;
    }

    /**
     * @param array<string, Agent> $agents
     */
    private function createTeam(ObjectManager $manager, array $agents): Team
    {
        $team = new Team(
            $this->translator->trans('fixtures.team.web_dev.name', [], self::TRANSLATION_DOMAIN),
            $this->translator->trans('fixtures.team.web_dev.description', [], self::TRANSLATION_DOMAIN),
        );
        foreach ($agents as $agent) {
            $team->addAgent($agent);
        }
        $manager->persist($team);
        return $team;
    }

    /**
     * @param array<string, AgentAction> $actions
     */
    private function createWorkflowTemplate(ObjectManager $manager, array $actions): Workflow
    {
        $workflow = new Workflow(
            $this->translator->trans('fixtures.workflow.standard.name', [], self::TRANSLATION_DOMAIN),
            WorkflowTrigger::Manual,
            $this->translator->trans('fixtures.workflow.standard.description', [], self::TRANSLATION_DOMAIN),
        );
        foreach ([
            [1, $this->translator->trans('fixtures.workflow.standard.step.new.name', [], self::TRANSLATION_DOMAIN),            'new',            WorkflowStepTransitionMode::Automatic, [['product.specify', true]]],
            [2, $this->translator->trans('fixtures.workflow.standard.step.ready.name', [], self::TRANSLATION_DOMAIN),          'ready',          WorkflowStepTransitionMode::Manual,    []],
            [3, $this->translator->trans('fixtures.workflow.standard.step.planning.name', [], self::TRANSLATION_DOMAIN),       'planning',       WorkflowStepTransitionMode::Automatic, [['tech.plan', true]]],
            [4, $this->translator->trans('fixtures.workflow.standard.step.graphic_design.name', [], self::TRANSLATION_DOMAIN), 'graphic_design', WorkflowStepTransitionMode::Automatic, [['design.ui_mockup', false]]],
            [5, $this->translator->trans('fixtures.workflow.standard.step.development.name', [], self::TRANSLATION_DOMAIN),    'development',    WorkflowStepTransitionMode::Automatic, [['dev.backend.implement', false], ['dev.frontend.implement', false]]],
            [6, $this->translator->trans('fixtures.workflow.standard.step.code_review.name', [], self::TRANSLATION_DOMAIN),    'code_review',    WorkflowStepTransitionMode::Automatic, [['review.code', false]]],
            [7, $this->translator->trans('fixtures.workflow.standard.step.done.name', [], self::TRANSLATION_DOMAIN),           'done',           WorkflowStepTransitionMode::Manual,    []],
        ] as [$order, $name, $key, $transitionMode, $stepActions]) {
            $step = new WorkflowStep($workflow, $order, $name, $key);
            $step->setTransitionMode($transitionMode);

            foreach ($stepActions as [$actionKey, $createWithTicket]) {
                $action = $actions[$actionKey] ?? null;
                if ($action === null) {
                    continue;
                }

                $step->addAction(
                    (new WorkflowStepAction($step, $action))
                        ->setCreateWithTicket($createWithTicket)
                );
            }

            $manager->persist($step);
        }

        $manager->persist($workflow);
        return $workflow;
    }

    private function extractFrontmatterField(string $content, string $field): ?string
    {
        if (preg_match('/^' . preg_quote($field, '/') . ':\s*(.+)$/m', $content, $m)) {
            return trim($m[1]);
        }
        return null;
    }

}

<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Agent;
use App\Entity\AgentAction;
use App\Entity\Role;
use App\Entity\Skill;
use App\Entity\Team;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowStepAction;
use App\Enum\ConnectorType;
use App\Enum\SkillSource;
use App\Enum\WorkflowStepTransitionMode;
use App\Enum\WorkflowTrigger;
use App\ValueObject\ConnectorConfig;
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
            'product-owner', 'tech-planning', 'spec-writer', 'code-reviewer',
            'php-backend-dev', 'js-frontend-dev', 'ui-design',
            'test-writing', 'bug-reporting', 'documentation-writing', 'ci-cd-setup',
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
            'product-owner'  => ['name' => $this->translator->trans('fixtures.role.product_owner.name', [], self::TRANSLATION_DOMAIN),  'desc' => $this->translator->trans('fixtures.role.product_owner.description', [], self::TRANSLATION_DOMAIN),  'skills' => ['product-owner', 'bug-reporting']],
            'lead-tech'      => ['name' => $this->translator->trans('fixtures.role.lead_tech.name', [], self::TRANSLATION_DOMAIN),      'desc' => $this->translator->trans('fixtures.role.lead_tech.description', [], self::TRANSLATION_DOMAIN),      'skills' => ['tech-planning', 'spec-writer', 'code-reviewer']],
            'php-dev'        => ['name' => $this->translator->trans('fixtures.role.php_dev.name', [], self::TRANSLATION_DOMAIN),        'desc' => $this->translator->trans('fixtures.role.php_dev.description', [], self::TRANSLATION_DOMAIN),        'skills' => ['php-backend-dev']],
            'frontend-dev'   => ['name' => $this->translator->trans('fixtures.role.frontend_dev.name', [], self::TRANSLATION_DOMAIN),   'desc' => $this->translator->trans('fixtures.role.frontend_dev.description', [], self::TRANSLATION_DOMAIN),   'skills' => ['js-frontend-dev']],
            'ui-ux-designer' => ['name' => $this->translator->trans('fixtures.role.ui_ux_designer.name', [], self::TRANSLATION_DOMAIN), 'desc' => $this->translator->trans('fixtures.role.ui_ux_designer.description', [], self::TRANSLATION_DOMAIN), 'skills' => ['ui-design']],
            'tester'         => ['name' => $this->translator->trans('fixtures.role.tester.name', [], self::TRANSLATION_DOMAIN),         'desc' => $this->translator->trans('fixtures.role.tester.description', [], self::TRANSLATION_DOMAIN),         'skills' => ['test-writing', 'bug-reporting']],
            'scrum-master'   => ['name' => $this->translator->trans('fixtures.role.scrum_master.name', [], self::TRANSLATION_DOMAIN),   'desc' => $this->translator->trans('fixtures.role.scrum_master.description', [], self::TRANSLATION_DOMAIN),   'skills' => []],
            'tech-writer'    => ['name' => $this->translator->trans('fixtures.role.tech_writer.name', [], self::TRANSLATION_DOMAIN),    'desc' => $this->translator->trans('fixtures.role.tech_writer.description', [], self::TRANSLATION_DOMAIN),    'skills' => ['documentation-writing']],
            'devops'         => ['name' => $this->translator->trans('fixtures.role.devops.name', [], self::TRANSLATION_DOMAIN),         'desc' => $this->translator->trans('fixtures.role.devops.description', [], self::TRANSLATION_DOMAIN),         'skills' => ['ci-cd-setup']],
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
            ['key' => 'product.specify',        'label' => 'Product specification',         'role' => 'product-owner',  'skill' => 'product-owner', 'effects' => ['log_agent_response', 'ask_clarification', 'complete_current_task', 'rewrite_ticket', 'complete_ticket']],
            ['key' => 'tech.plan',             'label' => 'Technical planning',           'role' => 'lead-tech',      'skill' => 'tech-planning',  'effects' => [...$baseEffects, 'replace_planning_tasks', 'create_subtasks', 'prepare_branch', 'update_ticket_progress']],
            ['key' => 'design.ui_mockup',       'label' => 'UI mockup',                    'role' => 'ui-ux-designer', 'skill' => 'ui-design',      'effects' => $baseEffects],
            ['key' => 'dev.backend.implement',  'label' => 'Backend implementation',       'role' => 'php-dev',        'skill' => 'php-backend-dev', 'effects' => $baseEffects],
            ['key' => 'dev.frontend.implement', 'label' => 'Frontend implementation',      'role' => 'frontend-dev',   'skill' => 'js-frontend-dev', 'effects' => $baseEffects],
            ['key' => 'review.code',           'label' => 'Code review',                  'role' => 'lead-tech',      'skill' => 'code-reviewer',  'effects' => $baseEffects],
            ['key' => 'qa.validate',           'label' => 'QA validation',                'role' => 'tester',         'skill' => 'test-writing',   'effects' => $baseEffects],
            ['key' => 'docs.write',            'label' => 'Documentation writing',        'role' => 'tech-writer',    'skill' => 'documentation-writing', 'effects' => $baseEffects],
            ['key' => 'ops.configure',         'label' => 'Infrastructure configuration', 'role' => 'devops',         'skill' => 'ci-cd-setup',    'effects' => $baseEffects],
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
            'po-alice'    => ['name' => $this->translator->trans('fixtures.agent.po_alice.name', [], self::TRANSLATION_DOMAIN),    'role' => 'product-owner'],
            'lt-bob'      => ['name' => $this->translator->trans('fixtures.agent.lt_bob.name', [], self::TRANSLATION_DOMAIN),      'role' => 'lead-tech'],
            'php-charlie' => ['name' => $this->translator->trans('fixtures.agent.php_charlie.name', [], self::TRANSLATION_DOMAIN), 'role' => 'php-dev'],
            'front-diana' => ['name' => $this->translator->trans('fixtures.agent.front_diana.name', [], self::TRANSLATION_DOMAIN), 'role' => 'frontend-dev'],
            'design-eve'  => ['name' => $this->translator->trans('fixtures.agent.design_eve.name', [], self::TRANSLATION_DOMAIN),  'role' => 'ui-ux-designer'],
            'qa-frank'    => ['name' => $this->translator->trans('fixtures.agent.qa_frank.name', [], self::TRANSLATION_DOMAIN),    'role' => 'tester'],
            'sm-grace'    => ['name' => $this->translator->trans('fixtures.agent.sm_grace.name', [], self::TRANSLATION_DOMAIN),    'role' => 'scrum-master'],
            'doc-henry'   => ['name' => $this->translator->trans('fixtures.agent.doc_henry.name', [], self::TRANSLATION_DOMAIN),   'role' => 'tech-writer'],
            'ops-iris'    => ['name' => $this->translator->trans('fixtures.agent.ops_iris.name', [], self::TRANSLATION_DOMAIN),    'role' => 'devops'],
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

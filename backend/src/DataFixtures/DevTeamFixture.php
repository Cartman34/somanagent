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
use App\ValueObject\AgentConfig;
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
    private const FIXTURE_LABEL_KEYS = [
        'role' => [
            'product_owner_name' => 'fixtures.role.product_owner.name',
            'product_owner_description' => 'fixtures.role.product_owner.description',
            'lead_tech_name' => 'fixtures.role.lead_tech.name',
            'lead_tech_description' => 'fixtures.role.lead_tech.description',
            'php_dev_name' => 'fixtures.role.php_dev.name',
            'php_dev_description' => 'fixtures.role.php_dev.description',
            'frontend_dev_name' => 'fixtures.role.frontend_dev.name',
            'frontend_dev_description' => 'fixtures.role.frontend_dev.description',
            'ui_ux_designer_name' => 'fixtures.role.ui_ux_designer.name',
            'ui_ux_designer_description' => 'fixtures.role.ui_ux_designer.description',
            'tester_name' => 'fixtures.role.tester.name',
            'tester_description' => 'fixtures.role.tester.description',
            'scrum_master_name' => 'fixtures.role.scrum_master.name',
            'scrum_master_description' => 'fixtures.role.scrum_master.description',
            'tech_writer_name' => 'fixtures.role.tech_writer.name',
            'tech_writer_description' => 'fixtures.role.tech_writer.description',
            'devops_name' => 'fixtures.role.devops.name',
            'devops_description' => 'fixtures.role.devops.description',
        ],
        'agent' => [
            'po_alice_name' => 'fixtures.agent.po_alice.name',
            'lt_bob_name' => 'fixtures.agent.lt_bob.name',
            'php_charlie_name' => 'fixtures.agent.php_charlie.name',
            'front_diana_name' => 'fixtures.agent.front_diana.name',
            'design_eve_name' => 'fixtures.agent.design_eve.name',
            'qa_frank_name' => 'fixtures.agent.qa_frank.name',
            'sm_grace_name' => 'fixtures.agent.sm_grace.name',
            'doc_henry_name' => 'fixtures.agent.doc_henry.name',
            'ops_iris_name' => 'fixtures.agent.ops_iris.name',
        ],
        'team' => [
            'web_dev_name' => 'fixtures.team.web_dev.name',
            'web_dev_description' => 'fixtures.team.web_dev.description',
        ],
        'workflow' => [
            'standard_name' => 'fixtures.workflow.standard.name',
            'standard_description' => 'fixtures.workflow.standard.description',
            'standard_step_new_name' => 'fixtures.workflow.standard.step.new.name',
            'standard_step_ready_name' => 'fixtures.workflow.standard.step.ready.name',
            'standard_step_planning_name' => 'fixtures.workflow.standard.step.planning.name',
            'standard_step_graphic_design_name' => 'fixtures.workflow.standard.step.graphic_design.name',
            'standard_step_development_name' => 'fixtures.workflow.standard.step.development.name',
            'standard_step_code_review_name' => 'fixtures.workflow.standard.step.code_review.name',
            'standard_step_done_name' => 'fixtures.workflow.standard.step.done.name',
        ],
    ];

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

    /** @return array<string, Skill> */
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

    /** @return array<string, Role> */
    private function createRoles(ObjectManager $manager, array $skills): array
    {
        $definitions = [
            'product-owner'  => ['name' => $this->tr('fixtures.role.product_owner.name'),  'desc' => $this->tr('fixtures.role.product_owner.description'),  'skills' => ['product-owner', 'bug-reporting']],
            'lead-tech'      => ['name' => $this->tr('fixtures.role.lead_tech.name'),      'desc' => $this->tr('fixtures.role.lead_tech.description'),      'skills' => ['tech-planning', 'spec-writer', 'code-reviewer']],
            'php-dev'        => ['name' => $this->tr('fixtures.role.php_dev.name'),        'desc' => $this->tr('fixtures.role.php_dev.description'),        'skills' => ['php-backend-dev']],
            'frontend-dev'   => ['name' => $this->tr('fixtures.role.frontend_dev.name'),   'desc' => $this->tr('fixtures.role.frontend_dev.description'),   'skills' => ['js-frontend-dev']],
            'ui-ux-designer' => ['name' => $this->tr('fixtures.role.ui_ux_designer.name'), 'desc' => $this->tr('fixtures.role.ui_ux_designer.description'), 'skills' => ['ui-design']],
            'tester'         => ['name' => $this->tr('fixtures.role.tester.name'),         'desc' => $this->tr('fixtures.role.tester.description'),         'skills' => ['test-writing', 'bug-reporting']],
            'scrum-master'   => ['name' => $this->tr('fixtures.role.scrum_master.name'),   'desc' => $this->tr('fixtures.role.scrum_master.description'),   'skills' => []],
            'tech-writer'    => ['name' => $this->tr('fixtures.role.tech_writer.name'),    'desc' => $this->tr('fixtures.role.tech_writer.description'),    'skills' => ['documentation-writing']],
            'devops'         => ['name' => $this->tr('fixtures.role.devops.name'),         'desc' => $this->tr('fixtures.role.devops.description'),         'skills' => ['ci-cd-setup']],
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
     */
    private function createAgentActions(ObjectManager $manager, array $roles, array $skills): array
    {
        $definitions = [
            ['key' => 'product.specify', 'label' => 'Product specification', 'role' => 'product-owner', 'skill' => 'product-owner'],
            ['key' => 'tech.plan', 'label' => 'Technical planning', 'role' => 'lead-tech', 'skill' => 'tech-planning'],
            ['key' => 'design.ui_mockup', 'label' => 'UI mockup', 'role' => 'ui-ux-designer', 'skill' => 'ui-design'],
            ['key' => 'dev.backend.implement', 'label' => 'Backend implementation', 'role' => 'php-dev', 'skill' => 'php-backend-dev'],
            ['key' => 'dev.frontend.implement', 'label' => 'Frontend implementation', 'role' => 'frontend-dev', 'skill' => 'js-frontend-dev'],
            ['key' => 'review.code', 'label' => 'Code review', 'role' => 'lead-tech', 'skill' => 'code-reviewer'],
            ['key' => 'qa.validate', 'label' => 'QA validation', 'role' => 'tester', 'skill' => 'test-writing'],
            ['key' => 'docs.write', 'label' => 'Documentation writing', 'role' => 'tech-writer', 'skill' => 'documentation-writing'],
            ['key' => 'ops.configure', 'label' => 'Infrastructure configuration', 'role' => 'devops', 'skill' => 'ci-cd-setup'],
        ];

        $actions = [];
        foreach ($definitions as $definition) {
            $action = new AgentAction($definition['key'], $definition['label']);
            $action->setRole($roles[$definition['role']] ?? null);
            $action->setSkill($skills[$definition['skill']] ?? null);
            $manager->persist($action);
            $actions[$definition['key']] = $action;
        }

        return $actions;
    }

    /** @return array<string, Agent> */
    private function createAgents(ObjectManager $manager, array $roles): array
    {
        $config = AgentConfig::default();

        $definitions = [
            'po-alice'    => ['name' => $this->tr('fixtures.agent.po_alice.name'),    'role' => 'product-owner'],
            'lt-bob'      => ['name' => $this->tr('fixtures.agent.lt_bob.name'),      'role' => 'lead-tech'],
            'php-charlie' => ['name' => $this->tr('fixtures.agent.php_charlie.name'), 'role' => 'php-dev'],
            'front-diana' => ['name' => $this->tr('fixtures.agent.front_diana.name'), 'role' => 'frontend-dev'],
            'design-eve'  => ['name' => $this->tr('fixtures.agent.design_eve.name'),  'role' => 'ui-ux-designer'],
            'qa-frank'    => ['name' => $this->tr('fixtures.agent.qa_frank.name'),    'role' => 'tester'],
            'sm-grace'    => ['name' => $this->tr('fixtures.agent.sm_grace.name'),    'role' => 'scrum-master'],
            'doc-henry'   => ['name' => $this->tr('fixtures.agent.doc_henry.name'),   'role' => 'tech-writer'],
            'ops-iris'    => ['name' => $this->tr('fixtures.agent.ops_iris.name'),    'role' => 'devops'],
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

    private function createTeam(ObjectManager $manager, array $agents): Team
    {
        $team = new Team(
            $this->tr('fixtures.team.web_dev.name'),
            $this->tr('fixtures.team.web_dev.description'),
        );
        foreach ($agents as $agent) {
            $team->addAgent($agent);
        }
        $manager->persist($team);
        return $team;
    }

    private function createWorkflowTemplate(ObjectManager $manager, array $actions): Workflow
    {
        $workflow = new Workflow(
            $this->tr('fixtures.workflow.standard.name'),
            WorkflowTrigger::Manual,
            $this->tr('fixtures.workflow.standard.description'),
        );
        foreach ([
            [1, 'fixtures.workflow.standard.step.new.name',            'new',            WorkflowStepTransitionMode::Automatic, [['product.specify', true]]],
            [2, 'fixtures.workflow.standard.step.ready.name',          'ready',          WorkflowStepTransitionMode::Manual,    []],
            [3, 'fixtures.workflow.standard.step.planning.name',       'planning',       WorkflowStepTransitionMode::Automatic, [['tech.plan', true]]],
            [4, 'fixtures.workflow.standard.step.graphic_design.name', 'graphic_design', WorkflowStepTransitionMode::Automatic, [['design.ui_mockup', false]]],
            [5, 'fixtures.workflow.standard.step.development.name',    'development',    WorkflowStepTransitionMode::Automatic, [['dev.backend.implement', false], ['dev.frontend.implement', false]]],
            [6, 'fixtures.workflow.standard.step.code_review.name',    'code_review',    WorkflowStepTransitionMode::Automatic, [['review.code', false]]],
            [7, 'fixtures.workflow.standard.step.done.name',           'done',           WorkflowStepTransitionMode::Manual,    []],
        ] as [$order, $nameKey, $key, $transitionMode, $stepActions]) {
            $step = new WorkflowStep($workflow, $order, $this->tr($nameKey), $key);
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

    private function tr(string $key): string
    {
        return $this->translator->trans($key, [], self::TRANSLATION_DOMAIN);
    }
}

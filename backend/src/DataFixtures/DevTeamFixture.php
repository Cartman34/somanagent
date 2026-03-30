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

/**
 * Charge une équipe de développement web complète avec rôles, skills, agents et un workflow template.
 *
 * Exécution : php bin/console doctrine:fixtures:load
 */
class DevTeamFixture extends Fixture
{
    private const SKILLS_BASE_PATH = '/var/www/skills/custom';

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
            'product-owner'  => ['name' => 'Product Owner',      'desc' => 'Rédige et complète les user stories.',                          'skills' => ['product-owner', 'bug-reporting']],
            'lead-tech'      => ['name' => 'Lead Tech',           'desc' => 'Planifie, découpe, assigne et revoit le code.',                 'skills' => ['tech-planning', 'spec-writer', 'code-reviewer']],
            'php-dev'        => ['name' => 'Développeur PHP',     'desc' => 'Développement backend PHP 8.4 / Symfony 7.',                   'skills' => ['php-backend-dev']],
            'frontend-dev'   => ['name' => 'Développeur Frontend','desc' => 'Développement frontend React / TypeScript.',                   'skills' => ['js-frontend-dev']],
            'ui-ux-designer' => ['name' => 'Designer UX/UI',      'desc' => 'Conception graphique, maquettes, charte de style.',            'skills' => ['ui-design']],
            'tester'         => ['name' => 'Testeur QA',          'desc' => 'Tests fonctionnels et rapports d\'anomalies.',                 'skills' => ['test-writing', 'bug-reporting']],
            'scrum-master'   => ['name' => 'Scrum Master',        'desc' => 'Facilitation Agile et levée des blocages.',                    'skills' => []],
            'tech-writer'    => ['name' => 'Tech Writer',         'desc' => 'Documentation fonctionnelle et technique.',                    'skills' => ['documentation-writing']],
            'devops'         => ['name' => 'DevOps',              'desc' => 'Infrastructure, CI/CD, Docker, déploiement.',                  'skills' => ['ci-cd-setup']],
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
            'po-alice'    => ['name' => 'Alice (PO)',           'role' => 'product-owner'],
            'lt-bob'      => ['name' => 'Bob (Lead Tech)',       'role' => 'lead-tech'],
            'php-charlie' => ['name' => 'Charlie (PHP Dev)',     'role' => 'php-dev'],
            'front-diana' => ['name' => 'Diana (Frontend Dev)',  'role' => 'frontend-dev'],
            'design-eve'  => ['name' => 'Eve (Designer)',        'role' => 'ui-ux-designer'],
            'qa-frank'    => ['name' => 'Frank (QA)',            'role' => 'tester'],
            'sm-grace'    => ['name' => 'Grace (Scrum Master)',  'role' => 'scrum-master'],
            'doc-henry'   => ['name' => 'Henry (Tech Writer)',   'role' => 'tech-writer'],
            'ops-iris'    => ['name' => 'Iris (DevOps)',         'role' => 'devops'],
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
        $team = new Team('Web Dev Team', 'Équipe de développement web complète.');
        foreach ($agents as $agent) {
            $team->addAgent($agent);
        }
        $manager->persist($team);
        return $team;
    }

    private function createWorkflowTemplate(ObjectManager $manager, array $actions): Workflow
    {
        $workflow = new Workflow(
            'Développement web standard',
            WorkflowTrigger::Manual,
            'Workflow de référence pour une équipe web: cadrage PO, préparation, planification, design, développement, revue, terminaison.',
        );
        foreach ([
            [1, 'Nouvelle',             'new',            WorkflowStepTransitionMode::Automatic, [['product.specify', true]]],
            [2, 'Prête',                'ready',          WorkflowStepTransitionMode::Manual,    []],
            [3, 'Planification',        'planning',       WorkflowStepTransitionMode::Automatic, [['tech.plan', false]]],
            [4, 'Conception graphique', 'graphic_design', WorkflowStepTransitionMode::Automatic, [['design.ui_mockup', false]]],
            [5, 'Développement',        'development',    WorkflowStepTransitionMode::Automatic, [['dev.backend.implement', false], ['dev.frontend.implement', false]]],
            [6, 'Revue',                'code_review',    WorkflowStepTransitionMode::Automatic, [['review.code', false]]],
            [7, 'Terminée',             'done',           WorkflowStepTransitionMode::Manual,    []],
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

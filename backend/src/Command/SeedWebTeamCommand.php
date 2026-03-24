<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TeamService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'somanagent:seed:web-team', description: 'Crée l\'équipe Web Development Team d\'exemple')]
class SeedWebTeamCommand extends Command
{
    private const ROLES = [
        ['name' => 'Tech Lead',              'description' => 'Architecture et décisions techniques',   'skillSlug' => 'architect'],
        ['name' => 'Développeur Backend',    'description' => 'Code serveur, API',                      'skillSlug' => 'backend-dev'],
        ['name' => 'Développeur Frontend',   'description' => 'UI, intégration',                        'skillSlug' => 'frontend-dev'],
        ['name' => 'Reviewer',               'description' => 'Revue de code, qualité',                 'skillSlug' => 'code-reviewer'],
        ['name' => 'QA',                     'description' => 'Tests, validation',                      'skillSlug' => 'qa-tester'],
        ['name' => 'DevOps',                 'description' => 'CI/CD, infrastructure',                  'skillSlug' => 'devops'],
    ];

    public function __construct(private readonly TeamService $teamService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SoManAgent — Seed Web Development Team');

        $team = $this->teamService->create('Web Development Team', 'Équipe de développement web full-stack — équipe d\'exemple SoManAgent');
        $io->text('Équipe créée : <info>' . $team->getName() . '</info>');

        foreach (self::ROLES as $roleData) {
            $role = $this->teamService->addRole($team, $roleData['name'], $roleData['description'], $roleData['skillSlug']);
            $io->text('  + Rôle : <comment>' . $role->getName() . '</comment> (skill: ' . ($role->getSkillSlug() ?? '—') . ')');
        }

        $io->success(sprintf('Équipe "%s" créée avec %d rôles.', $team->getName(), count(self::ROLES)));
        return Command::SUCCESS;
    }
}

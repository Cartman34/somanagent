<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Agent;
use App\Entity\Role;
use App\Entity\Team;
use App\Enum\ConnectorType;
use App\ValueObject\AgentConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'somanagent:seed:web-team',
    description: 'Crée les rôles et l\'équipe de développement web par défaut.',
)]
class SeedWebTeamCommand extends Command
{
    private const ROLES = [
        ['slug' => 'product-owner',   'name' => 'Product Owner',           'description' => 'Définit les US, priorise le backlog, accepte les livrables.'],
        ['slug' => 'scrum-master',    'name' => 'Scrum Master',             'description' => 'Facilite les cérémonies agile, lève les obstacles.'],
        ['slug' => 'lead-tech',       'name' => 'Lead Tech',                'description' => 'Architecture technique, revue de code, mentoring.'],
        ['slug' => 'dev-php',         'name' => 'Développeur PHP',          'description' => 'Développement backend PHP / Symfony.'],
        ['slug' => 'dev-frontend',    'name' => 'Développeur Frontend',     'description' => 'Développement React / TypeScript / CSS.'],
        ['slug' => 'qa-tester',       'name' => 'Testeur QA',               'description' => 'Tests fonctionnels, rédaction d\'anomalies, recette.'],
        ['slug' => 'ui-ux-designer',  'name' => 'Designer UI/UX',           'description' => 'Maquettes, design system, expérience utilisateur.'],
        ['slug' => 'tech-writer',     'name' => 'Documentaliste Technique', 'description' => 'Rédaction documentation technique et fonctionnelle.'],
        ['slug' => 'devops',          'name' => 'DevOps',                   'description' => 'CI/CD, infrastructure, monitoring, déploiements.'],
    ];

    private const AGENTS = [
        ['name' => 'PO — Alice',       'slug' => 'product-owner',  'description' => 'Agent Product Owner. Rédige les US, priorise le backlog.'],
        ['name' => 'Scrum — Bob',      'slug' => 'scrum-master',   'description' => 'Agent Scrum Master. Facilite et synchronise l\'équipe.'],
        ['name' => 'Lead — Clara',     'slug' => 'lead-tech',      'description' => 'Agent Lead Tech. Valide l\'architecture et fait les revues de code.'],
        ['name' => 'PHP — David',      'slug' => 'dev-php',        'description' => 'Agent Dev PHP. Implémente les fonctionnalités backend.'],
        ['name' => 'Front — Emma',     'slug' => 'dev-frontend',   'description' => 'Agent Dev Frontend. Implémente les interfaces React.'],
        ['name' => 'QA — Félix',       'slug' => 'qa-tester',      'description' => 'Agent Testeur QA. Rédige et exécute les tests, remonte les anomalies.'],
        ['name' => 'Design — Grace',   'slug' => 'ui-ux-designer', 'description' => 'Agent Designer UI/UX. Produit les maquettes et le design system.'],
        ['name' => 'Doc — Hugo',       'slug' => 'tech-writer',    'description' => 'Agent Documentaliste. Rédige la documentation technique.'],
        ['name' => 'DevOps — Iris',    'slug' => 'devops',         'description' => 'Agent DevOps. Gère l\'infrastructure et les déploiements.'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Recréer même si des rôles existent déjà.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $existing = $this->em->getRepository(Role::class)->findAll();
        if (count($existing) > 0 && !$force) {
            $io->warning('Des rôles existent déjà. Utilisez --force pour recréer.');
            return Command::SUCCESS;
        }

        $io->title('SoManAgent — Seed équipe de développement web');

        // Rôles
        $roles = [];
        foreach (self::ROLES as $def) {
            $role = new Role($def['slug'], $def['name'], $def['description']);
            $this->em->persist($role);
            $roles[$def['slug']] = $role;
            $io->text("  Rôle : <info>{$def['name']}</info>");
        }

        // Équipe
        $team = new Team('Équipe Web', 'Équipe de développement web full-stack.');
        $this->em->persist($team);
        $io->text('  Équipe : <info>Équipe Web</info>');

        // Agents
        $config = AgentConfig::fromArray(['model' => 'claude-sonnet-4-6']);
        foreach (self::AGENTS as $def) {
            $agent = new Agent($def['name'], ConnectorType::ClaudeApi, $config, $def['description']);
            $agent->setRole($roles[$def['slug']]);
            $this->em->persist($agent);
            $team->addAgent($agent);
            $io->text("  Agent : <comment>{$def['name']}</comment> [{$def['slug']}]");
        }

        $this->em->flush();

        $io->success(sprintf('%d rôles, %d agents et l\'équipe "%s" créés.', count(self::ROLES), count(self::AGENTS), $team->getName()));
        return Command::SUCCESS;
    }
}

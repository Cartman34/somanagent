<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AgentPortRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'somanagent:health', description: 'Vérifie l\'état des connecteurs IA et VCS')]
class HealthCheckCommand extends Command
{
    public function __construct(private readonly AgentPortRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $io->title('SoManAgent — Vérification des connecteurs');

        $results = $this->registry->healthCheckAll();
        $allOk   = true;

        foreach ($results as $connector => $ok) {
            if ($ok) {
                $io->writeln("  <info>✓</info> {$connector}");
            } else {
                $io->writeln("  <error>✗</error> {$connector}");
                $allOk = false;
            }
        }

        if ($allOk) {
            $io->success('Tous les connecteurs sont opérationnels.');
            return Command::SUCCESS;
        }

        $io->warning('Certains connecteurs sont inaccessibles.');
        return Command::FAILURE;
    }
}

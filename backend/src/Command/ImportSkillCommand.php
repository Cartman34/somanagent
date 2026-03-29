<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SkillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'somanagent:skill:import', description: 'Imports a skill from skills.sh (for example: owner/skill-name)')]
class ImportSkillCommand extends Command
{
    public function __construct(private readonly SkillService $skillService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'Skill source (for example: anthropics/code-reviewer)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $source = $input->getArgument('source');

        $io->text("Importing skill <info>{$source}</info>...");

        $skill = $this->skillService->importFromRegistry($source);

        $io->success(sprintf('Skill "%s" (slug: %s) imported successfully.', $skill->getName(), $skill->getSlug()));
        return Command::SUCCESS;
    }
}

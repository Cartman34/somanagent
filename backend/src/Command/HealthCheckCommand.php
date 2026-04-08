<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\ConnectorRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to check the health of AI and VCS connectors.
 */
#[AsCommand(name: 'somanagent:health', description: 'Checks the health of AI and VCS connectors')]
class HealthCheckCommand extends Command
{
    /**
     * Initializes the command with the connector registry.
     */
    public function __construct(private readonly ConnectorRegistry $registry)
    {
        parent::__construct();
    }

    /**
     * Declares the --no-prompt-test option used to skip real prompt execution during the health run.
     */
    protected function configure(): void
    {
        $this->addOption('no-prompt-test', null, InputOption::VALUE_NONE, 'Skip real prompt execution and use the cached result instead');
    }

    /**
     * Prints the health status of every registered connector with runtime, auth, and failure details.
     *
     * By default, a real prompt is sent per connector (deep check). Pass --no-prompt-test to skip.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $deep = !$input->getOption('no-prompt-test');
        $io->title('SoManAgent — Connector Health' . ($deep ? '' : ' (prompt test skipped)'));

        $descriptors = $this->registry->describeAll($deep);
        $allOk       = true;

        foreach ($descriptors as $descriptor) {
            $ok = $descriptor->isOverallHealthy();
            $allOk = $allOk && $ok;

            $io->section(sprintf('%s (%s)', $descriptor->connector->label(), $descriptor->connector->value));
            $io->table(
                ['Check', 'Status', 'Summary', 'Fix'],
                array_map(
                    static fn ($check): array => [
                        $check->name,
                        $check->status === 'ok' ? '<info>ok</info>' : ($check->status === 'skipped' ? '<comment>skipped</comment>' : '<error>degraded</error>'),
                        $check->summary ?? '',
                        $check->fixCommand ?? '',
                    ],
                    $descriptor->health->checks->all(),
                ),
            );
        }

        if ($allOk) {
            $io->success('All connectors are operational.');
            return Command::SUCCESS;
        }

        $io->warning('Some connectors are unreachable.');
        return Command::FAILURE;
    }
}

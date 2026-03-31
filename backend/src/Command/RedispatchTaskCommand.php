<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\Agent;
use App\Enum\TaskExecutionTrigger;
use App\Repository\AgentRepository;
use App\Repository\TicketTaskRepository;
use App\Service\TicketTaskService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'somanagent:task:redispatch',
    description: 'Redispatches an existing ticket execution when a Messenger message was lost.',
)]
final class RedispatchTaskCommand extends Command
{
    public function __construct(
        private readonly TicketTaskService $ticketTaskService,
        private readonly AgentRepository $agentRepository,
        private readonly TicketTaskRepository $ticketTaskRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::OPTIONAL, 'ID of the task to redispatch')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Searches for a task by title')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Targets the most recent task')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Forces a specific agent ID')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Deprecated. The refactored model only supports redispatch through Messenger.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ((bool) $input->getOption('sync')) {
            $io->error('The --sync flag is no longer supported on the Task model. Use async redispatch instead.');
            return Command::FAILURE;
        }

        $taskId = $input->getArgument('task-id');
        $title = $input->getOption('title');
        $latest = (bool) $input->getOption('latest');
        $agentId = $input->getOption('agent');

        $task = $this->resolveTask(
            is_string($taskId) && $taskId !== '' ? $taskId : null,
            is_string($title) && $title !== '' ? $title : null,
            $latest,
            $io,
        );
        if ($task === null) {
            return Command::FAILURE;
        }

        if ($task->getAgentAction()->getSkill()?->getSlug() === null) {
            $io->error(sprintf('Task "%s" cannot be redispatched because its action has no executable skill.', $task->getTitle()));
            return Command::FAILURE;
        }

        $agent = null;
        if (is_string($agentId) && $agentId !== '') {
            $agent = $this->agentRepository->find(Uuid::fromString($agentId));
            if ($agent === null) {
                $io->error('Agent not found.');
                return Command::FAILURE;
            }
        }

        try {
            $result = $this->ticketTaskService->execute($task, $agent, TaskExecutionTrigger::Redispatch);
        } catch (\RuntimeException|\LogicException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Task redispatched with %s (%s).',
            $result['agent']->getName(),
            $result['skill'],
        ));

        return Command::SUCCESS;
    }

    private function resolveTask(?string $taskId, ?string $title, bool $latest, SymfonyStyle $io): ?\App\Entity\TicketTask
    {
        $modeCount = (int) ($taskId !== null) + (int) ($title !== null) + (int) $latest;
        if ($modeCount !== 1) {
            $io->error('Use exactly one selector: <task-id>, --title, or --latest.');
            return null;
        }

        if ($taskId !== null) {
            $task = $this->ticketTaskService->findById($taskId);
            if ($task === null) {
                $io->error('Task not found.');
            }
            return $task;
        }

        if ($latest) {
            $tasks = $this->ticketTaskRepository->findRecent(1);
            if ($tasks === []) {
                $io->error('No recent task found.');
                return null;
            }

            $task = $tasks[0];
            $io->note(sprintf('Selected task: "%s" (%s)', $task->getTitle(), (string) $task->getId()));
            return $task;
        }

        $matches = $this->ticketTaskRepository->findByTitleLike($title ?? '', 5);
        if ($matches === []) {
            $io->error('No matching task found.');
            return null;
        }

        if (count($matches) > 1) {
            $io->error('Multiple tasks match. Refine the title or use the ID.');
            foreach ($matches as $match) {
                $io->text(sprintf(
                    '- %s | %s | %s',
                    (string) $match->getId(),
                    $match->getCreatedAt()->format('Y-m-d H:i'),
                    $match->getTitle(),
                ));
            }
            return null;
        }

        $task = $matches[0];
        $io->note(sprintf('Selected task: "%s" (%s)', $task->getTitle(), (string) $task->getId()));
        return $task;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TaskExecutionTrigger;
use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskLogRepository;
use App\Service\AgentExecutionService;
use App\Service\LogService;
use App\Service\RequestCorrelationService;
use App\Service\StoryExecutionService;
use App\Service\TaskExecutionService;
use App\Service\TaskService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'somanagent:task:redispatch',
    description: 'Redispatches an existing story execution, useful when a Messenger message was lost.',
)]
final class RedispatchTaskCommand extends Command
{
    public function __construct(
        private readonly TaskService            $taskService,
        private readonly StoryExecutionService  $storyExecutionService,
        private readonly AgentExecutionService  $agentExecutionService,
        private readonly RequestCorrelationService $requestCorrelation,
        private readonly LogService             $logService,
        private readonly TaskExecutionService   $taskExecutionService,
        private readonly AgentRepository        $agentRepository,
        private readonly TaskRepository         $taskRepository,
        private readonly TaskLogRepository      $taskLogRepository,
        private readonly MessageBusInterface    $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::OPTIONAL, 'ID of the task to redispatch')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Searches for a story by title')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Targets the most recent story')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Forces a specific agent ID')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Executes immediately instead of dispatching through Messenger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $taskId  = $input->getArgument('task-id');
        $title   = $input->getOption('title');
        $latest  = (bool) $input->getOption('latest');
        $agentId = $input->getOption('agent');
        $sync    = (bool) $input->getOption('sync');

        $task = $this->resolveTask(
            is_string($taskId) && $taskId !== '' ? $taskId : null,
            is_string($title) && $title !== '' ? $title : null,
            $latest,
            $io,
        );
        if ($task === null) return Command::FAILURE;

        if (!$task->isStory()) {
            $io->error('Only user stories and bugs can be redispatched.');
            return Command::FAILURE;
        }

        $skillSlug = $this->resolveSkillSlug($task);
        if ($skillSlug === null) {
            $io->error('Unable to determine which skill should be redispatched for this task.');
            return Command::FAILURE;
        }

        $agent = null;
        if (is_string($agentId) && $agentId !== '') {
            $agent = $this->agentRepository->find(Uuid::fromString($agentId));
            if ($agent === null) {
                $io->error('Agent not found.');
                return Command::FAILURE;
            }
        } else {
            $agent = $this->resolveAgentForSkill($skillSlug, $task);
            if ($agent === null) {
                $io->error(sprintf('No active agent found for skill "%s".', $skillSlug));
                return Command::FAILURE;
            }
        }

        if ($sync) {
            $requestRef = $this->requestCorrelation->getCurrentRequestRef() ?? Uuid::v7()->toRfc4122();
            $traceRef = Uuid::v7()->toRfc4122();
            $execution = $this->taskExecutionService->createExecution(
                task: $task,
                requestedAgent: $agent,
                skillSlug: $skillSlug,
                triggerType: TaskExecutionTrigger::Redispatch,
                requestRef: $requestRef,
                traceRef: $traceRef,
                workflowStepKey: $task->getStoryStatus()?->value,
                maxAttempts: 1,
            );

            $this->taskExecutionService->logDispatch(
                execution: $execution,
                action: 'execution_redispatched_sync',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                content: sprintf('Relance synchrone par commande avec %s (%s)', $agent->getName(), $skillSlug),
            );

            $attempt = $this->taskExecutionService->startAttempt(
                execution: $execution,
                attemptNumber: 1,
                agent: $agent,
                requestRef: $requestRef,
                messengerReceiver: 'sync',
            );

            try {
                $this->logService->record(
                    source: 'backend',
                    category: 'runtime',
                    level: 'info',
                    title: '',
                    message: '',
                    options: [
                        'title_i18n' => [
                            'domain' => 'logs',
                            'key' => 'logs.backend.runtime.task_redispatched_sync.title',
                        ],
                        'message_i18n' => [
                            'domain' => 'logs',
                            'key' => 'logs.backend.runtime.task_redispatched_sync.message',
                            'parameters' => [
                                '%taskTitle%' => $task->getTitle(),
                                '%agentName%' => $agent->getName(),
                                '%skillSlug%' => $skillSlug,
                            ],
                        ],
                        'project_id' => (string) $task->getProject()->getId(),
                        'task_id' => (string) $task->getId(),
                        'agent_id' => (string) $agent->getId(),
                        'request_ref' => $requestRef,
                        'trace_ref' => $traceRef,
                        'context' => [
                            'task_execution_id' => (string) $execution->getId(),
                            'entry_point' => 'cli',
                            'redispatch_mode' => 'sync',
                            'skill_slug' => $skillSlug,
                        ],
                    ],
                );

                $this->agentExecutionService->execute($task, $agent, $skillSlug);
                $this->taskExecutionService->markSucceeded($execution, $attempt);
            } catch (\Throwable $e) {
                $this->taskExecutionService->markFailed($execution, $attempt, $e->getMessage(), false, 'execution');
                $this->taskService->failExecution($task, $e->getMessage());
                $io->error(sprintf(
                    'Synchronous redispatch failed with %s (%s): %s',
                    $agent->getName(),
                    $skillSlug,
                    $e->getMessage(),
                ));

                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Task redispatched synchronously with %s (%s).',
                $agent->getName(),
                $skillSlug,
            ));

            return Command::SUCCESS;
        }

        $requestRef = $this->requestCorrelation->getCurrentRequestRef() ?? Uuid::v7()->toRfc4122();
        $traceRef = Uuid::v7()->toRfc4122();
        $execution = $this->taskExecutionService->createExecution(
            task: $task,
            requestedAgent: $agent,
            skillSlug: $skillSlug,
            triggerType: TaskExecutionTrigger::Redispatch,
            requestRef: $requestRef,
            traceRef: $traceRef,
            workflowStepKey: $task->getStoryStatus()?->value,
        );
        $this->taskExecutionService->logDispatch(
            execution: $execution,
            action: 'execution_redispatched',
            // Stored in DB for the in-app log UI, so the human-facing message stays in French.
            content: sprintf('Relance en file par commande avec %s (%s)', $agent->getName(), $skillSlug),
        );

        $this->bus->dispatch(new AgentTaskMessage(
            taskId: (string) $task->getId(),
            agentId: (string) $agent->getId(),
            skillSlug: $skillSlug,
            taskExecutionId: (string) $execution->getId(),
            requestRef: $requestRef,
            traceRef: $traceRef,
        ));

        $this->logService->record(
            source: 'backend',
            category: 'runtime',
            level: 'info',
            title: '',
            message: '',
            options: [
                'title_i18n' => [
                    'domain' => 'logs',
                    'key' => 'logs.backend.runtime.task_redispatched.title',
                ],
                'message_i18n' => [
                    'domain' => 'logs',
                    'key' => 'logs.backend.runtime.task_redispatched.message',
                    'parameters' => [
                        '%taskTitle%' => $task->getTitle(),
                        '%agentName%' => $agent->getName(),
                        '%skillSlug%' => $skillSlug,
                    ],
                ],
                'project_id' => (string) $task->getProject()->getId(),
                'task_id' => (string) $task->getId(),
                'agent_id' => (string) $agent->getId(),
                'request_ref' => $requestRef,
                'trace_ref' => $traceRef,
                'context' => [
                    'task_execution_id' => (string) $execution->getId(),
                    'entry_point' => 'cli',
                    'redispatch_mode' => 'async',
                    'skill_slug' => $skillSlug,
                ],
            ],
        );

        $io->success(sprintf(
            'Task requeued with %s (%s).',
            $agent->getName(),
            $skillSlug,
        ));

        return Command::SUCCESS;
    }

    private function resolveTask(?string $taskId, ?string $title, bool $latest, SymfonyStyle $io): ?\App\Entity\Task
    {
        $modeCount = (int) ($taskId !== null) + (int) ($title !== null) + (int) $latest;
        if ($modeCount !== 1) {
            $io->error('Use exactly one selector: <task-id>, --title, or --latest.');
            return null;
        }

        if ($taskId !== null) {
            $task = $this->taskService->findById($taskId);
            if ($task === null) {
                $io->error('Task not found.');
            }
            return $task;
        }

        if ($latest) {
            $tasks = $this->taskRepository->findRecentStories(1);
            if ($tasks === []) {
                $io->error('No recent story found.');
                return null;
            }

            $task = $tasks[0];
            $io->note(sprintf('Selected story: "%s" (%s)', $task->getTitle(), (string) $task->getId()));
            return $task;
        }

        $matches = $this->taskRepository->findStoriesByTitleLike($title ?? '', 5);
        if ($matches === []) {
            $io->error('No matching story found.');
            return null;
        }

        if (count($matches) > 1) {
            $io->error('Multiple stories match. Refine the title or use the ID.');
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
        $io->note(sprintf('Selected story: "%s" (%s)', $task->getTitle(), (string) $task->getId()));
        return $task;
    }

    private function resolveSkillSlug(\App\Entity\Task $task): ?string
    {
        $logs = array_reverse($this->taskLogRepository->findByTask($task));
        foreach ($logs as $log) {
            if (!in_array($log->getAction(), ['execution_dispatched', 'execution_redispatched', 'execution_redispatched_sync'], true)) {
                continue;
            }

            $content = $log->getContent() ?? '';
            if (preg_match('/\(([a-z0-9_-]+)\)$/i', $content, $matches) === 1) {
                return $matches[1];
            }

            if (preg_match('/skill ([a-z0-9_-]+)/i', $content, $matches) === 1) {
                return $matches[1];
            }
        }

        if ($this->storyExecutionService->canExecute($task)) {
            $config = $this->storyExecutionService->resolveExecutionConfig($task);
            return $config['skill'];
        }

        return null;
    }

    private function resolveAgentForSkill(string $skillSlug, \App\Entity\Task $task): ?\App\Entity\Agent
    {
        $team = $task->getProject()->getTeam();
        $agents = $team !== null
            ? $this->agentRepository->findActiveBySkillSlugAndTeam($skillSlug, $team)
            : $this->agentRepository->findActiveBySkillSlug($skillSlug);

        return $agents[0] ?? null;
    }
}

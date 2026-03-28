<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TaskLog;
use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskLogRepository;
use App\Service\AgentExecutionService;
use App\Service\LogService;
use App\Service\RequestCorrelationService;
use App\Service\StoryExecutionService;
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
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
    description: 'Relance l\'exécution d\'une story existante, utile si un message Messenger a été perdu.',
)]
final class RedispatchTaskCommand extends Command
{
    public function __construct(
        private readonly TaskService            $taskService,
        private readonly StoryExecutionService  $storyExecutionService,
        private readonly AgentExecutionService  $agentExecutionService,
        private readonly RequestCorrelationService $requestCorrelation,
        private readonly LogService             $logService,
        private readonly AgentRepository        $agentRepository,
        private readonly TaskRepository         $taskRepository,
        private readonly TaskLogRepository      $taskLogRepository,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::OPTIONAL, 'ID de la tâche à relancer')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Recherche une story par titre')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Cible la story la plus récente')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'ID d\'un agent à forcer')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Exécute immédiatement au lieu de passer par Messenger');
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
            $io->error('Seules les user stories et anomalies peuvent être redispatchées.');
            return Command::FAILURE;
        }

        $skillSlug = $this->resolveSkillSlug($task);
        if ($skillSlug === null) {
            $io->error('Impossible de déterminer le skill à relancer pour cette tâche.');
            return Command::FAILURE;
        }

        $agent = null;
        if (is_string($agentId) && $agentId !== '') {
            $agent = $this->agentRepository->find(Uuid::fromString($agentId));
            if ($agent === null) {
                $io->error('Agent introuvable.');
                return Command::FAILURE;
            }
        } else {
            $agent = $this->resolveAgentForSkill($skillSlug, $task);
            if ($agent === null) {
                $io->error(sprintf('Aucun agent actif trouvé pour le skill "%s".', $skillSlug));
                return Command::FAILURE;
            }
        }

        if ($sync) {
            $requestRef = $this->requestCorrelation->getCurrentRequestRef() ?? Uuid::v7()->toRfc4122();
            $traceRef = Uuid::v7()->toRfc4122();

            $this->em->persist(new TaskLog(
                $task,
                'execution_redispatched_sync',
                sprintf('Relance synchrone par commande avec %s (%s)', $agent->getName(), $skillSlug),
            ));
            $this->em->flush();

            try {
                $this->logService->record(
                    source: 'backend',
                    category: 'runtime',
                    level: 'info',
                    title: 'Agent task redispatched synchronously',
                    // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                    message: sprintf('Relance synchrone de %s vers %s avec le skill %s', $task->getTitle(), $agent->getName(), $skillSlug),
                    options: [
                        'project_id' => (string) $task->getProject()->getId(),
                        'task_id' => (string) $task->getId(),
                        'agent_id' => (string) $agent->getId(),
                        'request_ref' => $requestRef,
                        'trace_ref' => $traceRef,
                        'context' => [
                            'entry_point' => 'cli',
                            'redispatch_mode' => 'sync',
                            'skill_slug' => $skillSlug,
                        ],
                    ],
                );

                $this->agentExecutionService->execute($task, $agent, $skillSlug);
            } catch (\Throwable $e) {
                $this->taskService->failExecution($task, $e->getMessage());
                $io->error(sprintf(
                    'Échec de la relance synchrone avec %s (%s) : %s',
                    $agent->getName(),
                    $skillSlug,
                    $e->getMessage(),
                ));

                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Tâche relancée en synchrone avec %s (%s).',
                $agent->getName(),
                $skillSlug,
            ));

            return Command::SUCCESS;
        }

        $this->em->persist(new TaskLog(
            $task,
            'execution_redispatched',
            sprintf('Relance en file par commande avec %s (%s)', $agent->getName(), $skillSlug),
        ));
        $this->em->flush();

        $requestRef = $this->requestCorrelation->getCurrentRequestRef() ?? Uuid::v7()->toRfc4122();
        $traceRef = Uuid::v7()->toRfc4122();

        $this->bus->dispatch(new AgentTaskMessage(
            taskId: (string) $task->getId(),
            agentId: (string) $agent->getId(),
            skillSlug: $skillSlug,
            requestRef: $requestRef,
            traceRef: $traceRef,
        ));

        $this->logService->record(
            source: 'backend',
            category: 'runtime',
            level: 'info',
            title: 'Agent task redispatched',
            // Stored in DB for the in-app log UI, so the human-facing message stays in French.
            message: sprintf('Relance CLI de %s vers %s avec le skill %s', $task->getTitle(), $agent->getName(), $skillSlug),
            options: [
                'project_id' => (string) $task->getProject()->getId(),
                'task_id' => (string) $task->getId(),
                'agent_id' => (string) $agent->getId(),
                'request_ref' => $requestRef,
                'trace_ref' => $traceRef,
                'context' => [
                    'entry_point' => 'cli',
                    'redispatch_mode' => 'async',
                    'skill_slug' => $skillSlug,
                ],
            ],
        );

        $io->success(sprintf(
            'Tâche remise en file avec %s (%s).',
            $agent->getName(),
            $skillSlug,
        ));

        return Command::SUCCESS;
    }

    private function resolveTask(?string $taskId, ?string $title, bool $latest, SymfonyStyle $io): ?\App\Entity\Task
    {
        $modeCount = (int) ($taskId !== null) + (int) ($title !== null) + (int) $latest;
        if ($modeCount !== 1) {
            $io->error('Utilisez exactement un sélecteur : <task-id>, --title ou --latest.');
            return null;
        }

        if ($taskId !== null) {
            $task = $this->taskService->findById($taskId);
            if ($task === null) {
                $io->error('Tâche introuvable.');
            }
            return $task;
        }

        if ($latest) {
            $tasks = $this->taskRepository->findRecentStories(1);
            if ($tasks === []) {
                $io->error('Aucune story récente trouvée.');
                return null;
            }

            $task = $tasks[0];
            $io->note(sprintf('Story ciblée : "%s" (%s)', $task->getTitle(), (string) $task->getId()));
            return $task;
        }

        $matches = $this->taskRepository->findStoriesByTitleLike($title ?? '', 5);
        if ($matches === []) {
            $io->error('Aucune story correspondante trouvée.');
            return null;
        }

        if (count($matches) > 1) {
            $io->error('Plusieurs stories correspondent. Raffinez le titre ou utilisez l\'ID.');
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
        $io->note(sprintf('Story ciblée : "%s" (%s)', $task->getTitle(), (string) $task->getId()));
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

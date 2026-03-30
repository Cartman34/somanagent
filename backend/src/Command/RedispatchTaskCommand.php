<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Agent;
use App\Entity\Ticket;
use App\Enum\TaskExecutionTrigger;
use App\Repository\AgentRepository;
use App\Repository\TicketLogRepository;
use App\Repository\TicketRepository;
use App\Service\StoryExecutionService;
use App\Service\TicketService;
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
        private readonly TicketService $ticketService,
        private readonly StoryExecutionService $storyExecutionService,
        private readonly AgentRepository $agentRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly TicketLogRepository $ticketLogRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::OPTIONAL, 'ID of the ticket to redispatch')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Searches for a ticket by title')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Targets the most recent ticket')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Forces a specific agent ID')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Deprecated. The refactored model only supports redispatch through Messenger.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ((bool) $input->getOption('sync')) {
            $io->error('Le mode --sync n’est plus supporté sur le modèle Ticket/TicketTask. Utiliser la relance async.');
            return Command::FAILURE;
        }

        $taskId = $input->getArgument('task-id');
        $title = $input->getOption('title');
        $latest = (bool) $input->getOption('latest');
        $agentId = $input->getOption('agent');

        $ticket = $this->resolveTicket(
            is_string($taskId) && $taskId !== '' ? $taskId : null,
            is_string($title) && $title !== '' ? $title : null,
            $latest,
            $io,
        );
        if ($ticket === null) {
            return Command::FAILURE;
        }

        if (!$ticket->isStory()) {
            $io->error('Only user stories and bugs can be redispatched.');
            return Command::FAILURE;
        }

        $skillSlug = $this->resolveSkillSlug($ticket);
        if ($skillSlug === null) {
            $io->error('Unable to determine which skill should be redispatched for this ticket.');
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
            $agent = $this->resolveAgentForSkill($skillSlug, $ticket);
            if ($agent === null) {
                $io->error(sprintf('No active agent found for skill "%s".', $skillSlug));
                return Command::FAILURE;
            }
        }

        $result = $this->storyExecutionService->execute($ticket, $agent, TaskExecutionTrigger::Redispatch);

        $io->success(sprintf(
            'Ticket redispatched with %s (%s).',
            $result['agent']->getName(),
            $result['skill'],
        ));

        return Command::SUCCESS;
    }

    private function resolveTicket(?string $ticketId, ?string $title, bool $latest, SymfonyStyle $io): ?Ticket
    {
        $modeCount = (int) ($ticketId !== null) + (int) ($title !== null) + (int) $latest;
        if ($modeCount !== 1) {
            $io->error('Use exactly one selector: <task-id>, --title, or --latest.');
            return null;
        }

        if ($ticketId !== null) {
            $ticket = $this->ticketService->findById($ticketId);
            if ($ticket === null) {
                $io->error('Ticket not found.');
            }
            return $ticket;
        }

        if ($latest) {
            $tickets = $this->ticketRepository->findRecentStories(1);
            if ($tickets === []) {
                $io->error('No recent ticket found.');
                return null;
            }

            $ticket = $tickets[0];
            $io->note(sprintf('Selected ticket: "%s" (%s)', $ticket->getTitle(), (string) $ticket->getId()));
            return $ticket;
        }

        $matches = $this->ticketRepository->findStoriesByTitleLike($title ?? '', 5);
        if ($matches === []) {
            $io->error('No matching ticket found.');
            return null;
        }

        if (count($matches) > 1) {
            $io->error('Multiple tickets match. Refine the title or use the ID.');
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

        $ticket = $matches[0];
        $io->note(sprintf('Selected ticket: "%s" (%s)', $ticket->getTitle(), (string) $ticket->getId()));
        return $ticket;
    }

    private function resolveSkillSlug(Ticket $ticket): ?string
    {
        $logs = array_reverse($this->ticketLogRepository->findByTicket($ticket));
        foreach ($logs as $log) {
            $metadata = $log->getMetadata();
            if (is_string($metadata['skillSlug'] ?? null) && $metadata['skillSlug'] !== '') {
                return $metadata['skillSlug'];
            }
        }

        if ($this->storyExecutionService->canExecute($ticket)) {
            $config = $this->storyExecutionService->resolveExecutionConfig($ticket);
            return $config['skill'];
        }

        return null;
    }

    private function resolveAgentForSkill(string $skillSlug, Ticket $ticket): ?Agent
    {
        $team = $ticket->getProject()->getTeam();
        $agents = $team !== null
            ? $this->agentRepository->findActiveBySkillSlugAndTeam($skillSlug, $team)
            : $this->agentRepository->findActiveBySkillSlug($skillSlug);

        return $agents[0] ?? null;
    }
}

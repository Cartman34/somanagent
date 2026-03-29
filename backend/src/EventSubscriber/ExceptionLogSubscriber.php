<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\LogService;
use App\Service\RequestCorrelationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LogService $logService,
        private readonly RequestCorrelationService $correlation,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $exception = $event->getThrowable();

        try {
            $this->logService->recordError('backend', '', $exception, [
                'title_i18n' => [
                    'domain' => 'logs',
                    'key' => 'logs.backend.error.unhandled_http_exception.title',
                ],
                'request_ref' => $this->correlation->ensureRequestRef($request),
                'project_id' => $this->resolveProjectId($request),
                'task_id' => $this->resolveTaskId($request),
                'agent_id' => $this->resolveAgentId($request),
                'context' => [
                    'route' => $request->attributes->get('_route'),
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo(),
                    'query' => $request->query->all(),
                ],
            ]);
        } catch (\Throwable) {
            // Avoid infinite exception loops when logging itself fails.
        }
    }

    private function resolveProjectId(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $projectId = $request->attributes->get('projectId');

        return is_string($projectId) && $projectId !== '' ? $projectId : null;
    }

    private function resolveAgentId(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $agentId = $request->attributes->get('agentId');

        return is_string($agentId) && $agentId !== '' ? $agentId : null;
    }

    private function resolveTaskId(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $taskId = $request->attributes->get('taskId');
        if (is_string($taskId) && $taskId !== '') {
            return $taskId;
        }

        $route = $request->attributes->get('_route');
        $id = $request->attributes->get('id');

        if (is_string($route) && str_starts_with($route, 'task_') && is_string($id) && $id !== '') {
            return $id;
        }

        return null;
    }
}

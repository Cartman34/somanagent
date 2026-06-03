<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\EventSubscriber;

use Sowapps\SoManAgent\Service\MessengerExecutionContext;
use Sowapps\SoManAgent\Message\AgentTaskMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Captures Messenger delivery metadata so worker handlers can persist attempt-aware execution state.
 */
final class MessengerExecutionContextSubscriber implements EventSubscriberInterface
{
    /**
     * Injects the execution context shared with message handlers.
     */
    public function __construct(private readonly MessengerExecutionContext $executionContext) {}

    /**
     * Returns the Messenger event classes this subscriber listens to.
     *
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onFinished',
            WorkerMessageFailedEvent::class => 'onFailed',
        ];
    }

    /**
     * Stores retry metadata before the handler runs so execution state can reuse it.
     */
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof AgentTaskMessage) {
            return;
        }

        $retryCount = RedeliveryStamp::getRetryCountFromEnvelope($event->getEnvelope());

        $this->executionContext->set([
            'attempt' => $retryCount + 1,
            'isRetry' => $retryCount > 0,
            'receiverName' => $event->getReceiverName(),
        ]);
    }

    /**
     * Clears per-message retry metadata once handling is complete.
     */
    public function onFinished(WorkerMessageHandledEvent $event): void
    {
        if ($event->getEnvelope()->getMessage() instanceof AgentTaskMessage) {
            $this->executionContext->clear();
        }
    }

    /**
     * Clears per-message retry metadata only when no retry will follow.
     */
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$event->getEnvelope()->getMessage() instanceof AgentTaskMessage) {
            return;
        }

        if (!$event->willRetry()) {
            $this->executionContext->clear();
        }
    }
}

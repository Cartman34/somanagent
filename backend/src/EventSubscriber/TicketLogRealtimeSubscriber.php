<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\TicketLog;
use App\Service\RealtimeUpdateService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Publishes realtime updates for persisted ticket logs after Doctrine inserts them.
 */
#[AsDoctrineListener(event: Events::postPersist)]
final class TicketLogRealtimeSubscriber
{
    /**
     * Initializes the subscriber with the high-level realtime update service.
     */
    public function __construct(
        private readonly RealtimeUpdateService $realtimeUpdateService,
    ) {}

    /**
     * Publishes one realtime update when a new TicketLog row is inserted.
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TicketLog) {
            return;
        }

        $this->realtimeUpdateService->publishTicketLogChanged($entity);
    }
}

<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Port;

use Sowapps\SoManAgent\ValueObject\RealtimeUpdate;

/**
 * Publishes application realtime updates to an external transport.
 */
interface RealtimePublisherPort
{
    /**
     * Publishes one normalized realtime update through the underlying transport.
     */
    public function publish(RealtimeUpdate $update): void;
}

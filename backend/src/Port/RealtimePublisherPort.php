<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Port;

use App\ValueObject\RealtimeUpdate;

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

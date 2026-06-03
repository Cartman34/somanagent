<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Model;

use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogTaskType;

/**
 * Immutable plan describing the resolved interpretation of a queued task before any
 * mutation happens during start.
 *
 * Built fully from read-only operations (board parsing + Git reads); used to display
 * the planned action under --dry-run and as the source of truth for the executor.
 */
final class WorkStartPlan
{
    public const KIND_FEATURE = 'feature';
    public const KIND_TASK = 'task';

    /**
     * @param self::KIND_* $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly BacklogTaskType $type,
        public readonly string $featureSlug,
        public readonly ?string $taskSlug,
        public readonly string $entryText,
        public readonly string $featureBranch,
        public readonly ?string $taskBranch,
        public readonly bool $featureContainerNeedsCreation,
        public readonly string $agent,
    ) {
    }

}

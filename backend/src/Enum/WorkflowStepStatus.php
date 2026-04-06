<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Execution status of a single step within a workflow.
 */
enum WorkflowStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done    = 'done';
    case Error   = 'error';
    case Skipped = 'skipped';

    /**
     * Returns a human-readable label for the workflow step execution status.
     */
    public function label(): string
    {
        return match($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Done    => 'Done',
            self::Error   => 'Error',
            self::Skipped => 'Skipped',
        };
    }
}

<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Model;

/**
 * Resolved model configuration passed to an agent client launcher.
 */
final readonly class ResolvedModel
{
    /**
     * CLI arguments to append to the client command for the resolved model and effort.
     *
     * @var list<string>
     */
    public array $cliArgs;

    /**
     * Non-blocking warnings explaining ignored or degraded model options.
     *
     * @var list<string>
     */
    public array $warnings;

    /**
     * @param list<string> $cliArgs CLI arguments to append to the client command
     * @param list<string> $warnings Non-blocking warnings to print before launch
     */
    public function __construct(array $cliArgs, array $warnings = [])
    {
        $this->cliArgs = $cliArgs;
        $this->warnings = $warnings;
    }
}

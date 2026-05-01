<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\WorktreeScriptProxy;

/**
 * Displays the git worktree context detected by WorktreeScriptProxy.
 */
final class WorktreeInfoRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Display git worktree context for the current script.';
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/worktree-info.php',
        ];
    }

    public function run(array $args): int
    {
        if ($this->scriptFile === null) {
            $this->console->fail('Cannot determine script path.');
        }

        $context = WorktreeScriptProxy::detect($this->scriptFile);

        $type = $context->isLinkedWorktree() ? 'linked worktree' : 'main worktree';

        $this->console->line(sprintf('Type         : %s', $type));
        $this->console->line(sprintf('Current root : %s', $this->format($context->getCurrentRoot())));
        $this->console->line(sprintf('Main root    : %s', $this->format($context->getMainRoot())));

        return 0;
    }

    private function format(?string $value): string
    {
        return $value ?? '(none)';
    }
}

<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Node script runner.
 *
 * Runs reusable commands inside the Node Docker container.
 */
final class NodeRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Run reusable commands inside the Node Docker container';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'type-check', 'description' => 'Run TypeScript type checking'],
            ['name' => 'build', 'description' => 'Build the frontend'],
            ['name' => 'lint', 'description' => 'Run ESLint'],
            ['name' => 'test', 'description' => 'Run frontend tests'],
            ['name' => 'run', 'description' => 'Run a named npm script'],
            ['name' => 'exec', 'description' => 'Execute a raw command in the Node container'],
            ['name' => 'shell', 'description' => 'Open a shell in the Node container'],
        ];
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<script-name>', 'description' => 'npm script name (after "run")'],
            ['name' => '<command>', 'description' => 'Raw command to execute (after "exec")'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/node.php type-check',
            'php scripts/node.php run build',
            'php scripts/node.php exec npm install',
            'php scripts/node.php shell',
        ];
    }

    public function run(array $args): int
    {
        if ($args === []) {
            $this->console->line('Usage: php scripts/node.php type-check');
            $this->console->line('Usage: php scripts/node.php run build');
            $this->console->line('Usage: php scripts/node.php exec npm install');
            $this->console->line('Usage: php scripts/node.php shell');
            return 1;
        }

        try {
            $runner = new NodeCommandRunner($this->app);
            return $runner->run($args);
        } catch (\InvalidArgumentException $e) {
            $this->console->fail($e->getMessage());
        }
    }
}

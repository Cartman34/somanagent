<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Service\NodeCommandService;
use Sowapps\Toolkit\Runner\AbstractScriptRunner;

/**
 * Node script runner (controller).
 *
 * Runs reusable commands inside the Node Docker container. This runner owns the dispatch and the
 * display; the injected {@see NodeCommandService} is a silent thin model that executes the command
 * inside the node container and throws on failure.
 */
final class NodeRunner extends AbstractScriptRunner
{
    private const NAME = 'node';

    public function __construct(
        private readonly NodeCommandService $service,
    ) {
        parent::__construct();
    }

    protected function getName(): string
    {
        return self::NAME;
    }

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

    /**
     * Dispatches the requested Node command to the service.
     *
     * @param list<string> $args
     */
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
            $this->service->run($args);
        } catch (\InvalidArgumentException $e) {
            $this->console->fail($e->getMessage());
        }

        return 0;
    }
}

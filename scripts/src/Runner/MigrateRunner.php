<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\SoManAgentApplication;
use Sowapps\Toolkit\Runner\AbstractScriptRunner;

/**
 * Migrate script runner.
 *
 * Runs Doctrine migrations inside the PHP container.
 * With --generate, produces an isolated diff against a temporary database
 * so that the shared application database is never used as the diff target.
 */
final class MigrateRunner extends AbstractScriptRunner
{
    private const NAME = 'migrate';

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Run Doctrine migrations inside the PHP container';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Show SQL queries without executing'],
            ['name' => '--generate', 'description' => 'Generate a new migration from the current entity diff using an isolated temporary database'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/migrate.php',
            'php scripts/migrate.php --dry-run',
            'php scripts/migrate.php --generate',
        ];
    }

    /**
     * Runs Doctrine migrations or generates a diff, depending on the flags.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [, $options] = $this->parseArgs($args);

        if (isset($options['generate'])) {
            $agentCode = $this->detectAgentCode();
            return (new MigrateGenerateService(SoManAgentApplication::getInstance(), $agentCode, $this->projectRoot, $this->projectRoot))->run();
        }

        try {
            return (new DoctrineRunner(SoManAgentApplication::getInstance()))->run(['migrate', ...$args]);
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->console->fail($e->getMessage());
        }
    }

    /**
     * Resolves the agent code, required for --generate (it names the temporary database).
     */
    private function detectAgentCode(): string
    {
        $fromEnv = trim((string) getenv('SOMANAGER_AGENT'));
        if ($fromEnv === '') {
            $this->console->fail('SOMANAGER_AGENT is required for migrate --generate.');
        }

        return $fromEnv;
    }
}

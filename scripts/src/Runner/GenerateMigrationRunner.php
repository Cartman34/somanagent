<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\SoManAgentApplication;
use Sowapps\Toolkit\Runner\AbstractScriptRunner;

/**
 * Generate migration script runner.
 *
 * Produces an isolated Doctrine diff against a temporary database so that the
 * shared application database is never used as the diff target.
 */
final class GenerateMigrationRunner extends AbstractScriptRunner
{
    private const NAME = 'generate-migration';

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Generate a Doctrine migration using an isolated temporary database';
    }

    protected function getOptions(): array
    {
        return [];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/generate-migration.php',
        ];
    }

    /**
     * Generates a Doctrine migration diff using a temporary database.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [$positional, $options] = $this->parseArgs($args);

        if ($positional !== []) {
            $this->console->fail('generate-migration does not accept arguments.');
        }

        if ($options !== []) {
            $this->console->fail('generate-migration does not accept options.');
        }

        $agentCode = $this->detectAgentCode();

        return (new GenerateMigrationService(
            SoManAgentApplication::getInstance(),
            $agentCode,
            $this->projectRoot,
            $this->projectRoot,
        ))->run();
    }

    /**
     * Resolves the agent code, required because it names the temporary database.
     */
    private function detectAgentCode(): string
    {
        $fromEnv = trim((string) getenv('SOMANAGER_AGENT'));
        if ($fromEnv === '') {
            $this->console->fail('SOMANAGER_AGENT is required for generate-migration.');
        }

        return $fromEnv;
    }
}

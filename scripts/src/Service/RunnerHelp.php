<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Service;

/**
 * Typed container for runner-level YAML help data — read from {scriptResources}/help.yaml.
 */
final class RunnerHelp
{
    /**
     * @param list<CommandParamHelp> $options
     * @param list<string>           $examples
     * @param list<string>           $commandNames
     */
    public function __construct(
        public readonly string $description,
        public readonly array $options,
        public readonly array $examples,
        public readonly array $commandNames,
    ) {
    }
}

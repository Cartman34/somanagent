<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Service;

/**
 * Typed container for per-command YAML help data — read from {scriptResources}/commands/{name}.yaml.
 */
final class CommandHelp
{
    /**
     * @param list<CommandParamHelp> $arguments
     * @param list<CommandParamHelp> $options
     * @param list<string>           $examples
     * @param list<string>           $notes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $arguments,
        public readonly array $options,
        public readonly array $examples,
        public readonly array $notes,
    ) {
    }
}

<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script;

use Sowapps\Backlog\BacklogServiceFactory;

/**
 * Host service factory for SoManAgent.
 *
 * Inherits the complete backlog + toolkit service builders. It adds no host-specific service yet, but
 * is posed and used so the host owns its own (extensible) factory in the package inheritance chain.
 */
final class SoManAgentServiceFactory extends BacklogServiceFactory
{
}

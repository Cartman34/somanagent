<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Test\Backlog\Campaign;

use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;
use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use Sowapps\SoManAgent\Script\Test\Backlog\Campaign\CampaignInterface;
final class HelpCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'help';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->runHelpChecks();
        $driver->runOptionEqualsChecks();
        $driver->runForceCurrentWorktreeFlagChecks();
        $driver->runStrictOptionsChecks();
    }
}

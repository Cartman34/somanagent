<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

final class HelpCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'help';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->runHelpChecks();
    }
}

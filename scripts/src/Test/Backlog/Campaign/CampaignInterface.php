<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Test\Backlog\Campaign;

use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;
use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;

interface CampaignInterface
{
    public function getName(): string;

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void;
}

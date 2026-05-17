<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Board format normalization campaign.
 *
 * Verifies YAML board structural invariants: version field, section presence,
 * round-trip fidelity for all entry fields, empty-state representation,
 * and absence of legacy markdown artefacts after save.
 */
final class BoardFormatNormalizationCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'board-format-normalization';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        // Scenario 1 — fresh board has correct YAML skeleton.
        // After resetArtifacts() the board must declare version 1 with empty sections.
        $driver->assertBoardContains('version: 1');
        $driver->assertBoardContains('todo: []');
        $driver->assertBoardContains('active: []');
        $driver->assertBoardLacksText('## To do');
        $driver->assertBoardLacksText('## In progress');
        $driver->assertBoardLacksText('## Suggestions');

        // Scenario 2 — plain feature entry round-trips with expected YAML shape.
        $bodyFile = $driver->createBodyFile('norm-plain.md', ['Plain feature title']);
        $driver->runBacklog(['entry-create', '--feature=norm-plain', "--body-file={$bodyFile}"]);
        $driver->assertBoardContains('version: 1');
        $driver->assertBoardTodoBlock([
            '  - feature: norm-plain',
            "    title: 'Plain feature title'",
        ]);
        $driver->assertBoardLacksText('## To do');
        $driver->removeFirstTodoTask();
        // After removal, todo section collapses back to inline empty list.
        $driver->assertBoardContains('todo: []');

        // Scenario 3 — scoped entry with all optional fields round-trips correctly.
        $driver->resetArtifacts();
        $bodyFile = $driver->createBodyFile('norm-scoped.md', ['Scoped entry title']);
        $driver->runBacklog(['entry-create', '--feature=norm-feature', '--task=norm-task', '--type=feat', "--body-file={$bodyFile}"]);
        $driver->assertBoardTodoBlock([
            '  - feature: norm-feature',
            '    task: norm-task',
            '    type: feat',
            "    title: 'Scoped entry title'",
        ]);
        $driver->removeTodoTask('norm-feature/norm-task');

        // Scenario 4 — multi-line body round-trips as YAML block scalar.
        $driver->resetArtifacts();
        $bodyFile = $driver->createBodyFile('norm-body.md', [
            'Body round-trip title',
            '  - Sub-item alpha',
            '  - Sub-item beta',
        ]);
        $driver->runBacklog(['entry-create', '--feature=norm-body', '--task=norm-body-task', "--body-file={$bodyFile}"]);
        $driver->assertBoardTodoBlock([
            '  - feature: norm-body',
            '    task: norm-body-task',
            "    title: 'Body round-trip title'",
        ]);
        $driver->assertBoardContains('- Sub-item alpha');
        $driver->assertBoardContains('- Sub-item beta');
        $driver->removeTodoTask('norm-body/norm-body-task');

        // Scenario 5 — multiple entries preserve insertion order in YAML list.
        $driver->resetArtifacts();
        $fileA = $driver->createBodyFile('norm-order-a.md', ['Order entry A']);
        $fileB = $driver->createBodyFile('norm-order-b.md', ['Order entry B']);
        $fileC = $driver->createBodyFile('norm-order-c.md', ['Order entry C']);
        $driver->runBacklog(['entry-create', '--feature=norm-order-a', "--body-file={$fileA}"]);
        $driver->runBacklog(['entry-create', '--feature=norm-order-b', "--body-file={$fileB}"]);
        $driver->runBacklog(['entry-create', '--feature=norm-order-c', "--body-file={$fileC}"]);
        $boardText = $driver->getBoardText();
        $posA = strpos($boardText, 'feature: norm-order-a');
        $posB = strpos($boardText, 'feature: norm-order-b');
        $posC = strpos($boardText, 'feature: norm-order-c');
        if ($posA === false || $posB === false || $posC === false || !($posA < $posB && $posB < $posC)) {
            throw new \RuntimeException('Board entry order not preserved: expected A < B < C in YAML output.');
        }
        $driver->removeTodoTask('norm-order-a');
        $driver->removeTodoTask('norm-order-b');
        $driver->removeTodoTask('norm-order-c');
    }
}

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
 * Verifies that saveBoard strips legacy rule sections, normalizes legacy titles,
 * ensures the Suggestions section is always present, and preserves work entries
 * across all board variants (fresh, legacy French rules, legacy Usage rules).
 */
final class BoardFormatNormalizationCampaign implements CampaignInterface
{
    /**
     * Returns the campaign identifier.
     */
    public function getName(): string
    {
        return 'board-format-normalization';
    }

    /**
     * Runs all normalization scenarios against the test board.
     */
    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        // Scenario 1 — fresh board already uses English title but carries a
        // "Usage rules" section (as created by resetArtifacts); after the first
        // write the section must be gone and mandatory sections must remain.
        $driver->createTodoTask('[tech][board-format-normalization][norm-fresh] Fresh board normalization');
        $driver->assertBoardLacksText('## Usage rules');
        $driver->assertBoardTodoBlock(['- [board-format-normalization][norm-fresh] Fresh board normalization']);
        $driver->assertBoardTodoBlock(['## To do']);
        $driver->assertBoardTodoBlock(['## In progress']);
        $driver->assertBoardTodoBlock(['## Suggestions']);
        $driver->removeFirstTodoTask();

        // Scenario 2 — board carrying a French "Règles d'usage" section must have
        // that section stripped while work entries are preserved.
        $driver->resetArtifacts();
        $driver->replaceBoardText('## Usage rules', "## Règles d'usage");
        $driver->createTodoTask("[tech][board-format-normalization][norm-french] French rules normalization");
        $driver->assertBoardLacksText("## Règles d'usage");
        $driver->assertBoardTodoBlock(['- [board-format-normalization][norm-french] French rules normalization']);
        $driver->removeFirstTodoTask();

        // Scenario 3 — board with a legacy French title must be normalised to the
        // canonical English title after a write; work entries must be preserved.
        $driver->resetArtifacts();
        $driver->replaceBoardText('# Backlog board', '# Tableau du backlog');
        $driver->createTodoTask('[tech][board-format-normalization][norm-title] Legacy title normalization');
        $driver->assertBoardLacksText('# Tableau du backlog');
        $driver->assertBoardTodoBlock(['# Backlog board']);
        $driver->assertBoardTodoBlock(['- [board-format-normalization][norm-title] Legacy title normalization']);
        $driver->removeFirstTodoTask();

        // Scenario 4 — board missing the Suggestions section entirely must have it
        // re-added by saveBoard; existing work entries must survive.
        $driver->resetArtifacts();
        $driver->replaceBoardText("\n## Suggestions\n", "\n");
        $driver->createTodoTask('[tech][board-format-normalization][norm-suggestions] Missing Suggestions re-added');
        $driver->assertBoardTodoBlock(['## Suggestions']);
        $driver->assertBoardTodoBlock(['- [board-format-normalization][norm-suggestions] Missing Suggestions re-added']);
        $driver->removeFirstTodoTask();
    }
}

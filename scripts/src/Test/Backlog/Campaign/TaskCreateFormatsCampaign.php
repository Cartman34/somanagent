<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Task creation formats campaign.
 *
 * Covers the supported task-create input shapes: single-line text with optional
 * type prefix, single-line text with feature/task scoped prefix, multi-line
 * inline body with sub-tasks, and multi-line body read from --body-file=<path>.
 */
final class TaskCreateFormatsCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'task-create-formats';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->createTodoTask('[feat] Single-line feat task');
        $driver->assertTodoContains('Single-line feat task');
        $driver->assertBoardTodoBlock([
            '- Single-line feat task',
            '  meta:',
            '    type: feat',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[fix] Single-line fix task');
        $driver->assertBoardTodoBlock([
            '- Single-line fix task',
            '  meta:',
            '    type: fix',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[scope-feature][scope-task] Scoped task title');
        $driver->assertTodoContains('[scope-feature][scope-task] Scoped task title');
        $driver->assertBoardTodoBlock([
            '- [scope-feature][scope-task] Scoped task title',
        ]);
        $driver->removeFirstTodoTask();

        $multilineInline = "[feat][multiline-feature][multiline-task] Multiline inline task title\n  - First sub-task\n  - Second sub-task";
        $driver->createTodoTask($multilineInline);
        $driver->assertTodoContains('Multiline inline task title');
        $driver->assertBoardTodoBlock([
            '- [multiline-feature][multiline-task] Multiline inline task title',
            '  - First sub-task',
            '  - Second sub-task',
            '  meta:',
            '    type: feat',
        ]);
        $driver->removeFirstTodoTask();

        $bodyFilePath = $driver->createTodoTaskFromBodyFile([
            '[feat][body-file-feature][body-file-task] Body file task title',
            '  - Sub-task one',
            '  - Sub-task two',
            '  - Sub-task three',
        ], 'task-create-body-file.md');
        $driver->assertBoardTodoBlock([
            '- [body-file-feature][body-file-task] Body file task title',
            '  - Sub-task one',
            '  - Sub-task two',
            '  - Sub-task three',
            '  meta:',
            '    type: feat',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTaskFromBodyFile([
            '- [feat] Bullet leading body file task',
            'Unindented sub-task that should be auto-indented',
        ], 'task-create-body-file-bullet.md');
        $driver->assertBoardTodoBlock([
            '- Bullet leading body file task',
            '  Unindented sub-task that should be auto-indented',
            '  meta:',
            '    type: feat',
        ]);
        $driver->removeFirstTodoTask();

        $driver->assertTaskCreateFails(
            'task-create does not accept positional <text> when --body-file is used.',
            ['Positional should not be allowed', '--body-file=' . $bodyFilePath],
        );

        $driver->assertTaskCreateFails(
            '--body-file path does not exist',
            ['--body-file=local/tmp/task-create-missing-body-file.md'],
        );
    }
}

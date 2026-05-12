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
 * type prefix ([feat] / [fix] / [tech]), single-line text with feature/task scoped
 * prefix, type prefix at any position in the leading bracket sequence, multi-line
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
        foreach (['feat', 'fix', 'tech'] as $type) {
            $driver->createTodoTask(sprintf('[%s] Single-line %s task', $type, $type));
            $driver->assertBoardTodoBlock([
                sprintf('- Single-line %s task', $type),
                '  meta:',
                sprintf('    type: %s', $type),
            ]);
            $driver->removeFirstTodoTask();
        }

        $driver->createTodoTask('[scope-feature][scope-task] Scoped task title');
        $driver->assertTodoContains('[scope-feature][scope-task] Scoped task title');
        $driver->assertBoardTodoBlock([
            '- [scope-feature][scope-task] Scoped task title',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[tech][backlog-entry-types] Type-leading scoped feature');
        $driver->assertBoardTodoBlock([
            '- [backlog-entry-types] Type-leading scoped feature',
            '  meta:',
            '    type: tech',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[backlog-entry-types][tech] Type-trailing single feature');
        $driver->assertBoardTodoBlock([
            '- [backlog-entry-types] Type-trailing single feature',
            '  meta:',
            '    type: tech',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTask('[backlog-entry-types][child-task][tech] Type-trailing scoped child task');
        $driver->assertBoardTodoBlock([
            '- [backlog-entry-types][child-task] Type-trailing scoped child task',
            '  meta:',
            '    type: tech',
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
            '[tech][body-file-feature][body-file-task] Body file task title',
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
            '    type: tech',
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
            'Duplicate task type prefix',
            ['[feat][fix] Duplicate type'],
        );

        $driver->assertTaskCreateFails(
            'task-create does not accept positional <text> when --body-file is used.',
            ['Positional should not be allowed', '--body-file=' . $bodyFilePath],
        );

        $driver->assertTaskCreateFails(
            '--body-file path does not exist',
            ['--body-file=local/tmp/task-create-missing-body-file.md'],
        );

        // --position=index clamps out-of-range values while still inserting the task.
        $driver->createTodoTask('[clamp-low] Task to anchor the queue for clamp coverage');
        $clampLowOutput = $driver->runBacklog([
            'task-create',
            '[clamp-zero] Insert with --index=0 should clamp to start',
            '--position=index',
            '--index=0',
        ]);
        $driver->assertOutputContainsAll($clampLowOutput, [
            'Warning: --index=0 is out of range',
            'inserting at position 1 instead',
        ]);
        $driver->assertTodoContains('[clamp-zero] Insert with --index=0 should clamp to start');
        $clampHighOutput = $driver->runBacklog([
            'task-create',
            '[clamp-high] Insert with --index=99 should clamp to end',
            '--position=index',
            '--index=99',
        ]);
        $driver->assertOutputContainsAll($clampHighOutput, [
            'Warning: --index=99 is out of range',
        ]);
        $driver->assertTodoContains('[clamp-high] Insert with --index=99 should clamp to end');
        $driver->removeTodoTask('clamp-zero');
        $driver->removeTodoTask('clamp-low');
        $driver->removeTodoTask('clamp-high');

        $driver->assertTaskCreateFails(
            'task-create with --position=index requires --index=',
            ['Missing index value', '--position=index'],
        );
    }
}

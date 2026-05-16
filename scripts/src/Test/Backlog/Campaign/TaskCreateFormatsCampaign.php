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
 * Covers the supported task-create input shapes, all via --body-file=<path>:
 * single-line title with optional type prefix ([feat] / [fix] / [tech]),
 * feature/task scoped prefix, type prefix at any position in the leading bracket
 * sequence, multi-line body with sub-tasks, and bullet-leading body file.
 * Also covers rejection of inline positional text, missing --body-file,
 * duplicate type prefix, missing scope, and out-of-range --index clamping.
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
            $driver->createTodoTask(sprintf('[%s][test-%s-single] Single-line %s task', $type, $type, $type));
            $driver->assertBoardTodoBlock([
                sprintf('- [test-%s-single] Single-line %s task', $type, $type),
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
            '    - First sub-task',
            '    - Second sub-task',
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
            '    - Sub-task one',
            '    - Sub-task two',
            '    - Sub-task three',
            '  meta:',
            '    type: tech',
        ]);
        $driver->removeFirstTodoTask();

        $driver->createTodoTaskFromBodyFile([
            '- [feat][test-bullet-feature] Bullet leading body file task',
            'Unindented sub-task that should be auto-indented',
        ], 'task-create-body-file-bullet.md');
        $driver->assertBoardTodoBlock([
            '- [test-bullet-feature] Bullet leading body file task',
            '  Unindented sub-task that should be auto-indented',
            '  meta:',
            '    type: feat',
        ]);
        $driver->removeFirstTodoTask();

        // Standard markdown nesting: top-level bullet (0 indent) and sub-bullet (2 spaces)
        // must land at 2 and 4 spaces in the board respectively after the uniform +2 shift.
        $driver->createTodoTaskFromBodyFile([
            '[markdown-nesting-feature] Standard markdown nesting task',
            '- Top-level item',
            '  - Sub-level item',
        ], 'task-create-markdown-nesting.md');
        $driver->assertBoardTodoBlock([
            '- [markdown-nesting-feature] Standard markdown nesting task',
            '  - Top-level item',
            '    - Sub-level item',
        ]);
        $driver->removeFirstTodoTask();

        // Rejection: duplicate type prefix via body file.
        $dupTypeFile = $driver->createBodyFile('task-create-dup-type.md', ['[feat][fix] Duplicate type']);
        $driver->assertTaskCreateFails(
            'Duplicate task type prefix',
            ['--body-file=' . $dupTypeFile],
        );

        // Rejection: inline positional text is no longer accepted (with or without --body-file).
        $driver->assertTaskCreateFails(
            'task-create no longer accepts inline task text',
            ['[positional-only] Should fail'],
        );
        $driver->assertTaskCreateFails(
            'task-create no longer accepts inline task text',
            ['Positional should not be allowed', '--body-file=' . $bodyFilePath],
        );

        // Rejection: no --body-file and no positional text.
        $driver->assertTaskCreateFails(
            'task-create requires --body-file=<path>',
            [],
        );

        $driver->assertTaskCreateFails(
            '--body-file path does not exist',
            ['--body-file=local/tmp/task-create-missing-body-file.md'],
        );

        // --position=index clamps out-of-range values while still inserting the task.
        $driver->createTodoTask('[clamp-low] Task to anchor the queue for clamp coverage');
        $clampZeroFile = $driver->createBodyFile('task-create-clamp-zero.md', ['[clamp-zero] Insert with --index=0 should clamp to start']);
        $clampLowOutput = $driver->runBacklog([
            'task-create',
            '--body-file=' . $clampZeroFile,
            '--position=index',
            '--index=0',
        ]);
        $driver->assertOutputContainsAll($clampLowOutput, [
            'Warning: --index=0 is out of range',
            'inserting at position 1 instead',
        ]);
        $driver->assertTodoContains('[clamp-zero] Insert with --index=0 should clamp to start');
        $clampHighFile = $driver->createBodyFile('task-create-clamp-high.md', ['[clamp-high] Insert with --index=99 should clamp to end']);
        $clampHighOutput = $driver->runBacklog([
            'task-create',
            '--body-file=' . $clampHighFile,
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

        $missingIndexFile = $driver->createBodyFile('task-create-missing-index.md', ['[test-missing-index] Missing index value']);
        $driver->assertTaskCreateFails(
            'task-create with --position=index requires --index=',
            ['--body-file=' . $missingIndexFile, '--position=index'],
        );

        // Rejection: bare title without any scope prefix.
        $bareTitleFile = $driver->createBodyFile('task-create-bare-title.md', ['Bare title without scope']);
        $driver->assertTaskCreateFails(
            'task-create requires an explicit [feature-slug] scope',
            ['--body-file=' . $bareTitleFile],
        );

        // Rejection: type prefix alone, no feature scope.
        $typeOnlyFile = $driver->createBodyFile('task-create-type-only.md', ['[tech] Type only no feature scope']);
        $driver->assertTaskCreateFails(
            'task-create requires an explicit [feature-slug] scope',
            ['--body-file=' . $typeOnlyFile],
        );

        // Rejection: bare title in --body-file mode with sub-tasks.
        $noScopeBodyFile = $driver->createBodyFile('task-create-no-scope-body.md', ['Bare title in body file', '  - sub-task']);
        $driver->assertTaskCreateFails(
            'task-create requires an explicit [feature-slug] scope',
            ['--body-file=' . $noScopeBodyFile],
        );
    }
}

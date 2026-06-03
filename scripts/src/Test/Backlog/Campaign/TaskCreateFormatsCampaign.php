<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Test\Backlog\Campaign;

use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;
use Sowapps\SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;

/**
 * Task creation formats campaign.
 *
 * Covers the supported entry-create input shapes via --feature / --task / --type / --body-file:
 * plain feature, scoped feature/task with each valid type (feat / fix / tech), multi-line body,
 * and rejection cases: missing --feature, missing --type, missing --body-file, unknown --type,
 * --body-file path does not exist, legacy bracket prefix in title, and out-of-range --index clamping.
 */
final class TaskCreateFormatsCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'entry-create-formats';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        // Plain feature entry — each valid type prefix.
        foreach (['feat', 'fix', 'tech'] as $type) {
            $slug = sprintf('test-%s-single', $type);
            $bodyFile = $driver->createBodyFile("entry-create-{$type}-single.md", [sprintf('Single-line %s task', $type)]);
            $driver->runBacklog([BacklogCommandName::ENTRY_CREATE->value, "--feature={$slug}", "--type={$type}", "--body-file={$bodyFile}"]);
            $driver->assertBoardTodoBlock([
                "  - feature: {$slug}",
                "    type: {$type}",
                sprintf("    title: 'Single-line %s task'", $type),
            ]);
            $driver->removeTodoTask($slug);
        }

        // Scoped feature/task entry with explicit type.
        $bodyFile = $driver->createBodyFile('entry-create-scoped.md', ['Scoped task title']);
        $driver->runBacklog([BacklogCommandName::ENTRY_CREATE->value, '--feature=scope-feature', '--task=scope-task', '--type=feat', "--body-file={$bodyFile}"]);
        $driver->assertBoardTodoBlock([
            '  - feature: scope-feature',
            '    task: scope-task',
            '    type: feat',
            "    title: 'Scoped task title'",
        ]);
        $driver->removeTodoTask('scope-feature/scope-task');

        // Type stored when feature has the same slug as the type keyword.
        $bodyFile = $driver->createBodyFile('entry-create-type-leading.md', ['Type-leading scoped feature']);
        $driver->runBacklog([BacklogCommandName::ENTRY_CREATE->value, '--feature=backlog-entry-types', '--type=tech', "--body-file={$bodyFile}"]);
        $driver->assertBoardTodoBlock([
            '  - feature: backlog-entry-types',
            '    type: tech',
            "    title: 'Type-leading scoped feature'",
        ]);
        $driver->removeTodoTask('backlog-entry-types');

        // Scoped task with type.
        $bodyFile = $driver->createBodyFile('entry-create-scoped-type.md', ['Type-leading scoped child task']);
        $driver->runBacklog([BacklogCommandName::ENTRY_CREATE->value, '--feature=backlog-entry-types', '--task=child-task', '--type=tech', "--body-file={$bodyFile}"]);
        $driver->assertBoardTodoBlock([
            '  - feature: backlog-entry-types',
            '    task: child-task',
            '    type: tech',
            "    title: 'Type-leading scoped child task'",
        ]);
        $driver->removeTodoTask('backlog-entry-types/child-task');

        // Multi-line body via body file.
        $multilineBodyFile = $driver->createBodyFile('entry-create-body-file.md', [
            'Body file task title',
            '  - Sub-task one',
            '  - Sub-task two',
            '  - Sub-task three',
        ]);
        $driver->runBacklog([BacklogCommandName::ENTRY_CREATE->value, '--feature=body-file-feature', '--task=body-file-task', '--type=tech', "--body-file={$multilineBodyFile}"]);
        $driver->assertBoardTodoBlock([
            '  - feature: body-file-feature',
            '    task: body-file-task',
            '    type: tech',
            "    title: 'Body file task title'",
        ]);
        $driver->assertBoardContains('- Sub-task one');
        $driver->assertBoardContains('- Sub-task two');
        $driver->assertBoardContains('- Sub-task three');
        $driver->removeTodoTask('body-file-feature/body-file-task');

        // Rejection: inline positional text is not accepted.
        $driver->assertTaskCreateFails(
            'entry-create no longer accepts inline task text',
            ['[positional-only] Should fail'],
        );

        // Rejection: no --body-file.
        $driver->assertTaskCreateFails(
            'entry-create requires --body-file=<path>',
            ['--feature=missing-body-feature', '--type=feat'],
        );

        // Rejection: no --feature at all.
        $driver->assertTaskCreateFails(
            'entry-create requires --feature=<slug>',
            [],
        );

        // Rejection: no --type at all.
        $missingTypeFile = $driver->createBodyFile('entry-create-missing-type.md', ['Title without type']);
        $driver->assertTaskCreateFails(
            'entry-create requires --type=<feat, fix, tech>',
            ['--feature=missing-type-feature', "--body-file={$missingTypeFile}"],
        );

        // Rejection: --body-file path does not exist.
        $driver->assertTaskCreateFails(
            '--body-file path does not exist',
            ['--feature=some-feature', '--type=feat', '--body-file=/nonexistent/path/entry-create-missing-body-file.md'],
        );

        // Rejection: unknown --type value.
        $unknownTypeFile = $driver->createBodyFile('entry-create-unknown-type.md', ['Unknown type title']);
        $driver->assertTaskCreateFails(
            'Unknown --type=invalid',
            ['--feature=some-feature', '--type=invalid', "--body-file={$unknownTypeFile}"],
        );

        // Rejection: legacy bracket prefix in body file title.
        $bracketTitleFile = $driver->createBodyFile('entry-create-bracket-title.md', ['[feat][legacy-feature] Legacy title']);
        $driver->assertTaskCreateFails(
            'obsolete prefix syntax, use the CLI options',
            ['--feature=legacy-feature', '--type=feat', "--body-file={$bracketTitleFile}"],
        );

        // --position=index clamps out-of-range values while still inserting the task.
        $driver->createTodoTask('[clamp-low] Task to anchor the queue for clamp coverage');
        $clampZeroFile = $driver->createBodyFile('entry-create-clamp-zero.md', ['Insert with --index=0 should clamp to start']);
        $clampLowOutput = $driver->runBacklog([
            BacklogCommandName::ENTRY_CREATE->value,
            '--feature=clamp-zero',
            '--type=feat',
            "--body-file={$clampZeroFile}",
            '--position=index',
            '--index=0',
        ]);
        $driver->assertOutputContainsAll($clampLowOutput, [
            'Warning: --index=0 is out of range',
            'inserting at position 1 instead',
        ]);
        $driver->assertTodoContains('Insert with --index=0 should clamp to start');
        $clampHighFile = $driver->createBodyFile('entry-create-clamp-high.md', ['Insert with --index=99 should clamp to end']);
        $clampHighOutput = $driver->runBacklog([
            BacklogCommandName::ENTRY_CREATE->value,
            '--feature=clamp-high',
            '--type=feat',
            "--body-file={$clampHighFile}",
            '--position=index',
            '--index=99',
        ]);
        $driver->assertOutputContainsAll($clampHighOutput, [
            'Warning: --index=99 is out of range',
        ]);
        $driver->assertTodoContains('Insert with --index=99 should clamp to end');
        $driver->removeTodoTask('clamp-zero');
        $driver->removeTodoTask('clamp-low');
        $driver->removeTodoTask('clamp-high');

        $missingIndexFile = $driver->createBodyFile('entry-create-missing-index.md', ['Missing index value']);
        $driver->assertTaskCreateFails(
            'entry-create with --position=index requires --index=',
            ['--feature=test-missing-index', '--type=feat', "--body-file={$missingIndexFile}", '--position=index'],
        );
    }
}

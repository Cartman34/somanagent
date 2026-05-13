<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Entry set-meta campaign.
 *
 * Covers the entry-set-meta command: setting and clearing the `database` key on an
 * active entry, and the rejection cases (no active entry, unknown key, missing `=`).
 */
final class EntrySetMetaCampaign implements CampaignInterface
{
    public function getName(): string
    {
        return 'entry-set-meta';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $agent = $context->agentPrimary;

        // ── Setup: start a plain feature so the agent has an active entry ──────
        $driver->createTodoTask('[tech][set-meta-test] entry-set-meta lifecycle test');
        $driver->startNextFeature($agent, 'set-meta-test');

        // ── Set database key ──────────────────────────────────────────────────
        $driver->setEntryMeta($agent, 'database=d04_migrate_gen');
        $driver->assertBoardContains('    database: d04_migrate_gen');

        // ── Overwrite with a new value ────────────────────────────────────────
        $driver->setEntryMeta($agent, 'database=d04_migrate_gen_v2');
        $driver->assertBoardContains('    database: d04_migrate_gen_v2');
        $driver->assertBoardMissing('    database: d04_migrate_gen');

        // ── Clear database key (empty value) ──────────────────────────────────
        $driver->setEntryMeta($agent, 'database=');
        $driver->assertBoardMissing('    database:');

        // ── Rejection: unknown key ────────────────────────────────────────────
        $driver->assertSetEntryMetaFails($agent, 'unknown-key=value', 'does not support key "unknown-key"');

        // ── Rejection: missing = separator ───────────────────────────────────
        $driver->assertSetEntryMetaFails($agent, 'database', 'key=value argument');

        // ── Rejection: no active entry ────────────────────────────────────────
        $driver->assertSetEntryMetaFails('agent-without-entry', 'database=some_db', 'has no active entry');
    }
}

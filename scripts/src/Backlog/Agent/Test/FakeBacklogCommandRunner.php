<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\BacklogCommandRunner;

/**
 * Recording stub for BacklogCommandRunner.
 *
 * Records every call in $calls and delegates to optional callbacks ($onReviewNext,
 * $onReviewCancel, $onWorkStart, $onEntryRelease) so tests can inject board mutations
 * that simulate the real commands.
 */
final class FakeBacklogCommandRunner implements BacklogCommandRunner
{
    /**
     * Ordered list of calls received.
     * Each entry carries 'method' plus the method-specific keys:
     *   reviewNext/reviewCancel: 'reviewerCode', 'entryRef'
     *   workStart/entryRelease:  'developerCode', 'entryRef'
     *
     * @var list<array<string, string>>
     */
    public array $calls = [];

    /**
     * Optional callback invoked during reviewNext(). Receives (reviewerCode, entryRef).
     *
     * @var (callable(string, string): void)|null
     */
    public $onReviewNext = null;

    /**
     * Optional callback invoked during reviewCancel(). Receives (reviewerCode, entryRef).
     *
     * @var (callable(string, string): void)|null
     */
    public $onReviewCancel = null;

    /**
     * Optional callback invoked during workStart(). Receives (developerCode, entryRef).
     *
     * @var (callable(string, string): void)|null
     */
    public $onWorkStart = null;

    /**
     * {@inheritdoc}
     */
    public function reviewNext(string $reviewerCode, string $entryRef): void
    {
        $this->calls[] = ['method' => 'reviewNext', 'reviewerCode' => $reviewerCode, 'entryRef' => $entryRef];
        if ($this->onReviewNext !== null) {
            ($this->onReviewNext)($reviewerCode, $entryRef);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function reviewCancel(string $reviewerCode, string $entryRef): void
    {
        $this->calls[] = ['method' => 'reviewCancel', 'reviewerCode' => $reviewerCode, 'entryRef' => $entryRef];
        if ($this->onReviewCancel !== null) {
            ($this->onReviewCancel)($reviewerCode, $entryRef);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function workStart(string $developerCode, string $entryRef): void
    {
        $this->calls[] = ['method' => 'workStart', 'developerCode' => $developerCode, 'entryRef' => $entryRef];
        if ($this->onWorkStart !== null) {
            ($this->onWorkStart)($developerCode, $entryRef);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function entryRelease(string $developerCode, string $entryRef): void
    {
        $this->calls[] = ['method' => 'entryRelease', 'developerCode' => $developerCode, 'entryRef' => $entryRef];
    }
}

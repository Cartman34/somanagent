<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog;

final class BacklogScriptTestContext
{
    /** @var array<string, true> */
    private array $localBranches = [];

    /** @var array<string, true> */
    private array $remoteBranches = [];

    /** @var array<string, true> */
    private array $tempFiles = [];

    /** @var array<string, true> */
    private array $worktrees = [];

    private ?int $pullRequestNumber = null;
    private bool $pullRequestMerged = false;
    private ?string $prBaseBranch = null;

    public function __construct(
        public readonly string $projectRoot,
        public readonly string $boardPath,
        public readonly string $reviewPath,
        public readonly string $tmpDir,
        public readonly bool $allowRemote,
        public readonly bool $keepArtifacts,
        public readonly bool $dryRun,
        public readonly bool $verbose,
        public readonly string $agentPrimary = 'test-d01',
        public readonly string $agentSecondary = 'test-d02',
        public readonly string $plainFeature = 'test-plain-feature-alpha',
        public readonly string $assignFeature = 'test-assign-feature',
        public readonly string $fixFeature = 'test-fix-feature-beta',
        public readonly string $scopedFeature = 'test-scoped-feature',
        public readonly string $childA = 'test-child-a',
        public readonly string $childB = 'test-child-b',
    ) {
    }

    public function recordLocalBranch(string $branch): void
    {
        $this->localBranches[$branch] = true;
    }

    /**
     * @return array<string>
     */
    public function localBranches(): array
    {
        return array_keys($this->localBranches);
    }

    public function recordRemoteBranch(string $branch): void
    {
        $this->remoteBranches[$branch] = true;
    }

    /**
     * @return array<string>
     */
    public function remoteBranches(): array
    {
        return array_keys($this->remoteBranches);
    }

    public function recordTempFile(string $path): void
    {
        $this->tempFiles[$path] = true;
    }

    /**
     * @return array<string>
     */
    public function tempFiles(): array
    {
        return array_keys($this->tempFiles);
    }

    public function recordWorktree(string $path): void
    {
        $this->worktrees[$path] = true;
    }

    public function hasWorktree(string $path): bool
    {
        return isset($this->worktrees[$path]);
    }

    /**
     * @return array<string>
     */
    public function worktrees(): array
    {
        return array_keys($this->worktrees);
    }

    public function setPullRequestNumber(?int $number): void
    {
        $this->pullRequestNumber = $number;
    }

    public function pullRequestNumber(): ?int
    {
        return $this->pullRequestNumber;
    }

    public function markPullRequestMerged(): void
    {
        $this->pullRequestMerged = true;
    }

    public function isPullRequestMerged(): bool
    {
        return $this->pullRequestMerged;
    }

    public function setPrBaseBranch(?string $branch): void
    {
        $this->prBaseBranch = $branch;
    }

    public function prBaseBranch(): ?string
    {
        return $this->prBaseBranch;
    }
}

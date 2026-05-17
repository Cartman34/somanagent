<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\LaunchDecision;
use SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver;
use SoManAgent\Script\Backlog\Model\BacklogBoard;

/**
 * Unit tests for {@see AgentLaunchPromptResolver::resolveStageDecision()}.
 *
 * Covers all 14 combinations: developer × 7 stages + reviewer × 7 stages.
 */
final class AgentLaunchPromptResolverTest
{
    private string $promptPath;

    /**
     * @var array<string, array{role: AgentRole, stage: string|null, expectType: string, expectContains: string}>
     */
    private array $table;

    public function __construct()
    {
        $this->promptPath = dirname(__DIR__, 4) . '/resources/backlog-agent/launch-prompts.yaml';

        $this->table = [
            // Developer × 7 stages
            'dev_todo'       => [AgentRole::DEVELOPER, null, LaunchDecision::TYPE_PROMPT, 'Démarre'],
            'dev_development' => [AgentRole::DEVELOPER, BacklogBoard::STAGE_IN_PROGRESS, LaunchDecision::TYPE_PROMPT, 'Reprends'],
            'dev_review'     => [AgentRole::DEVELOPER, BacklogBoard::STAGE_IN_REVIEW, LaunchDecision::TYPE_REFUSE, 'rien à faire'],
            'dev_reviewing'  => [AgentRole::DEVELOPER, BacklogBoard::STAGE_REVIEWING, LaunchDecision::TYPE_REFUSE, 'attends le retour'],
            'dev_rejected'   => [AgentRole::DEVELOPER, BacklogBoard::STAGE_REJECTED, LaunchDecision::TYPE_PROMPT, 'rework'],
            'dev_approved'   => [AgentRole::DEVELOPER, BacklogBoard::STAGE_APPROVED, LaunchDecision::TYPE_LAUNCHER_HANDLED, ''],
            'dev_done'       => [AgentRole::DEVELOPER, 'unknown', LaunchDecision::TYPE_REFUSE, 'mergée'],

            // Reviewer × 7 stages
            'rev_todo'        => [AgentRole::REVIEWER, null, LaunchDecision::TYPE_REFUSE, 'Rien à reviewer'],
            'rev_development' => [AgentRole::REVIEWER, BacklogBoard::STAGE_IN_PROGRESS, LaunchDecision::TYPE_REFUSE, 'pas encore soumise'],
            'rev_review'      => [AgentRole::REVIEWER, BacklogBoard::STAGE_IN_REVIEW, LaunchDecision::TYPE_PROMPT, 'Démarre la review'],
            'rev_reviewing'   => [AgentRole::REVIEWER, BacklogBoard::STAGE_REVIEWING, LaunchDecision::TYPE_PROMPT, 'Reprends la review'],
            'rev_rejected'    => [AgentRole::REVIEWER, BacklogBoard::STAGE_REJECTED, LaunchDecision::TYPE_REFUSE, 'retravailler'],
            'rev_approved'    => [AgentRole::REVIEWER, BacklogBoard::STAGE_APPROVED, LaunchDecision::TYPE_REFUSE, 'user-merge'],
            'rev_done'        => [AgentRole::REVIEWER, 'unknown', LaunchDecision::TYPE_REFUSE, 'mergée'],
        ];
    }

    /**
     * Runs all test cases and returns the cumulative failure count.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testResolveStageDecisionTable();
        $failed += $this->testResolveBackwardCompatibility();
        $failed += $this->testResolveConflictPrompt();

        return $failed;
    }

    private function testResolveStageDecisionTable(): int
    {
        $failed = 0;
        $resolver = new AgentLaunchPromptResolver($this->promptPath);

        foreach ($this->table as $name => [$role, $stage, $expectType, $expectContains]) {
            $decision = $resolver->resolveStageDecision($role, $stage);

            if ($decision->isRefusal() && $expectType !== LaunchDecision::TYPE_REFUSE) {
                echo "FAIL {$name}: expected type {$expectType}, got refuse ('{$decision->getMessage()}')\n";
                $failed++;
                continue;
            }
            if ($decision->isLauncherHandled() && $expectType !== LaunchDecision::TYPE_LAUNCHER_HANDLED) {
                echo "FAIL {$name}: expected type {$expectType}, got launcher_handled\n";
                $failed++;
                continue;
            }
            if ($decision->isPrompt() && $expectType !== LaunchDecision::TYPE_PROMPT) {
                echo "FAIL {$name}: expected type {$expectType}, got prompt\n";
                $failed++;
                continue;
            }

            if ($expectContains !== '' && $expectType === LaunchDecision::TYPE_PROMPT) {
                $actual = $decision->getPrompt() ?? '';
                if (!str_contains(mb_strtolower($actual), mb_strtolower($expectContains))) {
                    echo "FAIL {$name}: prompt does not contain '{$expectContains}'. Got: {$actual}\n";
                    $failed++;
                    continue;
                }
            }

            if ($expectContains !== '' && $expectType === LaunchDecision::TYPE_REFUSE) {
                $actual = $decision->getMessage();
                if (!str_contains(mb_strtolower($actual), mb_strtolower($expectContains))) {
                    echo "FAIL {$name}: refusal message does not contain '{$expectContains}'. Got: {$actual}\n";
                    $failed++;
                    continue;
                }
            }

            echo "OK resolveStageDecision/{$name}\n";
        }

        return $failed;
    }

    private function testResolveBackwardCompatibility(): int
    {
        // resolve(AgentRole) must still return the YAML prompt string for backward compatibility.
        $resolver = new AgentLaunchPromptResolver($this->promptPath);

        $devPrompt = $resolver->resolve(AgentRole::DEVELOPER);
        if ($devPrompt === null || !str_contains($devPrompt, 'Démarre')) {
            echo "FAIL testResolveBackwardCompatibility: developer prompt missing or wrong. Got: " . var_export($devPrompt, true) . "\n";
            return 1;
        }

        $revPrompt = $resolver->resolve(AgentRole::REVIEWER);
        if ($revPrompt === null || !str_contains($revPrompt, 'Démarre la review')) {
            echo "FAIL testResolveBackwardCompatibility: reviewer prompt missing or wrong. Got: " . var_export($revPrompt, true) . "\n";
            return 1;
        }

        echo "OK testResolveBackwardCompatibility\n";
        return 0;
    }

    private function testResolveConflictPrompt(): int
    {
        $resolver = new AgentLaunchPromptResolver($this->promptPath);
        $prompt = $resolver->resolveConflictPrompt();

        if (!str_contains($prompt, 'conflit') && !str_contains($prompt, 'conflict')) {
            echo "FAIL testResolveConflictPrompt: conflict prompt does not mention conflict. Got: {$prompt}\n";
            return 1;
        }

        echo "OK testResolveConflictPrompt\n";
        return 0;
    }
}

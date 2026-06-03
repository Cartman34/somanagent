<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Model;

/**
 * Outcome of {@see \SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver::resolveStageDecision()}.
 *
 * Three mutually exclusive states:
 * - prompt   : launch the agent with an optional natural-language initial message
 * - refuse   : do not launch; output the message and return a non-zero exit code
 * - launcher_handled : the launcher must handle the transition (e.g. rebase) before deciding whether to launch
 */
final class LaunchDecision
{
    public const TYPE_PROMPT = 'prompt';
    public const TYPE_REFUSE = 'refuse';
    public const TYPE_LAUNCHER_HANDLED = 'launcher_handled';

    private string $type;

    private ?string $prompt;

    private ?string $message;

    private function __construct(string $type, ?string $prompt, ?string $message)
    {
        $this->type = $type;
        $this->prompt = $prompt;
        $this->message = $message;
    }

    /**
     * Launch the agent with an optional initial prompt.
     *
     * Pass null to launch silently (no initial message sent to the agent).
     */
    public static function prompt(?string $text): self
    {
        return new self(self::TYPE_PROMPT, $text, null);
    }

    /**
     * Refuse to launch the agent and surface the given message to the operator.
     */
    public static function refuse(string $message): self
    {
        return new self(self::TYPE_REFUSE, null, $message);
    }

    /**
     * Signal that the launcher must perform pre-launch work (e.g. rebase) before deciding.
     */
    public static function launcherHandled(): self
    {
        return new self(self::TYPE_LAUNCHER_HANDLED, null, null);
    }

    /**
     * @return bool
     */
    public function isRefusal(): bool
    {
        return $this->type === self::TYPE_REFUSE;
    }

    /**
     * @return bool
     */
    public function isLauncherHandled(): bool
    {
        return $this->type === self::TYPE_LAUNCHER_HANDLED;
    }

    /**
     * @return bool
     */
    public function isPrompt(): bool
    {
        return $this->type === self::TYPE_PROMPT;
    }

    /**
     * Returns the natural-language prompt to send to the agent, or null for a silent launch.
     *
     * Only meaningful when {@see isPrompt()} is true.
     *
     * @return ?string
     */
    public function getPrompt(): ?string
    {
        return $this->prompt;
    }

    /**
     * Returns the refusal message to show the operator.
     *
     * Only meaningful when {@see isRefusal()} is true.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message ?? '';
    }
}

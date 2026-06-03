<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Enum;

/**
 * Effective submit policy controlling whether the developer agent waits for a human `submit` or
 * runs `review-request` automatically after a successful `submit-check`.
 *
 * Resolved by combining `config.workflow.submit` from the board with the optional per-session
 * `--submit-mode` override. The fallback when both are absent or unreadable is `USER`.
 */
enum SubmitMode: string
{
    /**
     * Agent stops after submit-check and waits for the operator to send `submit`.
     */
    case USER = 'user';

    /**
     * Agent runs `review-request` immediately after a successful `submit-check`.
     */
    case AGENT = 'agent';
}

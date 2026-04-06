<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\LogEvent;
use App\Entity\LogOccurrence;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders human-readable log messages from structured log events and occurrences.
 */
final class LogMessageRenderer
{
    /**
     * Initializes the renderer with the translator used for persisted i18n metadata.
     */
    public function __construct(private readonly TranslatorInterface $translator) {}

    /**
     * Renders the translated title for a log entry, with fallback to the persisted legacy text.
     */
    public function renderTitle(LogEvent|LogOccurrence $entry): string
    {
        return $this->render(
            fallback: $entry->getTitle(),
            domain: $entry->getTitleDomain(),
            key: $entry->getTitleKey(),
            parameters: $entry->getTitleParameters(),
        );
    }

    /**
     * Renders the translated message for a log entry, with fallback to the persisted legacy text.
     */
    public function renderMessage(LogEvent|LogOccurrence $entry): string
    {
        return $this->render(
            fallback: $entry->getMessage(),
            domain: $entry->getMessageDomain(),
            key: $entry->getMessageKey(),
            parameters: $entry->getMessageParameters(),
        );
    }

    /**
     * @return array{
     *   titleDomain: ?string,
     *   titleKey: ?string,
     *   titleParameters: ?array<string, scalar|null>,
     *   messageDomain: ?string,
     *   messageKey: ?string,
     *   messageParameters: ?array<string, scalar|null>
     * }|null
     */
    public function buildI18n(LogEvent|LogOccurrence $entry): ?array
    {
        if ($entry->getTitleKey() === null && $entry->getMessageKey() === null) {
            return null;
        }

        return [
            'titleDomain' => $entry->getTitleDomain(),
            'titleKey' => $entry->getTitleKey(),
            'titleParameters' => $entry->getTitleParameters(),
            'messageDomain' => $entry->getMessageDomain(),
            'messageKey' => $entry->getMessageKey(),
            'messageParameters' => $entry->getMessageParameters(),
        ];
    }

    /**
     * Resolves a translated log fragment and falls back to the legacy persisted text when no i18n metadata exists.
     *
     * @param array<string, scalar|null>|null $parameters
     */
    public function render(string $fallback, ?string $domain, ?string $key, ?array $parameters): string
    {
        if ($domain === null || $key === null) {
            return $fallback;
        }

        return $this->translator->trans($key, $parameters ?? [], $domain);
    }
}

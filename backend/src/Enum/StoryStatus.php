<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum StoryStatus: string
{
    case New          = 'new';
    case Ready        = 'ready';
    case Approved     = 'approved';
    case Planning     = 'planning';
    case GraphicDesign = 'graphic_design';
    case Development  = 'development';
    case CodeReview   = 'code_review';
    case Done         = 'done';

    public function label(): string
    {
        return match($this) {
            self::New           => 'Nouvelle',
            self::Ready         => 'Prête',
            self::Approved      => 'Approuvée',
            self::Planning      => 'Planification',
            self::GraphicDesign => 'Conception graphique',
            self::Development   => 'Développement',
            self::CodeReview    => 'Revue de code',
            self::Done          => 'Terminée',
        };
    }

    /** Transitions autorisées depuis ce statut. */
    public function allowedTransitions(): array
    {
        return match($this) {
            self::New           => [self::Ready],
            self::Ready         => [self::Approved],
            self::Approved      => [self::Planning],
            self::Planning      => [self::GraphicDesign, self::Development],
            self::GraphicDesign => [self::Development],
            self::Development   => [self::CodeReview],
            self::CodeReview    => [self::Done, self::Development],
            self::Done          => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Transitions déclenchées automatiquement par un agent. */
    public function isAutomated(): bool
    {
        return match($this) {
            self::Ready, self::Planning, self::Development, self::CodeReview => true,
            default => false,
        };
    }
}

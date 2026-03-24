<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

enum StepInputSource: string
{
    case PreviousStep = 'previous_step'; // Sortie de l'étape précédente
    case VCS          = 'vcs';           // Données récupérées depuis GitHub/GitLab (diff, PR...)
    case Manual       = 'manual';        // Saisie manuelle au lancement
    case Context      = 'context';       // Contexte global du workflow
}

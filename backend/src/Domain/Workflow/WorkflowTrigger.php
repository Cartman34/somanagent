<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

enum WorkflowTrigger: string
{
    case Manual    = 'manual';     // Déclenché manuellement via l'UI
    case VcsEvent  = 'vcs_event'; // Déclenché par un webhook GitHub/GitLab
    case Scheduled = 'scheduled'; // Déclenché selon un planning
}

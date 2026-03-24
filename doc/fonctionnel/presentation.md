# Présentation de SoManAgent

> Voir aussi : [Concepts clés](concepts.md) · [Équipes et rôles](equipes-et-roles.md) · [Workflows](workflows.md)

## Qu'est-ce que SoManAgent ?

SoManAgent est une application web locale qui permet de **gérer des équipes d'agents IA** pour le développement de logiciels. Elle joue le rôle de chef d'orchestre entre :

- **Vous** (le développeur) — qui définissez les projets, les équipes et les tâches
- **Les agents IA** (Claude, etc.) — qui exécutent les tâches (coder, relire, tester…)
- **Vos outils** (GitHub/GitLab) — avec lesquels les agents interagissent

## Problème résolu

Développer un logiciel avec des agents IA soulève plusieurs questions pratiques :
- Comment organiser plusieurs agents avec des rôles différents ?
- Comment leur donner des instructions cohérentes et réutilisables ?
- Comment tracer ce qu'ils font ?
- Comment changer d'IA sans tout reconfigurer ?

SoManAgent répond à ces questions avec une interface structurée.

## Fonctionnement en un coup d'œil

```
Vous créez un Projet
       │
       ├── Modules (ex: api-php, app-mobile)
       │
       └── Vous assignez une Équipe
                   │
                   ├── Rôles (Tech Lead, Dev Backend, Reviewer…)
                   │         │
                   │         └── chacun a un Skill (instructions SKILL.md)
                   │
                   └── Agents (instances IA configurées)
                               │
                               └── connectés via Claude API ou Claude CLI
```

Vous lancez ensuite un **Workflow** : une suite d'étapes qui font travailler les agents
dans un ordre défini, avec les bons skills, sur le bon contexte.

## Exemple concret

> "Je veux une revue de code automatique à chaque Pull Request."

1. **Créer une équipe** "Web Dev Team" avec un rôle "Reviewer"
2. **Importer un skill** `code-reviewer` depuis skills.sh
3. **Assigner le skill** au rôle Reviewer
4. **Configurer un agent** Claude connecté via API
5. **Créer un workflow** "Revue PR" avec une étape qui envoie le diff au Reviewer
6. **Lancer le workflow** → l'agent analyse le diff et retourne ses commentaires

## Ce que SoManAgent n'est pas

- Ce n'est pas un environnement d'exécution de code (pas de CI/CD intégré)
- Ce n'est pas un outil de déploiement
- Ce n'est pas un service cloud — il tourne localement sur votre machine

## Interface

SoManAgent fournit :
- Une **interface web** (React) pour tout configurer visuellement
- Une **API REST** pour l'intégration avec d'autres outils
- Des **commandes Symfony** pour l'automatisation

→ Voir [Concepts clés](concepts.md) pour une définition précise de chaque terme.
→ Voir [Installation](../developpement/installation.md) pour démarrer.

# Troubleshooting

Quick local troubleshooting notes for common development issues.

Use `doc/README.md` to find broader documentation. This file is only a short recovery-oriented reference.

## Local URLs

- Frontend dev URL: `http://localhost:5173`
- API through Vite proxy: `/api/...`

## Claude Auth

- Sync auth: `php scripts/claude-auth.php sync`
- Re-auth: `php scripts/claude-auth.php login`
- Status: `php scripts/claude-auth.php status`

## Useful Checks

```bash
php backend/vendor/bin/phpunit --configuration backend/phpunit.dist.xml backend/tests/Unit/Service/AgentModelRecommendationPolicyResolverTest.php
php scripts/validate-backend-tests.php backend/src/Service/AgentModelRecommendationPolicyResolver.php
php scripts/validate-backend-tests.php --all
php scripts/console.php cache:clear
php scripts/node.php type-check
php scripts/toolkit/logs.php worker --tail 120
php scripts/db.php query "SELECT source, category, level, title, occurred_at FROM log_event ORDER BY occurred_at DESC LIMIT 20;"
```

## Git Hooks

The pre-commit hook (`scripts/githooks/pre-commit`) is activated once by running:

```bash
php scripts/scripts-install.php
```

This sets `core.hooksPath = scripts/githooks` in the WP git config. All linked worktrees (agent WAs) inherit it automatically — no per-WA setup needed. On a fresh checkout, re-run `scripts-install.php` if the hook is not firing.

## Local PHPUnit

- Dedicated backend service tests follow the mapping `backend/src/Service/...` -> `backend/tests/Unit/Service/...Test.php`
- Local WSL validation only covers isolated unit tests under `backend/tests/Unit/`
- Those tests must extend `Sowapps\SoManAgent\Tests\Support\LocalUnitTestCase`
- They must not boot Symfony, hit DB/Redis, or make a real external HTTP/API call
- Run one targeted local test with `php backend/vendor/bin/phpunit --configuration backend/phpunit.dist.xml <test-file>`
- Run the review-scope validator with `php scripts/validate-backend-tests.php <file> [file...]`
- Run the full local unit suite with `php scripts/validate-backend-tests.php --all`
- If `composer` ends with `cache:clear` failing on `DEFAULT_URI`, make sure `backend/.env.test` defines `DEFAULT_URI`

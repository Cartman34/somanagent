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
php scripts/console.php cache:clear
php scripts/node.php type-check
php scripts/logs.php worker --tail 120
php scripts/db.php query "SELECT source, category, level, title, occurred_at FROM log_event ORDER BY occurred_at DESC LIMIT 20;"
```

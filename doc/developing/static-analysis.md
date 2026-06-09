# Static analysis — SoManAgent specifics

The generic dead-code / PHPStan rules (the `unused-public` extension, `@api` annotation, how to fix a finding) live in the toolkit: [`scripts/toolkit/doc/developing/conventions/scripts.md`](../../scripts/toolkit/doc/developing/conventions/scripts.md#dead-code-detection).

## Backend baseline

`config/phpstan-baseline.neon` captures the pre-existing dead code in `backend/src/` that was present before the `unused-public` extension was introduced. New backend dead code is still caught. Cleaning the baseline entries is a separate follow-up task.

# Conventions — the hard rules

> **Moved.** These load-bearing rules are now the shipped Boost guideline (single source of truth):
> [`../resources/boost/guidelines/core.blade.php`](../resources/boost/guidelines/core.blade.php).
> Read that file. This pointer stays so `.ai/` remains a valid dev entry point; do not re-add the content here
> (avoid drift).

## Definition of done (package-dev — NOT shipped to consumers)
- Test-first (Pest). Coverage ≥ 80% on logic.
- `./vendor/bin/pint` clean · `./vendor/bin/phpstan analyse` clean.
- One commit per step-group; Conventional Commits; no attribution trailer.

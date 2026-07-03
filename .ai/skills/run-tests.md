# Skill — run the checks

Always run all three before considering a change done.

```bash
composer test      # Pest suite (Orchestra Testbench, SQLite :memory:)
composer lint      # Laravel Pint (code style) — add --test in CI to fail on drift
composer analyse   # Larastan / PHPStan level 6 (uses --memory-limit=1G)
```

Direct equivalents:
```bash
./vendor/bin/pest
./vendor/bin/pest --coverage --min=80        # needs Xdebug or PCOV
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=1G
```

## Test layout
- `tests/Unit/` — pure logic (exceptions, block types, renderer).
- `tests/Feature/` — services, models, media, publishing (default `TestCase`).
- New tests are RED-first; keep logic coverage ≥ 80%.

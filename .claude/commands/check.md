---
description: Run the dossiq quality suite (PHP strict gate, ESLint, Stylelint, l10n, manifest)
---

Run the quality suite from the repository root and report each failure with its file and line.

```bash
composer check:strict      # lint, refusal-grounds snapshot, phpcs, phpmd, psalm, phpstan, PHPUnit
npm run lint               # ESLint
npm run stylelint          # Stylelint
npm run test:l10n          # every t()/n() string has a catalogue entry
npm run check:manifest     # src/manifest.json validates against the app manifest schema
```

Fix what the change touched. PHPMD and PHPStan run without a baseline: fix violations at the source, never add a baseline or a suppression to get green. Auto-fixers: `composer cs:fix`, `composer phpcs:fix`, `npm run lint-fix`.

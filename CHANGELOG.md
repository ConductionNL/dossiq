# Changelog

All notable changes to Procest are documented in this file.

## [0.2.5] - 2026-06-01

### Changed

- Documentation site conformance to the canonical `@conduction/docusaurus-preset` product-pages structure (`docs-product-pages-conformance`):
  - Renamed `docs/tutorials/` to `docs/user-guide/` (history preserved via `git mv`).
  - Swept all em-dash characters (`—`) from `docs/` so the fleet-wide prose gate passes (`git grep -E '—' docs/` returns no matches).
  - Re-enabled the `nl` locale in `docs/docusaurus.config.js`; the production build succeeds with the Dutch locale active.
  - Verified the Redocusaurus `/api/` route, the canonical `Features/`, `user-guide/`, `Technical/` routes, and the `UseCases/`/`Integrations/` stubs all build cleanly.

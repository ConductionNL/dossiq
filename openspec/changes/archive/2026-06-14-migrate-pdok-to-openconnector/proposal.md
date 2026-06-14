# Migrate PDOK to openconnector

## Why

This change implements the `[procest]` subset of the Hydra-level umbrella change
`shared-pdok-via-openconnector`. Today, `src/services/pdokService.js` calls
`https://api.pdok.nl` directly from the browser, violating ADR-022. Centralizing
PDOK access in openconnector brings server-side caching, rate-limit handling, circuit
breaker, observability, and write-through to the OR `addresses` register — all at zero
cost to procest callers because the shim preserves identical exported function signatures.
The umbrella's full architecture, design rationale, and three-layer architecture live at
`hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.

## What

- Replace `src/services/pdokService.js` with a thin shim that exports the same six
  functions (`suggest`, `lookup`, `free`, `reverse`, `extractCoordinates`,
  `formatAddress`) — no procest caller requires modification.
- The first four functions delegate to
  `GET /index.php/apps/openconnector/api/pdok/{suggest|lookup|free|reverse}`.
- `extractCoordinates` and `formatAddress` remain pure utility functions with no
  network calls.
- 503 handling: shim resolves with `null` and surfaces the `message_key` to the caller.
- When openconnector is not installed (404), the shim surfaces an inline warning without
  breaking form submission.
- Updated unit tests: mocks changed from `api.pdok.nl` to `/index.php/apps/openconnector/api/pdok/*`.
- E2E smoke test confirming address autocomplete works end-to-end via the shim.
- Seed data bootstrap confirming OR fixtures are loadable in the test environment.

## Capabilities

### Modified Capabilities

- `pdok-consumer-via-openconnector`: procest's `src/services/pdokService.js` now routes
  address lookup/suggest/free/reverse calls through
  `/index.php/apps/openconnector/api/pdok/*` instead of calling `api.pdok.nl` directly,
  while preserving the existing caller surface.

## Affected Repos

procest only.

## References

- Umbrella spec:
  `hydra/openspec/changes/shared-pdok-via-openconnector/`
- Umbrella design (canonical architecture, shim contract):
  `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`
- openconnector PDOK adapter (the endpoint this shim targets):
  `openconnector/openspec/changes/add-pdok-adapter/`
  — openconnector MUST ship before procest's shim calls are fully functional;
  each task in this change describes only procest's contracts so the shim can be
  built and tests written without waiting for openconnector to deploy.
- OR addresses register:
  `openregister/openspec/changes/add-addresses-register/`
  — referenced for E2E fixture loading (OR fixtures used in integration test
  environment to avoid live PDOK dependency).

## Out of Scope

- The openconnector PDOK adapter — covered by sibling spec
  `openconnector/openspec/changes/add-pdok-adapter/`.
- The OR `addresses` register definition — covered by sibling spec
  `openregister/openspec/changes/add-addresses-register/`.
- procest's existing `pdok-integration` spec body — NOT modified by this change;
  a separate follow-up per-app spec will update it to reference the new consumer
  contract (see umbrella design, Phase 2).
- decidesk / zaakafhandelapp / pipelinq migration — separate per-app specs that
  reference the openconnector adapter once it ships.

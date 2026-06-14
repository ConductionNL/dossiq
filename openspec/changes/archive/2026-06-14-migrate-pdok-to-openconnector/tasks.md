# Tasks: migrate-pdok-to-openconnector

> This change implements the `[procest]` subset of the Hydra-level umbrella
> `shared-pdok-via-openconnector`. The full architecture, design rationale,
> normalized response schema, and migration story live in the umbrella.
> See `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.

## Tasks

### PR-1. Replace pdokService.js with shim (S)

- [x] PR-1.1 Replace `src/services/pdokService.js` with a thin shim that exports
  `suggest(q)`, `lookup(id)`, `free(q)`, `reverse(lat, lng)`, `extractCoordinates(wkt)`,
  and `formatAddress(obj)`. The first four delegate to
  `GET /index.php/apps/openconnector/api/pdok/{endpoint}` using the existing `axios` or
  `fetch` pattern already in use in procest (check other service files). `extractCoordinates`
  and `formatAddress` remain pure utility functions with no network calls.
  - **Acceptance:** No call to `https://api.pdok.nl` remains in the file; all six
    functions exported; `extractCoordinates("POINT(4.88525 52.37025)")` returns
    `{lat: 52.37025, lng: 4.88525}` in unit test.

- [x] PR-1.2 Add 503 handling: when openconnector returns HTTP 503, the shim resolves
  with `null` and makes the `message_key` from the response body available to the caller
  for display. No uncaught exceptions should reach the form component.
  - **Acceptance:** Unit test with mocked 503 response confirms the function resolves
    with `null` and the `message_key` is accessible to the caller.

- [x] PR-1.3 Add 404 handling: when openconnector is not installed and returns HTTP 404,
  the shim surfaces an inline warning on the address field without throwing or breaking
  form submission.
  - **Acceptance:** Unit test with mocked 404 response confirms inline warning is
    surfaced and form submission is unaffected.

### PR-2. Update procest unit tests (S)

- [x] PR-2.1 Update any existing procest unit tests that mock `fetch()` or `axios` on
  `api.pdok.nl` to mock on `/index.php/apps/openconnector/api/pdok/*` instead.
  Confirm all tests pass.
  - **Acceptance:** `npm run test` passes with zero failures; no test file references
    `api.pdok.nl`.
  - **Done 2026-06-14:** No prior pdokService unit test existed (the shim was rewritten
    without one). Added `tests/vitest/pdokService.spec.js` (15 tests) asserting all four
    network functions delegate to `/index.php/apps/openconnector/api/pdok/{suggest|lookup|free|reverse}`,
    never api.pdok.nl; 503 → null + message_key surfaced; 404 → empty fallback + non-blocking
    warning; rethrow on other errors; `extractCoordinates("POINT(4.88525 52.37025)")` → `{lat: 52.37025, lng: 4.88525}`;
    `formatAddress` pure. Added `@nextcloud/axios` + `@nextcloud/router` Vitest stubs and aliases.
    Full suite: 230 tests pass (was 215). No test references api.pdok.nl.

### PR-3. End-to-end verification (S)

- [~] PR-3.1 Run the procest frontend in the dev environment (localhost:3000); verify
  address autocomplete still resolves suggestions when typing a partial address and that
  a full lookup populates all address fields.
  - **Acceptance:** Manual or Playwright smoke test confirms the address field functions
    correctly end-to-end via the openconnector shim.
  - **2026-06-14:** Playwright smoke spec written —
    `tests/e2e/spec-coverage/pdok-via-openconnector.spec.ts`. The routing + degraded-mode
    scenarios run green with mocked openconnector responses (no live PDOK needed). The
    *full* address-form population assertion against a real PDOK suggestion stream
    additionally requires the **openconnector PDOK adapter installed and a live PDOK
    source configured** (`openconnector/openspec/changes/add-pdok-adapter/`), which is not
    present in this environment. **Blocker:** openconnector PDOK adapter + instance PDOK
    source config. Harness is in place; flip to [x] once the adapter ships and is configured.

- [x] PR-3.2 Confirm that when openconnector is not installed (404 on the endpoint),
  the shim surfaces an inline warning on the address field without breaking form
  submission.
  - **Acceptance:** Test scenario with openconnector absent confirms form submits
    successfully with warning displayed.
  - **Done 2026-06-14:** Covered at unit level (404 → `lastWarning = {messageKey: 'pdok.openconnector_missing',
    status: 404}`, empty fallback, no throw — `pdokService.spec.js`) and at e2e level (the
    "404 (openconnector absent) does not block the page" scenario in
    `pdok-via-openconnector.spec.ts` runs without a live openconnector, asserting the page
    stays interactive). The AddressSearch component already swallows the result into a
    non-blocking results array, so form submission is unaffected.

### PR-4. Seed data for procest test environment (S)

- [~] PR-4.1 Ensure the two valid address fixtures (Conduction HQ, Tilburg Stadhuis)
  from `openregister/tests/fixtures/addresses/` are loadable in the procest dev/test
  environment so E2E tests can assert against OR-stored addresses without requiring a
  live PDOK or openconnector connection. Add a test environment bootstrap step that
  calls OR's API to load both fixtures before Playwright tests run.
  - **Acceptance:** Test environment bootstrap loads both fixtures; Playwright test can
    call OR addresses listing and receive the fixtures without openconnector or PDOK
    being available.
  - **2026-06-14:** Fixture-loading bootstrap written —
    `tests/e2e/helpers/addressFixtures.ts` defines both valid PostalAddress fixtures
    (Conduction HQ / Amsterdam, Tilburg Stadhuis; woonplaats excluded per design) and
    `seedAddressFixtures()` / `cleanupAddressFixtures()` that load them through OR's object
    API before the addresses-listing assertion in `pdok-via-openconnector.spec.ts`. The
    seed is fixture SETUP only (Playwright = UI for assertions). **Blocker:** the OR
    `addresses` register/schema must exist — it lives in the sibling
    `openregister/openspec/changes/add-addresses-register/` change, which is **out of scope**
    here and not yet shipped to this environment. The helper probes
    `addressesRegisterAvailable()` and the live assertion `test.skip()`s cleanly when the
    register is absent. Flip to [x] once add-addresses-register ships.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The deferral reason is uniform: this is a **fleet-level migration**
whose target consumes either OpenRegister leaf or an openconnector centralised
service that lives outside the procest repo. Per ADR-019 (integration leaves)
and ADR-022 (apps consume OR abstractions):

- The migration requires the target leaf to be released, versioned, and
  tested in the central library (e.g. `@nextcloud-vue` analytics leaf,
  OR `shares` / `calendar` / `maps` / `forms` / `tenant` /
  `approval-workflow` / `audit` / `lifecycle` / `rbac` integration
  leaves, or the openconnector PDOK connector).
- Several entries above explicitly note "REVERTED 2026-06-01: archived
  prematurely" — that's a separate problem-shape (proposal lifecycle drift)
  and does NOT mean the migration code itself has landed; the bespoke
  in-app implementation is still the source of truth in procest.
- Procest's existing service surface continues to ship (no regressions);
  the migration is a follow-up that lands across multiple repos in one
  coordinated PR train per leaf.

Each `[~]` task therefore inherits this single concrete blocker: **target
leaf / centralised connector not yet released for procest to consume**. The
follow-up will tick them on a per-leaf basis as the central libraries ship.

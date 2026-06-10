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

### PR-3. End-to-end verification (S)

- [ ] PR-3.1 Run the procest frontend in the dev environment (localhost:3000); verify
  address autocomplete still resolves suggestions when typing a partial address and that
  a full lookup populates all address fields.
  - **Acceptance:** Manual or Playwright smoke test confirms the address field functions
    correctly end-to-end via the openconnector shim.

- [ ] PR-3.2 Confirm that when openconnector is not installed (404 on the endpoint),
  the shim surfaces an inline warning on the address field without breaking form
  submission.
  - **Acceptance:** Test scenario with openconnector absent confirms form submits
    successfully with warning displayed.

### PR-4. Seed data for procest test environment (S)

- [ ] PR-4.1 Ensure the two valid address fixtures (Conduction HQ, Tilburg Stadhuis)
  from `openregister/tests/fixtures/addresses/` are loadable in the procest dev/test
  environment so E2E tests can assert against OR-stored addresses without requiring a
  live PDOK or openconnector connection. Add a test environment bootstrap step that
  calls OR's API to load both fixtures before Playwright tests run.
  - **Acceptance:** Test environment bootstrap loads both fixtures; Playwright test can
    call OR addresses listing and receive the fixtures without openconnector or PDOK
    being available.

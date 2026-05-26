# Design: migrate-pdok-to-openconnector

> Cross-repo architecture, the canonical PostalAddress schema shape, the full
> caching and write-through flow, and the three-layer architecture all live in
> the umbrella spec:
> `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`
>
> This design document covers only procest-specific implementation details.

## Shim File Structure

The shim replaces `src/services/pdokService.js` in-place. The file structure:

```js
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2024 Conduction B.V.
// ...

const BASE_URL = '/index.php/apps/openconnector/api/pdok'

// Network-calling functions (delegate to openconnector)
export async function suggest(q) { ... }
export async function lookup(id) { ... }
export async function free(q, rows = 10, start = 0) { ... }
export async function reverse(lat, lng) { ... }

// Pure utility functions (no network calls)
export function extractCoordinates(wkt) { ... }
export function formatAddress(obj) { ... }
```

The first four functions use the same `axios` or `fetch` pattern already used elsewhere
in procest (check existing service files to confirm the pattern). All four catch HTTP
errors: 503 responses resolve with `null` and surface the `message_key` to the caller;
404 responses (openconnector not installed) surface an inline warning and allow the form
to continue.

## extractCoordinates Placement

`extractCoordinates(wkt)` stays in the shim. OR-sourced addresses already have parsed
GeoJSON `location` objects; callers receiving OR objects don't need it. But the function
must remain available for any procest caller passing raw WKT from a non-OR source.
Removing it would be a breaking change.

Contract: `extractCoordinates("POINT(4.88525 52.37025)")` returns
`{lat: 52.37025, lng: 4.88525}` — note that PDOK WKT has longitude first, and this
function swaps the order to return `{lat, lng}` for caller convenience.

## Test Approach

### Unit Tests (`npm run test`)

Existing unit tests that mock `fetch()` on `https://api.pdok.nl` are updated to mock
`fetch()` on `/index.php/apps/openconnector/api/pdok/*` instead. No new test framework
is introduced. Tests cover:
- `suggest`, `lookup`, `free`, `reverse` call the correct openconnector URL
- 503 response resolves with `null` and surfaces `message_key`
- 404 response (openconnector absent) surfaces inline warning, no exception
- `extractCoordinates("POINT(4.88525 52.37025)")` returns `{lat: 52.37025, lng: 4.88525}`
- `formatAddress` remains a pure function (no network calls)

### E2E Smoke Test

A Playwright or manual smoke test verifies that address autocomplete still works
end-to-end via the openconnector shim: typing a partial address returns suggestions,
selecting one populates all address fields. Run in the dev environment (localhost:3000)
with openconnector installed.

### openconnector-absent Test

Verify that when openconnector is not installed (404 on the endpoint), the shim
surfaces an inline warning on the address field without breaking form submission.

## Seed Data

No new OR schemas or registers are introduced by this change — those live in the
`openregister/openspec/changes/add-addresses-register/` sibling spec.

For the E2E and integration test environment, the two valid address fixtures from
`openregister/tests/fixtures/addresses/` (Conduction HQ, Tilburg Stadhuis) are loaded
into the test environment OR instance so E2E tests can assert against OR-stored
addresses without requiring a live PDOK or openconnector connection. The woonplaats
fixture is intentionally excluded from E2E seed (it lacks the fields required for
address form population).

The Playwright test environment bootstrap script loads both fixtures via OR's API before
running tests. This is a test-environment concern only — no seed data is added to the
procest app itself.

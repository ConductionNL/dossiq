---
status: done
---

# pdok-consumer Specification

## Purpose
Defines the dossiq-side contract for routing all PDOK Locatieserver access
through the openconnector PDOK adapter (`/index.php/apps/openconnector/api/pdok/*`)
instead of calling `api.pdok.nl` directly from the browser. dossiq's
`src/services/pdokService.js` is a thin shim that preserves the six existing
exports (`suggest`, `lookup`, `free`, `reverse`, `extractCoordinates`,
`formatAddress`) so no caller changes, delegates the four network functions to
openconnector, and degrades gracefully on HTTP 503 (PDOK unavailable) and HTTP 404
(openconnector absent). Per ADR-022 (apps consume OR/openconnector abstractions).

## Requirements
### Requirement: Frontend Shim Routes All PDOK Calls Through openconnector

`src/services/pdokService.js` MUST NOT contain any direct calls to `https://api.pdok.nl`.
The shim MUST export six functions — `suggest(q)`, `lookup(id)`, `free(q)`, `reverse(lat,
lng)`, `extractCoordinates(wkt)`, `formatAddress(obj)` — with identical signatures to
the current implementation. The first four MUST delegate to
`GET /index.php/apps/openconnector/api/pdok/{suggest|lookup|free|reverse}`. The last two
MUST remain pure utility functions with no network calls.

#### Scenario: suggest call reaches openconnector instead of api.pdok.nl

@e2e exclude no UI surface reaches the shim, so no browser journey can exercise it: `suggest()` is imported only by `src/components/map/AddressSearch.vue`, which is used only by `src/components/map/LocationPicker.vue`, which nothing in `src/` mounts. The bundle exports no global either, so a Playwright page cannot call the function. Verified instead by vitest `tests/vitest/pdokService.spec.js`, which calls the real `suggest()` and asserts both the openconnector URL and that no call reaches api.pdok.nl. Mutation checked 2026-09-11: pointing `suggest()` at api.pdok.nl reddens that test and only that test.

- GIVEN the dossiq frontend is loaded and openconnector is installed
- WHEN a dossiq component calls `suggest("Lauriergracht")`
- THEN the shim SHALL send
  `GET /index.php/apps/openconnector/api/pdok/suggest?q=Lauriergracht`
- AND SHALL return the normalized suggestion array to the caller
- AND SHALL NOT call `https://api.pdok.nl` directly

#### Scenario: extractCoordinates remains a pure utility function

@e2e exclude pure synchronous utility with no UI surface — verified by vitest tests/vitest/pdokService.spec.js

- GIVEN the shim is loaded
- WHEN a dossiq component calls `extractCoordinates("POINT(4.88525 52.37025)")`
- THEN the shim SHALL return `{lat: 52.37025, lng: 4.88525}`
- AND this function SHALL NOT make any network request

#### Scenario: No direct api.pdok.nl reference remains in the file

@e2e exclude static source-file assertion (grep), not a runtime UI behaviour — enforced by hydra forbidden-patterns and vitest routing tests

- GIVEN the shim file has been replaced
- WHEN the file content is inspected
- THEN no reference to `api.pdok.nl` SHALL be present anywhere in
  `src/services/pdokService.js`

### Requirement: Caller Signatures Are Preserved

All six exported function signatures MUST be identical to those of the replaced
`pdokService.js` — `suggest(q)`, `lookup(id)`, `free(q)`, `reverse(lat, lng)`,
`extractCoordinates(wkt)`, `formatAddress(obj)`. No dossiq component, view, or test
that currently imports from `pdokService.js` SHALL require modification as a result of
this change.

#### Scenario: All six functions are exported with unchanged signatures

@e2e exclude The scenario's own GIVEN is "the shim file is loaded in a test environment", and a browser is not one: the webpack bundle exports no global, so no page can inspect the module's exports. `tests/vitest/pdokService.spec.js` imports and calls `suggest`, `lookup`, `free` and `reverse` directly, which is what present-and-callable means. An e2e citation here previously resolved onto a test about OpenRegister address fixtures, which is unrelated.

- GIVEN the shim file is loaded in a test environment
- WHEN each of the six exports is inspected
- THEN all six SHALL be present and callable with their original signatures
- AND no existing dossiq caller SHALL need modification

#### Scenario: Existing dossiq tests pass unchanged against the shim

@e2e exclude test-runner outcome (npm run test), not a runtime UI behaviour — covered by the vitest suite

- GIVEN the shim is in place and `npm run test` is executed
- WHEN the test runner completes
- THEN all tests SHALL pass with zero failures
- AND no test file SHALL reference `api.pdok.nl`

### Requirement: Graceful Handling When openconnector Returns 503 or Is Absent

The shim MUST handle two degraded conditions without throwing uncaught exceptions or
breaking form submission:

1. **openconnector returns HTTP 503** (PDOK unavailable, circuit open): the affected
   function SHALL resolve with `null` and SHALL surface the `message_key` from the
   response body to the caller for display.
2. **openconnector not installed (HTTP 404)**: the shim SHALL surface an inline warning
   to the address field component and SHALL allow form submission to continue.

#### Scenario: 503 response resolves with null and surfaces message_key

@e2e exclude The subject is the shim function's return value, and no page can call it: `suggest()` and `lookup()` are imported only by `src/components/map/AddressSearch.vue`, used only by `LocationPicker.vue`, which nothing in `src/` mounts, and the bundle exports no global. Covered by `tests/vitest/pdokService.spec.js:169`, which calls the real `lookup()` against a mocked 503, asserts it resolves `null`, and asserts `lastWarning` equals `{ messageKey: 'pdok.unavailable', status: 503 }`. The e2e citation that stood here fetched the mocked URL and asserted the mock's own status, which no product change could redden.

- GIVEN openconnector returns HTTP 503 with
  `{"error": "pdok_unavailable", "message_key": "pdok.unavailable"}`
- WHEN the shim receives the 503 response on any network-calling function
- THEN the function SHALL resolve with `null`
- AND the `message_key` value `"pdok.unavailable"` SHALL be available to the caller
  for display
- AND no uncaught exception SHALL reach the form component

#### Scenario: openconnector absent surfaces warning without blocking form

@e2e exclude Same unreachable shim as the 503 scenario above. Covered by `tests/vitest/pdokService.spec.js:194`, which calls the real `free()` against a mocked 404 and asserts the empty fallback and the non-blocking warning. The e2e citation that stood here was credited while asserting only that a mocked 404 came back as 404.

- GIVEN openconnector is not installed and the endpoint returns HTTP 404
- WHEN a dossiq component calls `suggest("Tilburg")`
- THEN the shim SHALL surface an inline warning on the address field
- AND form submission SHALL remain possible (address field is non-blocking)


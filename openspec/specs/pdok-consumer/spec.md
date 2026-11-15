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

dossiq's address field SHALL be the library's CnAddressSearch, which calls PDOK only through the PDOK proxy app's `/api/pdok` routes (integriq, else openconnector) and never api.pdok.nl directly.

#### Scenario: suggest call reaches openconnector instead of api.pdok.nl

- **GIVEN** the case location dialog
- **WHEN** the handler types an address
- **THEN** the request goes to `/apps/<integriq|openconnector>/api/pdok/suggest` and no request leaves for api.pdok.nl

#### Scenario: extractCoordinates remains a pure utility function

- **GIVEN** the case location dialog with a list of suggestions
- **WHEN** the handler picks one
- **THEN** CnAddressSearch SHALL emit `select` with `{ result, coordinates, address }`, the payload AddressSearch emitted
- **AND** dossiq SHALL keep no coordinate helper of its own

#### Scenario: No direct api.pdok.nl reference remains in the file

- **GIVEN** dossiq's frontend source
- **WHEN** it is inspected
- **THEN** `src/services/pdokService.js` SHALL NOT exist
- **AND** no file under `src/` SHALL reference `api.pdok.nl`

### Requirement: Graceful Handling When openconnector Returns 503 or Is Absent

When the proxy answers 404 or 503, the address field SHALL say that address search is not available right now and SHALL stay usable, so the form around it can still be saved.

#### Scenario: 503 response resolves with null and surfaces message_key

- **GIVEN** the PDOK proxy answers HTTP 503
- **WHEN** the handler types an address
- **THEN** the field SHALL say address search is not available right now
- **AND** no uncaught exception SHALL reach the dialog, and it SHALL stay usable

#### Scenario: openconnector absent surfaces warning without blocking form

- **GIVEN** an instance without the PDOK proxy app
- **WHEN** the handler types an address
- **THEN** the field says address search is not available, and the dialog stays usable
- **AND** the form around it SHALL still save

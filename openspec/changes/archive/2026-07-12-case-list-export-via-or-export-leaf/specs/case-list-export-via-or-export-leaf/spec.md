# case-list-export-via-or-export-leaf

Behandelaren export the case list from the Cases page via the OpenRegister export leaf. Procest ships only a thin actions-slot component; serialization, filtering and access control live in openregister (ADR-022).

## ADDED Requirements

### Requirement: Export menu on the Cases page

The `Cases` index page SHALL render an "Export" actions menu (via `actionsComponent`) offering "Export as CSV" and "Export as Excel".

#### Scenario: Export menu visible

- **GIVEN** a user opens the Cases page
- **THEN** the page header shows an Export menu with CSV and Excel entries

@e2e exclude Requires a live OR-manifest-rendered Cases page; component rendering is asserted by vitest mount test, URL construction by unit test. Live-verify deferred to the deploy checklist because the shared dev instance serves a different checkout.

### Requirement: Export delegates to the OR export leaf

Choosing an export format SHALL navigate the browser to the OpenRegister export endpoint `/apps/openregister/api/objects/procest/case/export` with `format=csv` or `format=excel`, passing the current route query through as filter parameters. Procest SHALL NOT serialize CSV/Excel itself.

#### Scenario: CSV export URL

- **GIVEN** the Cases page is open with route query `?status=open`
- **WHEN** the user clicks "Export as CSV"
- **THEN** the browser requests `/apps/openregister/api/objects/procest/case/export?format=csv&status=open`
- **AND** the response is a `text/csv` download produced by openregister as the current user (OR pipeline enforces access)

@e2e exclude URL construction covered by vitest unit test; the endpoint itself is owned and tested by openregister.

#### Scenario: Excel export URL

- **GIVEN** the Cases page is open with no active filters
- **WHEN** the user clicks "Export as Excel"
- **THEN** the browser requests `/apps/openregister/api/objects/procest/case/export?format=excel`

@e2e exclude Same rationale as CSV scenario.

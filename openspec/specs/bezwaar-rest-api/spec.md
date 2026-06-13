# bezwaar-rest-api Specification

## Purpose
TBD - created by archiving change termijnbewaking-dwangsom-engine-10-bezwaar-rest-api. Update Purpose after archive.
## Requirements
### Requirement: Bezwaar-handling tegen dwangsom-beschikking (REQ-TERM-010)

The system SHALL freeze dwangsom accrual and payment when a bezwaar is filed (AWB 4:18) and SHALL adjust the amount and resume payment when the bezwaar is resolved.

#### Scenario: Bezwaar registration freezes payment

- **GIVEN** a closed `DwangsomBerekening` and a prepared `DwangsomUitbetaling`
- **WHEN** the handler registers a bezwaar against the dwangsom amount
- **THEN** the `DwangsomBerekening` SHALL remain `gestopt-wegens-beschikking` (frozen)
- **AND** `DwangsomUitbetaling.status` SHALL change to `on-hold-bezwaar` and payment SHALL be suspended
- **AND** a `bezwaar-ingediend` event SHALL be recorded and the burger SHALL receive a suspension confirmation

#### Scenario: Bezwaar resolution adjusts the amount and resumes payment

- **GIVEN** a bezwaar is upheld with a revised dwangsom-bedrag
- **WHEN** the handler registers the beslissing-op-bezwaar with the new amount
- **THEN** `DwangsomBerekening.definitievBedrag` and `DwangsomUitbetaling.bedrag` SHALL be updated to the new amount
- **AND** `DwangsomUitbetaling.status` SHALL change back to `voorbereid` and a new payment signal SHALL be emitted
- **AND** the burger SHALL be notified of the revised amount

### Requirement: Termijn and dwangsom REST API (REQ-TERM-API-001)

The system SHALL expose authorization-checked REST endpoints for termijn, ingebrekestelling, dwangsom, and reporting operations, registered via `appinfo/routes.php`.

#### Scenario: Endpoints declare auth and validate input

- **GIVEN** the termijn/ingebrekestelling/dwangsom/reporting controllers
- **WHEN** an endpoint is invoked
- **THEN** the endpoint SHALL declare an explicit Nextcloud auth posture and perform a per-object/role authorization check (admin for config, handler for case ops, accountant for reports)
- **AND** input SHALL be validated and errors returned with appropriate HTTP status (400/401/403/404/409)

#### Scenario: Unauthorized case access is rejected

- **GIVEN** a handler without access to a given case
- **WHEN** they call a case-scoped dwangsom or termijn endpoint for that case
- **THEN** the system SHALL reject the request with HTTP 403 and perform no state change


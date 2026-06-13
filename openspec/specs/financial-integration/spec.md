---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# financial-integration Specification

## Purpose
TBD - created by archiving change termijnbewaking-dwangsom-engine-07-financial-integration. Update Purpose after archive.
## Requirements
### Requirement: Uitbetaling-signaal aan financieel systeem (REQ-TERM-007)

The system SHALL prepare an ERP-ready payment signal with all required metadata via openconnector and SHALL process the ERP payment-confirmation callback.

#### Scenario: Payment signal generation

- **GIVEN** a `DwangsomBerekening` closes with a locked `definitievBedrag` and the burger's IBAN is known
- **WHEN** the payment signal is generated
- **THEN** a `DwangsomUitbetaling` SHALL be created with `bedrag`, `rekeninghouderNaam`, `iban`, `referentie` (zaakId + ingebrekestelling-date), `wettelijkeGrondslag` = "AWB 4:17 lid 2", `betaaldatumUiterlijk` = ingebrekestelling-date + 28 days, and `status` = `voorbereid`
- **AND** a `dwangsom-payment-signal` event SHALL be emitted to openconnector with the full metadata payload

#### Scenario: Missing or invalid IBAN blocks the signal

- **GIVEN** the burger's IBAN is missing or malformed
- **WHEN** `prepareBetaling` runs
- **THEN** the system SHALL raise an error and SHALL NOT emit a payment signal

#### Scenario: Payment confirmation callback updates status and notifies burger

- **GIVEN** the ERP sends a payment-confirmation callback via openconnector
- **WHEN** the signed callback arrives with `{referentie, status: betaald, werkelijkeBetaaldatum, betalingsreferentie}`
- **THEN** the callback signature SHALL be validated and the `DwangsomUitbetaling` SHALL be looked up by `referentie`
- **AND** its `status` SHALL be set to `betaald` with `werkelijkeBetaaldatum` and `betalingsreferentie` recorded
- **AND** a `dwangsom-betaald` event SHALL be emitted and a burger payment notification SHALL be triggered

#### Scenario: Unknown referentie is rejected

- **GIVEN** a callback arrives with a `referentie` that matches no `DwangsomUitbetaling`
- **WHEN** the callback is processed
- **THEN** the system SHALL return HTTP 404 with no side effects


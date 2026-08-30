# app-metadata-claims Specification

## Purpose

@e2e exclude Metadata-only capability (info.xml, README, features overlay) — no UI surface; verified via app-info.xsd validation, README link checker, and JSON validation.

Dossiq's licence metadata, README feature claims, documentation links, platform matrix, and
feature-maturity statuses tell the truth about the code as shipped.

**OpenSpec changes**: [align-claims-and-licence](../../changes/archive/2026-07-06-align-claims-and-licence/) _(archived 2026-07-06)_

## Requirements

### Requirement: Licence statements MUST be consistent with the LICENSE file

All licence statements (info.xml descriptions, README badge and §License) SHALL state EUPL-1.2,
matching the `LICENSE` file. The info.xml `<licence>` element SHALL be `EUPL-1.2` — the SPDX
token Nextcloud's app-info.xsd enum accepts since the 2026-05-07 upstream addition
(nextcloud/server PR #60212; also in the App Store's accepted-licenses fixtures) — per the
product-owner decision of 2026-07-05. Only when the targeted Nextcloud version ships an
app-info.xsd that predates the EUPL enum value MAY the element fall back to `agpl` accompanied
by a comment stating the actual licence.

#### Scenario: No AGPL claim remains in prose

- **WHEN** info.xml descriptions and README are inspected
- **THEN** no free-text statement MUST claim the app is AGPL-licensed
- **AND** the README badge MUST read EUPL-1.2

#### Scenario: info.xml stays schema-valid

- **WHEN** the app metadata is validated against a Nextcloud app-info.xsd carrying the EUPL enum
  value (upstream master, stable31+ branch heads, tagged releases from v33.0.5)
- **THEN** validation MUST pass with `<licence>EUPL-1.2</licence>`

### Requirement: README claims MUST be backed by code or marked roadmap

The README SHALL NOT present unimplemented capabilities as shipped: Unified Search is attributed
to OpenRegister ("provided centrally via OpenRegister"), DMN is removed or marked roadmap, and the
Pipelinq Bridge points at the `semantic-case-intake` change as roadmap until it lands. All README
documentation links SHALL resolve, and the platform matrix SHALL match `appinfo/info.xml`
(Nextcloud 28–34, PHP 8.3+).

#### Scenario: Feature list matches reality

- **WHEN** the README feature and standards sections are reviewed against `lib/` and `src/`
- **THEN** every capability listed as shipped MUST have implementing code
- **AND** roadmap items MUST be visibly marked as such

#### Scenario: No dead documentation links

- **WHEN** each `docs/…` link in the README is followed
- **THEN** the target file MUST exist in the repository

### Requirement: Feature-overlay statuses MUST reflect operational maturity

`openspec/features.overlay.json` SHALL grade `archief-edepot-handover` and `multi-tenancy` as
`beta`, each with a one-line reason, until a real e-Depot submission path exists and the tenant
stack sits on the OpenRegister boundary respectively.

#### Scenario: Mocked pipeline is not advertised stable

- **GIVEN** e-Depot submission is bound to a log adapter
- **WHEN** the features overlay is read
- **THEN** "Archivering naar e-Depot" MUST be `beta` with the mock-adapter reason
- **AND** "Multi-tenant SaaS" MUST be `beta` with the OR-boundary reason

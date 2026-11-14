# tenant-mandate-table Specification

## Purpose
What dossiq does for tenant mandate table. The requirements were first written by the change `tenant-mandate-table` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-tenant-mandate-table/`) and cover: The tenant page shows its mandates per role.

## Requirements

### Requirement: The tenant page shows its mandates per role

A tenant mandate SHALL carry its terms: a ceiling in euros (empty is unlimited), the decision types it covers and an enforcement level. The tenant page's mandate card SHALL show, per mandate, the role, the ceiling ("Onbeperkt" when empty), whether it decides objections and its enforcement level.

#### Scenario: Waterland's mandates
- **GIVEN** an Afdelingshoofd mandate with no ceiling, objections among its decision types and full enforcement
- **WHEN** an administrator opens the tenant
- **THEN** the card SHALL read Afdelingshoofd, Onbeperkt, Ja, Ja

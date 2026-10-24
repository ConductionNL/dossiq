# ui-facing-names Delta

**Status**: proposed
**Scope**: dossiq

## Purpose

What the UI calls and names says what it does, and nobody who used the earlier name loses anything.

## ADDED Requirements

### Requirement: A renamed address keeps its old address

Every URL the UI called under a procedure name SHALL be served at a generic URL. The old URL SHALL stay routed to the
same behaviour with the same authorization, until the next major release. The payment callback's old URL SHALL stay
routed permanently.

#### Scenario: An old address answers as the new one

- **GIVEN** a caller of `GET /api/termijn/reports/kwartaal?periode=2026-Q1`
- **WHEN** it calls the old address
- **THEN** it gets the same status and body as `GET /api/deadlines/reports/quarterly?period=2026-Q1`

#### Scenario: A bookmarked page address lands on the renamed page

- **GIVEN** a bookmark of `/apps/dossiq/woo-reports`
- **WHEN** a user opens it
- **THEN** the browser lands on `/apps/dossiq/document-review-reports`

### Requirement: Stored names follow a rename

A report key, series key, setting key or layout id that is stored or read by someone else SHALL be renamed without loss:
reports carry both keys for one release, series are published under both keys for one release, settings are read under
the new key with a fallback to the old one, and a repair step rewrites stored settings and stored buildiq arrangements.
The repair steps SHALL change nothing on a second run and never overwrite a value written under the new name.

#### Scenario: An arranged widget keeps its place

- **GIVEN** an administrator placed `td-quarterly` on the deadline page in buildiq
- **WHEN** the upgrade runs
- **THEN** the stored arrangement names `deadline-report-quarterly`, and a second run writes nothing

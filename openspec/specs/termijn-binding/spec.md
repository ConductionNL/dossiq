---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijn-binding Specification

## Purpose
Binds every zaak to a matching TermijnDefinitie and auto-creates its TermijnInstance on case creation, calculating the legal deadline from the configured duration and recording a start event. Case creation is blocked when no definition exists for the zaaktype, and definition versioning never retroactively changes deadlines on existing instances.
## Requirements
### Requirement: Termijn-binding per zaaktype (REQ-TERM-001)

The system SHALL require every zaak to have a matching `TermijnDefinitie` and SHALL auto-create a `TermijnInstance` on zaak-creation; explicit configuration SHALL prevent silent deadline-handling failures.

#### Scenario: Zaak-creation blocked when no TermijnDefinitie exists

- **GIVEN** a gemeente has configured TermijnDefinities for "Omgevingsvergunning-regulier" and "Wmo-aanvraag" but NOT for "Horeca-exploitatievergunning"
- **WHEN** a new "Horeca-exploitatievergunning" zaak is created
- **THEN** the system SHALL block zaak-creation with an admin-facing error directing the administrator to configure a `TermijnDefinitie` before creating cases of this type

#### Scenario: Auto-create TermijnInstance on zaak-creation

- **GIVEN** a zaak of type "Omgevingsvergunning-regulier" (56 days) is registered
- **WHEN** the `TermijnInstance` is auto-created
- **THEN** `einddatumBerekend` SHALL be set to `startDatum + standaardDuurDagen`
- **AND** `status` SHALL be `lopend`
- **AND** a `TermijnGebeurtenis` of type `start` SHALL be recorded with `tijdstip` = zaak-creation time and `grondslag` = "AWB 4:13"

#### Scenario: TermijnDefinitie versioning does not affect existing instances

- **GIVEN** a `TermijnDefinitie` is updated (e.g. duration 56 → 70 days)
- **WHEN** the change takes effect
- **THEN** new cases created after the change SHALL use the new duration
- **AND** existing `TermijnInstance` rows SHALL retain their original `einddatumBerekend` with no retroactive change
- **AND** if the `TermijnDefinitie` is marked `validUntil = today`, new cases of that zaaktype SHALL NOT be created while existing ones continue


# Spec delta: vth-config-foundation

## ADDED Requirements

### Requirement: VTH workflow template declarations

The system SHALL provide declarative workflow template JSON files for the three VTH case kinds (Omgevingsvergunning, Toezichtzaak, Handhavingszaak), each a valid OpenAPI 3.0 document with the `x-openregister` extension, defining status definitions, role definitions, document-type requirements, and transitions with guards.

**Spec ref**: REQ-VTH-001, REQ-VTH-002, REQ-VTH-003

#### Scenario: Omgevingsvergunning template declared

- **WHEN** the VTH config repair step runs
- **THEN** an Omgevingsvergunning workflow template SHALL be registered with statuses (Aanvraag ontvangen, In behandeling, Advies aangevraagd, Beschikking opgesteld, Verzonden, Verleend, Geweigerd, Ingetrokken, Afgehandeld), roles (Vergunningverlener, Juridisch adviseur, Administratief medewerker), and document-type requirements (Aanvraagformulier, Tekeningen, Beschikking)

#### Scenario: Toezichtzaak and Handhavingszaak templates declared

- **WHEN** the VTH config repair step runs
- **THEN** a Toezichtzaak template (statuses Geplande inspectie … Afgerond; roles Inspector, Coördinator toezicht, Rapporteur) and a Handhavingszaak template (statuses Onderzoek … Afgerond; roles Handhaver, Manager handhaving, Juridisch adviseur) SHALL be registered

### Requirement: LHSO matrix seed data

The system SHALL seed the complete 16-cell LHSO matrix (Gedrag A–D × Gevolgen 1–4) via an idempotent repair step, each cell carrying gedrag, gevolgen, interventieStep, and description.

**Spec ref**: REQ-VTH-003

#### Scenario: Sixteen LHSO cells loaded on install

- **WHEN** the Procest app is installed or upgraded
- **THEN** all 16 LHSO matrix cells SHALL be queryable, each with a suggested intervention (e.g. Advies, Bestuurlijke waarschuwing, Aanzegging, Dwangsom)

#### Scenario: LHSO seed is idempotent

- **WHEN** the LHSO repair step runs a second time
- **THEN** no duplicate matrix cells SHALL be created

### Requirement: VTH seed cases and master config repair step

The system SHALL provide 9 realistic Dutch seed cases (3 per VTH kind) and a master repair step that loads workflow templates, leges rule sets, beschikking templates, inspection checklists, the LHSO matrix, and the seed cases in dependency order, idempotently.

**Spec ref**: REQ-VTH-001, REQ-VTH-003, REQ-VTH-004

#### Scenario: Seed cases materialised and queryable

- **WHEN** the master VTH config repair step runs on a fresh install
- **THEN** 9 seed cases (Omgevingsvergunning, Toezichtzaak, Handhavingszaak) SHALL exist as structured OpenRegister data, queryable by zaaktype and status

#### Scenario: Master repair step is idempotent

- **WHEN** the master VTH config repair step runs twice
- **THEN** templates, LHSO cells, and seed cases SHALL NOT be duplicated, and templates SHALL be loaded before seed cases that reference them

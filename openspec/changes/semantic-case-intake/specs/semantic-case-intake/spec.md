---
status: proposed
---

# Spec: semantic-case-intake

**Status:** proposed
**Scope:** procest (consume-side of the `ns#Case` handoff)
**Depends on:** hydra change `semantic-object-handoff` (ADR-051) — `x-openregister-handoff` dialect; OpenRegister `SemanticTypeResolver` (shipped on origin/development); ADR-048 semantic references; ADR-031 notification dialect
**Backs:** README "Pipelinq Bridge" feature claim

## Purpose

Procest provides the semantic kind `https://openregister.app/ns#Case`: pipelinq requests handed
off to that kind are created as procest cases with faithful field mapping and navigable
provenance, surfaced in the intake Werkvoorraad, and announced via declarative notifications.

## ADDED Requirements

### Requirement: The zaak schema MUST implement the canonical Case kind

Procest's `case` schema SHALL declare `implements: ["https://openregister.app/ns#Case"]` so
OpenRegister's `SemanticTypeResolver` resolves procest as a `ns#Case` provider. Discovery SHALL
degrade gracefully per ADR-048: when procest is not installed, the handoff resolves to no provider
and pipelinq keeps functioning standalone.

#### Scenario: Resolver discovers procest as Case provider

- **GIVEN** procest is installed and its register configuration is imported
- **WHEN** OpenRegister resolves providers for `https://openregister.app/ns#Case`
- **THEN** procest's case schema MUST be returned as a provider

#### Scenario: Absent provider degrades gracefully

- **GIVEN** procest is not installed
- **WHEN** pipelinq initiates a handoff to `ns#Case`
- **THEN** resolution MUST report no provider without error cascade in pipelinq (ADR-048 semantics)

### Requirement: Handoff-created cases MUST map the contract faithfully

A case created via the `x-openregister-handoff` dialect SHALL map: contract title → `title`,
summary → `description`, channel → `intakeChannel`, priority → `priority`, requester → an ADR-048
semantic reference on the case (single write path shared with the initiator fields introduced by
`brp-kvk-register-sets`), and provenance → a semantic reference back to the originating pipelinq
request. Procest SHALL NOT expose an app-local creation endpoint for the handoff (creation flows
through OpenRegister).

#### Scenario: Pipelinq request becomes a procest case

- **GIVEN** a pipelinq request with title, summary, requester, channel, and priority
- **WHEN** it is handed off to `ns#Case` and procest is the resolved provider
- **THEN** a procest case MUST exist with the mapped title, description, intakeChannel, and priority
- **AND** the case's requester MUST be a semantic reference resolving to the original requester object
- **AND** the case MUST carry a provenance reference to the source pipelinq request

#### Scenario: Provenance is navigable both ways

- **GIVEN** a handoff-created case
- **WHEN** a user follows the case's provenance reference
- **THEN** it MUST resolve to the originating pipelinq request (when pipelinq is installed)
- **AND** the relation MUST be UUID-based per OR relation rules

### Requirement: Handoff intake MUST be visible with provenance in the Werkvoorraad

Handoff-created cases SHALL appear in the intake Werkvoorraad (`src/views/Werkvoorraad.vue`) like
any new case, and their intake card / case detail SHALL show provenance: origin app, link to the
source request, and handoff timestamp. Notification of the new intake SHALL be declared via the
`x-openregister-notifications` dialect on the case schema (ADR-031); procest SHALL NOT dispatch
imperative object notifications for it.

#### Scenario: Behandelaar sees the handoff case with origin

- **GIVEN** a case was just created via handoff
- **WHEN** a behandelaar opens the Werkvoorraad
- **THEN** the case MUST be listed in intake
- **AND** its provenance (origin "pipelinq", source link, handoff timestamp) MUST be visible on the intake card or case detail

#### Scenario: Intake notification is declarative

- **WHEN** a handoff-created case lands
- **THEN** the notification MUST originate from the schema's `x-openregister-notifications` declaration
- **AND** no procest PHP code MUST imperatively dispatch the intake notification

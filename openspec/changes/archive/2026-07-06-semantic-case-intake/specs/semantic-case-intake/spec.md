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

@e2e exclude Provider discovery runs inside OpenRegister's SemanticTypeResolver + complete-binding filter (backend, cross-app); procest's contribution is the implements + handoffContract declaration, proven by PHPUnit (SemanticCaseIntakeTest) against the REAL HandoffKindContracts ns#Case set. No procest browser surface.

- **GIVEN** procest is installed and its register configuration is imported
- **WHEN** OpenRegister resolves providers for `https://openregister.app/ns#Case`
- **THEN** procest's case schema MUST be returned as a provider

#### Scenario: Absent provider degrades gracefully

@e2e exclude ADR-048 graceful-degrade is OpenRegister/pipelinq behaviour (no procest provider present); nothing to drive in a procest UI. Covered by OR's handoff engine tests.

- **GIVEN** procest is not installed
- **WHEN** pipelinq initiates a handoff to `ns#Case`
- **THEN** resolution MUST report no provider without error cascade in pipelinq (ADR-048 semantics)

### Requirement: Handoff-created cases MUST map the contract faithfully

A case created via the `x-openregister-handoff` dialect SHALL bind the REAL OpenRegister ns#Case
contract (per `HandoffKindContracts`: mandatory `title`, `summary`, `channel`, `source`; optional
`requester`, `priority`) through the case schema's `handoffContract` block: contract `title` →
`title`, `summary` → `description`, `channel` → `intakeChannel`, `priority` → `priority`,
`requester` → the ADR-048 `requester` semantic reference on the case (single write path shared with
the initiator display fields from `brp-kvk-register-sets`), and the mandatory `source` provenance
field → the `handoffSource` semantic reference back to the originating request. (DC02 correction:
the landed contract names the provenance field `source` and makes it mandatory; the 2026-07-05
brief's `provenance` is that `source` field.) Procest SHALL NOT expose an app-local creation
endpoint for the handoff (creation flows through OpenRegister).

#### Scenario: Pipelinq request becomes a procest case

@e2e exclude End-to-end handoff execution spans pipelinq's produce-side + OpenRegister's HandoffService (target create, binding translation, provenance relations) — a cross-app backend flow with no procest-only browser path. The faithful, complete binding of the mapped fields is proven by PHPUnit (SemanticCaseIntakeTest) against the real contract.

- **GIVEN** a pipelinq request with title, summary, requester, channel, and priority
- **WHEN** it is handed off to `ns#Case` and procest is the resolved provider
- **THEN** a procest case MUST exist with the mapped title, description, intakeChannel, and priority
- **AND** the case's requester MUST be a semantic reference resolving to the original requester object
- **AND** the case MUST carry a provenance reference to the source pipelinq request

#### Scenario: Provenance is navigable both ways

@e2e exclude The bidirectional relation is written by OpenRegister's HandoffService (`handoff:<id>:handed-off-to` on the source, `handed-off-from` on the target) and needs a real cross-app handoff; procest's forward link (case → source via handoffSource) is rendered in the detail provenance UI, covered by the detail e2e test. The reverse OR relation is OR-owned.

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

@e2e exclude The notification fires from OpenRegister's AnnotationNotificationDispatcher reading the schema's x-openregister-notifications rule (created + notIn filter on handoffSource); PHPUnit proves the declaration is present and that lib/ contains no imperative handoff-intake dispatch. No procest UI to drive.

- **WHEN** a handoff-created case lands
- **THEN** the notification MUST originate from the schema's `x-openregister-notifications` declaration
- **AND** no procest PHP code MUST imperatively dispatch the intake notification

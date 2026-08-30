# avg-verwerkingenlogging Specification (dossiq thin consumer)

**Scope:** dossiq (thin consumer)
**Depends on:** OpenRegister >= 0.2.16 verwerkingenlogging engine (OR-PA-1..9: `x-openregister-processing` dialect, `/api/avg/verwerkingen*` + verwerkingsactiviteiten endpoints); case-management; zgw-autorisaties-api (client identity adjacency)
**Standard:** VNG Logging Verwerkingen; AVG art. 5 lid 2, art. 6, art. 15, art. 30

## Purpose

Dossiq contributes its zaakgericht-werken processing-activity catalogue, read-logging opt-ins,
attribution mappings, and FG UI surfacing to OpenRegister's platform verwerkingenlogging
capability. All storage, logging, retention, export, and API mechanics are owned by OpenRegister
per the 2026-06-11 abstraction decision; dossiq implements no log engine of its own.

**OpenSpec changes**: [avg-verwerkingenlogging](../../changes/archive/2026-07-06-avg-verwerkingenlogging/) _(archived 2026-07-06)_

## Requirements

### Requirement: Dossiq MUST declare its processing-activity catalogue declaratively

Dossiq SHALL ship its verwerkingsactiviteiten as `x-openregister-processing` annotations in its register configuration — per activity: name, purpose (doel), AVG art. 6 legal basis with statutory reference, data-subject categories, recipients, and retention period — seeded as drafts for FG review per OR-PA-2. Case-type attribution SHALL be expressed in the same annotations; dossiq SHALL NOT maintain a parallel activity store.

> Implementation note (2026-07-06): OR has no annotation-driven activity seeding yet, so the
> catalogue lives declaratively in `lib/Settings/verwerkingsactiviteiten.json` and the
> `SeedVerwerkingsactiviteiten` repair step upserts it by code (draft, FG status preserved)
> via OR's `VerwerkingsactiviteitMapper`. Per-case-type attribution awaits value-based
> attribution in OR's dialect; case reads attribute to the `zaakafhandeling` umbrella activity.

#### Scenario: Catalogue import seeds draft activities

@e2e exclude Seeding runs in a repair step against OR's mapper; covered by PHPUnit (SeedVerwerkingsactiviteitenTest: fresh-run drafts, upsert-by-code, FG-status preservation) — no browser surface.

- **GIVEN** dossiq's register configuration is imported into OpenRegister
- **WHEN** the import completes
- **THEN** each annotated verwerkingsactiviteit MUST exist in the OR processing-activity register as a draft
- **AND** activating it (FG action in OR) MUST make it attributable to dossiq case types

#### Scenario: Legal basis is constrained at the source

@e2e exclude The vocabulary gate is OR's mapper validation (backend); PHPUnit proves the shipped catalogue's rechtsgronden are inside OR's art. 6 vocabulary so the seed can never be rejected — no browser surface.

- **WHEN** a catalogue annotation declares a legal basis outside the six AVG art. 6 grounds
- **THEN** the OR import MUST reject the annotation with a validation error (OR-PA-2)
- **AND** the dossiq configuration MUST be corrected rather than bypassing validation

### Requirement: Person-bearing schemas MUST opt into read logging with attribution

Dossiq SHALL enable `logReads` on its person-bearing schemas (case, betrokkene/rol, klantcontact) so OpenRegister logs reads (OR-PA-3), and SHALL map each case type to its default activity via the catalogue annotations. ZGW API client identity SHALL reach OR's log context so machine access is attributed per client; processing without a mapping lands in OR's flagged fallback (OR-PA-4).

#### Scenario: Case read is logged by the platform with dossiq attribution

@e2e exclude Log emission executes inside OpenRegister's ProcessingLogService (buffered, fail-soft) and is e2e-tested in the OR repo; dossiq's contribution is the logReads/attribution annotation, proven by PHPUnit (testPersonBearingSchemasOptIntoReadLogging, testRegisterAttributionReferencesResolveToCatalogue).

- **GIVEN** the case type "Omgevingsvergunning" is mapped to "Behandelen omgevingsvergunning"
- **WHEN** a handler opens a BSN-bearing case of that type
- **THEN** OpenRegister MUST record a read processing-log entry attributed to that activity
- **AND** dossiq MUST NOT write any log entry itself

#### Scenario: ZGW client access carries client identity

@e2e exclude OR-side gap (DC02, recorded 2026-07-06): OR's ProcessingLogService derives the actor from IUserSession and no OR read path forwards an explicit ZGW client actor yet — the gap belongs on the OR change (openregister/processing-activity-register), not in dossiq controllers; no dossiq surface to e2e-test.

- **GIVEN** an external system reads a zaak via the ZRC endpoint with a bearer token
- **WHEN** OR records the processing-log entry
- **THEN** the entry's performer MUST reflect the resolved ZGW client identity (not a generic system user)

#### Scenario: Unmapped case type surfaces as unclassified

@e2e exclude Fallback attribution executes inside OpenRegister (OR-PA-4) and needs engine-generated log data; the dossiq counter rendering is exercised by the FG-overview e2e test and the unwrap logic by vitest (verwerkingenApi.spec.js).

- **WHEN** processing occurs for a case type without an activity mapping
- **THEN** OR MUST attribute it to the flagged fallback (OR-PA-4)
- **AND** the dossiq FG view MUST show the unclassified count for dossiq's registers

### Requirement: Dossiq MUST surface the FG inquiry and inzageverzoek entry points

Dossiq SHALL provide an FG/admin view scoped to its register slice (OR-PA-8): catalogue review status, the unclassified-processing counter, and a per-betrokkene inzageverzoek export entry point that delegates to OR's export (OR-PA-7). Access SHALL follow OR's FG delegation; non-FG users are denied.

#### Scenario: FG opens the dossiq verwerkingen overview

- **GIVEN** a user holding the FG delegation in OpenRegister
- **WHEN** they open dossiq's Verwerkingen view
- **THEN** they MUST see dossiq's activity catalogue status and unclassified counter, scoped to dossiq registers only

#### Scenario: Inzageverzoek export delegates to the platform

- **WHEN** the FG triggers a per-betrokkene export (e.g. by BSN) from the dossiq view
- **THEN** the export MUST be produced by OR-PA-7 scoped to dossiq's registers, including activity purpose and legal basis per entry
- **AND** the export action itself MUST be logged by OR

#### Scenario: Non-FG users cannot reach the surface

- **WHEN** a regular handler attempts to open the Verwerkingen view or its delegated endpoints
- **THEN** access MUST be denied per OR-PA-8

### Requirement: Dossiq MUST NOT duplicate platform verwerkingenlogging mechanics

Dossiq SHALL NOT implement log storage, append-only enforcement, retention jobs, export engines, or VNG Logging Verwerkingen API endpoints; external audit tooling SHALL be pointed at OpenRegister's VNG-shaped API (OR-PA-9) with dossiq's register scope, and dossiq documentation SHALL state this.

#### Scenario: No dossiq log endpoints exist

- **WHEN** the dossiq route table is inspected
- **THEN** no endpoint MUST exist that creates, queries, updates, or deletes processing-log entries
- **AND** docs/ MUST direct audit tooling to the OR VNG API with the dossiq register scope

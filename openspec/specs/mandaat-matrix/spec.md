---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# mandaat-matrix Specification

## Purpose
TBD - created by archiving change mandaat-matrix-01-schema-foundation. Update Purpose after archive.
## Requirements
### Requirement: Mandate-Matrix Schemas Are Registered

The system SHALL register six OpenRegister schemas — `MandateringsBesluit`, `Mandaat`,
`OrganisatieRol`, `MedewerkerRolToewijzing`, `MandaatGebruik`, and `MandaatEscalatie` — through
the procest register on app install or upgrade, including the relations between them.

#### Scenario: All six schemas exist after install

- GIVEN a fresh procest install with OpenRegister available
- WHEN the schema-registration repair step runs
- THEN each of the six schemas SHALL be retrievable from the procest register with a schema UUID
- AND the `Mandaat` schema SHALL declare a `besluitId` reference to `MandateringsBesluit`
- AND the `Mandaat` schema SHALL declare a `gemandateerdeRol` reference to `OrganisatieRol`
- AND the `MedewerkerRolToewijzing` schema SHALL declare a `rolId` reference to `OrganisatieRol`

#### Scenario: MandaatGebruik is declared immutable

- GIVEN the `MandaatGebruik` schema is registered
- WHEN its schema definition is inspected
- THEN it SHALL declare the audit-snapshot fields `rolOpMomentVanBesluit` and
  `gebruikteVoorwaarden` as JSON
- AND it SHALL be marked for write-once handling so that immutability can be enforced at the API
  layer by a later chain member

### Requirement: Reference Seed Data Is Created Idempotently

The system SHALL seed reference data — 7 OrganisatieRol records, 5 MedewerkerRolToewijzing
records (including one waarnemer), 2 MandateringsBesluit records, and 4 Mandaat records — through
an idempotent repair step that creates no duplicates on re-run.

#### Scenario: Seed data materialises with correct references

- GIVEN the mandate-matrix schemas are registered
- WHEN the seed repair step runs for the first time
- THEN 7 OrganisatieRol records SHALL exist (incl. Vergunningverlener, Senior Vergunningverlener,
  Hoofd Vergunningverlening, Handhaver)
- AND 5 MedewerkerRolToewijzing records SHALL exist, one with `toewijzingType` = "waarnemer"
- AND 2 MandateringsBesluit records SHALL exist (CR 2026-001 vastgesteld, CR 2025-099 vervallen)
- AND 4 Mandaat records SHALL exist, each with `besluitId` and `gemandateerdeRol` resolving to an
  existing MandateringsBesluit and OrganisatieRol respectively

#### Scenario: Re-running the seed step creates no duplicates

- GIVEN the seed repair step has already run once
- WHEN it runs a second time
- THEN the record counts SHALL remain unchanged (7 / 5 / 2 / 4)
- AND no duplicate OrganisatieRol, Mandaat, MedewerkerRolToewijzing, or MandateringsBesluit record
  SHALL be created

### Requirement: Real-Time Authorization Verdict

The `MandaatCheckService` SHALL provide `isAuthorized(userId, decisionType, caseId)` returning a
verdict `{authorized, mandaatId?, reden?}`, resolving the user's current role and evaluating the
applicable mandate's conditions.

#### Scenario: Authorized when role holds mandate and conditions pass

- GIVEN a zaak of zaaktype "Omgevingsvergunning" with bouwsom €75.000
- AND a user with role "Senior Vergunningverlener" which holds mandate M.3.1.2 (plafond €100.000)
- WHEN `isAuthorized(user, "vergunning_verlenen", zaakId)` is called
- THEN the service SHALL resolve the user's role as of today
- AND SHALL find that the role holds M.3.1.2
- AND SHALL evaluate bouwsom €75.000 ≤ plafond €100.000 as passing
- AND SHALL return `{authorized: true, mandaatId: <M.3.1.2 uuid>, reden: null}`

#### Scenario: Denied when role does not hold the mandate

- GIVEN a user with role "Medewerker Vergunningen" which does NOT hold mandate M.3.1.2
- WHEN `isAuthorized(user, "vergunning_verlenen", zaakId)` is called for a decision requiring M.3.1.2
- THEN the service SHALL return `{authorized: false, mandaatId: null, reden: "niet_bevoegd"}`

#### Scenario: Denied when plafond is exceeded

- GIVEN a user with role "Vergunningverlener" holding M.3.1.1 (plafond €50.000)
- AND a zaak with bouwsom €250.000
- WHEN `isAuthorized(user, "vergunning_verlenen", zaakId)` is called
- THEN the service SHALL return `{authorized: false, reden: "plafond_overschreden"}`

### Requirement: Waarnemer Role Resolution

The `MandaatCheckService` SHALL resolve waarnemer (substitute) assignments active on the decision
date, granting the substitute the authority of the role they are covering when subdelegation rules
permit.

#### Scenario: Waarnemer authorized during active coverage period

- GIVEN a MedewerkerRolToewijzing where Hoofd Stadsbeheer is waarnemer for role "Hoofd VTH" from
  2026-06-15 to 2026-06-30
- WHEN on 2026-06-22 Hoofd Stadsbeheer attempts a decision requiring a mandate held by "Hoofd VTH"
- THEN the service SHALL resolve the active waarnemer assignment
- AND SHALL return `{authorized: true}` with a role snapshot flagged `toewijzingType: "waarnemer"`

### Requirement: Subdelegatie Enforcement

The `MandaatCheckService` SHALL deny authority obtained via waarnemer when the mandate forbids
subdelegation.

#### Scenario: Subdelegation blocked

- GIVEN mandate M.4.2.1 with `subdelegatieToegestaan: false` held by role "Wethouder RO"
- AND Beleidsmedewerker RO is a waarnemer for Wethouder RO, valid today
- WHEN Beleidsmedewerker RO attempts a decision requiring M.4.2.1
- THEN the service SHALL return `{authorized: false, reden: "subdelegatie_niet_toegestaan"}`

### Requirement: ABAC Policy Engine Delegation

The `AbacPolicyService` SHALL wrap the OpenRegister policy engine, accepting a fact set and
returning `{allowed, violations[]}`, and `MandaatCheckService` SHALL use it for condition
evaluation.

#### Scenario: Conditions evaluated by the policy engine

- GIVEN a mandate with `voorwaarden` containing `plafond_bedrag` and `subdelegatie_toegestaan`
- WHEN `MandaatCheckService` evaluates conditions for a case
- THEN it SHALL call `AbacPolicyService.evaluatePolicy(policyName, factSet)` with the fact set
  `{userId, rolId, mandaatId, caseType, caseProperties, decisionType}`
- AND SHALL interpret a non-empty `violations[]` as a failed condition with the corresponding `reden`

### Requirement: Escalation Creation and Path Resolution

The `MandaatEscalatieService` SHALL create a `MandaatEscalatie` record routed to the next-higher
mandaathouder when authority is insufficient, and SHALL notify that recipient.

#### Scenario: Plafond overschrijding escalates to the next-higher mandaathouder

- GIVEN a zaak with bouwsom €250.000 and a user holding M.3.1.1 (plafond €50.000)
- WHEN `createEscalatie(zaakId, "vergunning_verlenen", userId, "plafond_overschreden")` is called
- THEN the service SHALL resolve M.3.1.3 (plafond €500.000) held by "Hoofd Vergunningverlening" as the path
- AND SHALL create a MandaatEscalatie with `status: "open"`,
  `escalatieReden: "plafond_overschreden"`, `escalatiePadEindigtBij` = the current Hoofd VV holder
- AND SHALL send a notification to that recipient

#### Scenario: Escalation routed when role does not hold the mandate

- GIVEN a user whose role does not hold the required mandate
- WHEN an escalation is created with reden "niet_bevoegd"
- THEN a MandaatEscalatie SHALL be created routed to the role holder one level higher in the hierarchy

### Requirement: Escalation Approval and Rejection

The `EscalatieApprovalService` SHALL allow the resolved mandaathouder to approve (executing the
decision and logging usage) or reject (cancelling) an open escalation, recording the outcome.

#### Scenario: Approval executes the decision and logs usage

- GIVEN an open escalation routed to Hoofd VV for a €250.000 vergunning
- WHEN the mandaathouder calls approve via `POST /api/mandate/escalatie/{id}/approve`
- THEN the service SHALL re-check that the approver holds the mandate
- AND SHALL execute the underlying decision
- AND SHALL set the escalation status to "goedgekeurd"
- AND SHALL create a MandaatGebruik log entry attributed to the mandaathouder
- AND SHALL notify the original initiator

#### Scenario: Rejection cancels without executing

- GIVEN an open escalation
- WHEN the mandaathouder calls reject via `POST /api/mandate/escalatie/{id}/reject` with a reason
- THEN the escalation status SHALL become "afgewezen" with the reason stored in `toelichting`
- AND the decision SHALL NOT be executed and the case SHALL remain in its prior status
- AND the initiator SHALL be notified with the rejection reason

#### Scenario: Approval endpoint rejects an unauthorized approver

- GIVEN an open escalation routed to a specific mandaathouder
- WHEN a different authenticated user who does not hold the required mandate calls approve
- THEN the request SHALL be rejected and the decision SHALL NOT be executed

### Requirement: Escalation Rerouting on Personnel Change

The `MandaatEscalatieService` SHALL reroute open escalaties to the new role holder when a person
is replaced in a role.

#### Scenario: Open escalaties reroute to the new role holder

- GIVEN open escalaties with `escalatiePadEindigtBij` = "carol.dewit" in role "Hoofd VV"
- WHEN Carol's assignment ends and "frank.kerkhof" is assigned to "Hoofd VV"
- THEN `autoRerouteOnPersonnelChange` SHALL update those escalaties to `escalatiePadEindigtBij` = "frank.kerkhof"
- AND SHALL notify both Frank (now responsible) and Carol (no longer responsible)

### Requirement: Decidesk Mandate Import

The `DecideskImportService` SHALL fetch a besluit and its attached mandate table from decidesk,
parse the table, and create concept `MandateringsBesluit` + `Mandaat` records.

#### Scenario: Import creates concept records from the besluit table

- GIVEN a collegebesluit "Algemene mandaatregeling gemeente 2026" in decidesk with an Excel mandate table
- WHEN a juridisch medewerker calls `POST /api/mandate/import` with `{decidesk_uuid}`
- THEN the service SHALL retrieve the besluit metadata and attachment from decidesk
- AND SHALL parse the table rows into mandate fields (mandaatNummer, omschrijving, rolNaam, plafond, …)
- AND SHALL create one MandateringsBesluit with `status: "concept"`
- AND SHALL create one Mandaat with `status: "concept"` per table row, each referencing the besluit

### Requirement: Role Validation and Diff View

The import SHALL validate that every referenced role exists and SHALL present a NEW/CHANGED/
REMOVED/UNCHANGED diff against the prior mandateringsbesluit.

#### Scenario: Missing role aborts with an error

- GIVEN an import where a row references role "Wethouder RO" not present in OrganisatieRol
- WHEN the import is parsed
- THEN the service SHALL report an error "Role 'Wethouder RO' not found in registry"

#### Scenario: Diff classifies rows against the prior version

- GIVEN a prior mandateringsbesluit exists
- WHEN a new import is parsed
- THEN the result SHALL classify each mandaatNummer as NEW, CHANGED, REMOVED, or UNCHANGED
- AND the import preview SHALL include `newCount`, `changedCount`, and `removedCount`

### Requirement: Import Approval Finalisation

On approval the import SHALL activate the new besluit and supersede the prior one.

#### Scenario: Approval activates the new besluit and vervalt the prior

- GIVEN a concept MandateringsBesluit with concept Mandaat records and a prior active besluit
- WHEN the user calls `POST /api/mandate/import/{importId}/approve`
- THEN the new MandateringsBesluit status SHALL become "vastgesteld" and its Mandaat records "active"
- AND the prior MandateringsBesluit status SHALL become "vervallen" with `vervalDatum` set to the
  day before the new inwerkingtreding

### Requirement: Authorization Guard on Case Decisions

The system SHALL invoke `MandaatCheckService.isAuthorized()` before any case decision that has a
mandate requirement executes, blocking and escalating on denial and proceeding on success.

#### Scenario: Authorized decision proceeds and is logged

- GIVEN a case decision "Vergunning verlenen" requiring a mandate, attempted by a user who holds it
- WHEN the decision action is triggered
- THEN the `CaseDecisionActionListener` SHALL call `isAuthorized()` and receive `{authorized: true}`
- AND the decision SHALL proceed
- AND a MandaatGebruik log entry SHALL be created after the decision completes

#### Scenario: Unauthorized decision is blocked and escalated

- GIVEN a case decision requiring a mandate the user does not hold
- WHEN the decision action is triggered
- THEN the listener SHALL receive `{authorized: false}`
- AND the decision SHALL NOT execute
- AND an escalation SHALL be dispatched and an error returned to the UI offering escalation

#### Scenario: Decisions without a mandate requirement are unaffected

- GIVEN a case decision that has no mandate requirement
- WHEN the decision action is triggered
- THEN the listener SHALL allow it to proceed without an authorization check or MandaatGebruik log

### Requirement: Immutable MandaatGebruik Audit Log

The `MandaatGebruikService` SHALL create a write-once MandaatGebruik record per authorized
decision, snapshotting role, mandate, and conditions, and SHALL reject mutation attempts.

#### Scenario: Snapshot captured atomically on authorized decision

- GIVEN an authorized decision by Alice (role "Senior Vergunningverlener") using mandate M.3.1.2
- WHEN the MandaatGebruik entry is created
- THEN it SHALL record `zaakId`, `beslissingId`, `mandaatId`, `gemandateerdeId`,
  `rolOpMomentVanBesluit` (role snapshot), `beslissingType`, `beslissingTimestamp`,
  `bevoegdheidsCheckResult`, and `gebruikteVoorwaarden`
- AND the record SHALL be locked

#### Scenario: Update attempt is rejected

- GIVEN an existing MandaatGebruik record
- WHEN any client attempts to update or delete it via the API
- THEN the system SHALL respond 403 Forbidden and the record SHALL remain unchanged

#### Scenario: Audit trail queryable per zaak

- GIVEN a zaak with multiple logged decisions
- WHEN `getDecisionAuditTrail(zaakId)` is called
- THEN it SHALL return all MandaatGebruik entries for that zaak with their snapshots intact

### Requirement: Effective-Dated Mandate Resolution

The `MandaatQueryService` SHALL resolve the mandate version effective on a given decision date, and
authorization SHALL use that version.

#### Scenario: Authorization uses the version effective on the decision date

- GIVEN mandate M.3.1.1 v1 (plafond €50.000, effective through 2026-06-30) and v2 (plafond
  €100.000, effective from 2026-07-01)
- WHEN on 2026-06-25 a user attempts a decision with bouwsom €75.000
- THEN `getMandaatAsOf("M.3.1.1", 2026-06-25)` SHALL return v1
- AND the plafond check SHALL use €50.000, yielding `plafond_overschreden`
- AND the system SHALL offer to schedule the decision for 2026-07-01 or later to use v2

#### Scenario: Audit snapshot is not re-evaluated against later versions

- GIVEN a decision made 2026-03-15 using v1
- WHEN an auditor reviews the zaak on 2026-07-01 after v2 activated
- THEN the audit trail SHALL show v1 (plafond €50.000) as used
- AND the system SHALL NOT re-evaluate the decision against v2

### Requirement: Conflict of Interest Detection

The `ConflictOfInterestService` SHALL detect when a decision-maker is related to the applicant and
SHALL block such decisions, supporting both automatic BRP detection and manual registration.

#### Scenario: Automatic BRP conflict blocks the decision

- GIVEN a zaak whose applicant BSN is related (e.g. spouse) to the deciding user
- WHEN the user attempts a decision on this zaak
- THEN `checkConflict(userId, zaakId)` SHALL return `{conflict: true}`
- AND `isAuthorized()` SHALL return `{authorized: false, reden: "belangenconflict"}`
- AND an escalation to a different role holder SHALL be triggered

#### Scenario: Manual conflict registration blocks the decision

- GIVEN a user with no automatic conflict who registers one with a reason
- WHEN they submit "Register interest conflict"
- THEN the case SHALL record the conflict flag and reason
- AND the user SHALL be prevented from executing the decision
- AND an escalation to an alternative mandaathouder SHALL be triggered

### Requirement: Mandate Matrix Admin Panel

The system SHALL provide an admin settings page with tabs for Besluiten, Rollen, Toewijzingen, and
Import, allowing admins to view and edit mandaten per mandateringsbesluit.

#### Scenario: Admin edits a mandate

- GIVEN an admin opens Settings > Mandate Matrix > Besluiten
- WHEN they select an active mandateringsbesluit and click Edit on a Mandaat
- THEN the MandaatEditor SHALL show fields mandaatNummer, omschrijving, bevoegdheidType,
  wettelijkeGrondslag, a voorwaarden editor (plafond_bedrag, subdelegatie_toegestaan), validity
  date pickers, and a role selector
- AND saving SHALL persist the change via the backend mandate endpoint

### Requirement: OrganisatieRol and Toewijzing Management

The admin panel SHALL allow managing the role hierarchy and person-to-role assignments, including
waarnemer assignments, with referential-integrity guards.

#### Scenario: Role deletion blocked when referenced

- GIVEN an OrganisatieRol referenced by a Mandaat or an active MedewerkerRolToewijzing
- WHEN an admin attempts to delete it
- THEN the system SHALL block the deletion with an error

#### Scenario: Waarnemer assignment created

- GIVEN the Toewijzingen tab is open
- WHEN an admin adds an assignment selecting a person, role, start date, and type "waarnemer"
- THEN a MedewerkerRolToewijzing SHALL be created with `toewijzingType: "waarnemer"`
- AND the assignment SHALL be visually distinguished from primair assignments in the table

#### Scenario: Ending an assignment

- GIVEN an active MedewerkerRolToewijzing
- WHEN the admin clicks "End assignment" and confirms a date
- THEN `toewijzingTotEnMet` SHALL be set to that date

### Requirement: User-Facing Bevoegdheden View

The case detail page SHALL provide a bevoegdheden view showing the mandate matrix for the case's
zaaktype filtered by the user's current role(s).

#### Scenario: Bevoegdheden matrix filtered by role

- GIVEN a zaakbehandelaar with role "Vergunningverlener" opens a zaak of type "Omgevingsvergunning"
- WHEN they open the bevoegdheden panel
- THEN the panel SHALL show only the mandaten their role(s) hold for this zaaktype, with columns
  Mandaat, Omschrijving, Plafond, Subdelegatie, current validity, and validity period
- AND a "What can I do?" filter SHALL show only decision types the user can unilaterally execute

### Requirement: Mandate Detail and Role Holders

The bevoegdheden view SHALL expand a mandate row to show its detail, current role holders, any
waarnemer relationship, and the mandateringsbesluit source.

#### Scenario: Row detail shows role holders and source

- GIVEN the bevoegdheden view is open
- WHEN the user clicks a mandate row
- THEN the panel SHALL expand to show the mandate description, wettelijke grondslag link, current
  role holders (people in the role today), and the MandateringsBesluit source reference
- AND if the user is acting as a waarnemer, the panel SHALL note they are substituting for the
  primary role holder

### Requirement: Authorization and Escalation Test Coverage

The system SHALL include unit and integration tests covering the authorization verdict and the
escalation workflow.

#### Scenario: Unit tests cover the verdict matrix

- GIVEN the MandaatCheckService test suite
- WHEN it runs
- THEN it SHALL assert authorized, niet_bevoegd, plafond_overschreden,
  subdelegatie_niet_toegestaan, waarnemer, and temporal-version paths, and SHALL pass

#### Scenario: Integration tests cover the escalation workflow

- GIVEN the escalation workflow integration test
- WHEN it runs
- THEN it SHALL assert escalation creation on plafond overshoot, approval executing the decision
  with MandaatGebruik logged, rejection leaving the case unchanged, and personnel-change rerouting,
  and SHALL pass

#### Scenario: Authorization guard verified on case decisions

- GIVEN the case-decision authorization integration test
- WHEN it runs
- THEN it SHALL assert that an authorized user's decision succeeds and is logged, an unauthorized
  user's decision is blocked with an escalation, and a waarnemer is authorized with a waarnemer
  flag, and SHALL pass

### Requirement: Spec Traceability and Admin Documentation

Every new public service method SHALL carry an `@spec` tag, and admin documentation SHALL describe
the mandate-matrix operations.

#### Scenario: @spec tags present on new services

- GIVEN the new mandate-matrix service classes
- WHEN they are inspected
- THEN each SHALL have a file-level `@spec` docblock and each public method SHALL link to the
  relevant requirement via an `@spec` tag

#### Scenario: Admin guide documents import and role management

- GIVEN the admin documentation
- WHEN it is reviewed
- THEN it SHALL describe the decidesk import workflow, role-hierarchy setup, waarnemer assignment,
  and troubleshooting for missing roles and validation errors


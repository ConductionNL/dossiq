# Spec — procest-adopt-or-abstractions

This delta spec captures the new contract procest establishes with the
OpenRegister platform: a single lifecycle annotation replaces four
overlapping specs and ~200 LOC of state-machine PHP, notifications move
from PHP services to schema annotations, legal deadline calculations
become declarative, and a manifest declares dependencies on `openregister`
and `openconnector`. The spec retires three sibling specs and updates
three more with OR-feature citations.

This change is **spec-only**. The implementation refactor is deferred to
a follow-up opsx change.

## ADDED Requirements

### Requirement: Procest case schema SHALL declare its lifecycle as an `x-openregister-lifecycle` annotation

The procest case schema SHALL include a single
`x-openregister-lifecycle` annotation that fully describes:

1. The set of lifecycle states (currently:
   `concept`, `in_parafering`, `teruggestuurd`, `geparafeerd`,
   `aangeboden`, `besloten`).
2. The transitions between states, including: `from` state(s), `to`
   state, action key, role-based authorization, and `requires` guards.
3. Per-transition audit-recording requirements (which audit context
   keys must be captured).
4. Skip-step and ad-hoc-step semantics expressed as transitions with
   appropriate `requires` guards.
5. i18n labels for every state and every transition action (Dutch +
   English minimum per ADR-025).

**Rationale:** Replaces the textbook state machine in
`lib/Service/ParaferingService.php:43-353` with declarative
configuration. Audit ref: `04-hardcoded.md:85–86`,
`02-spec-rewrite.md:64,66,67`.

#### Scenario: Annotation includes all six current STATUS_* values

- **WHEN** the case schema is loaded
- **THEN** `x-openregister-lifecycle.states` SHALL contain at minimum:
  `concept`, `in_parafering`, `teruggestuurd`, `geparafeerd`,
  `aangeboden`, `besloten`.
- **AND** each state SHALL include an `i18nKey` or inline `label` map
  with at minimum `nl` and `en` translations.

#### Scenario: Each transition specifies role-based authorization

- **WHEN** a transition is declared
- **THEN** the transition SHALL include a `roles` array listing which
  roles may invoke it.
- **AND** the OR lifecycle engine SHALL reject a transition request
  from a user lacking any of the listed roles (per ADR-023).

#### Scenario: Each transition records audit context

- **WHEN** a transition fires
- **THEN** the OR lifecycle engine SHALL record an
  `audit-trail-immutable` entry containing at minimum:
  `actorType`, `onBehalfOf`, `comment` (when applicable),
  the transition action key, the from-state and to-state, and the
  timestamp.

#### Scenario: Skip-step semantics expressed as a transition

- **GIVEN** a case in `in_parafering`
- **WHEN** an admin invokes the `skip_step` transition
- **THEN** the engine SHALL evaluate the `route.allowSkip` guard
- **AND** record an audit entry with `skippedStep` context
- **AND** keep the case in `in_parafering` (skip-step does not change
  state, only step-pointer).

#### Scenario: Ad-hoc step expressed as a transition

- **GIVEN** a case in `in_parafering`
- **WHEN** an admin or zaakbehandelaar invokes the `add_adhoc_step`
  transition
- **THEN** the engine SHALL record an audit entry with `addedStep`
  and `reason` context.

### Requirement: Procest case schema SHALL declare lifecycle notifications as `x-openregister-notifications`

The procest case schema SHALL replace the three hand-rolled
notifications in `lib/Service/ParaferingNotificationService.php` and
the PHP-built subject in `lib/Service/CaseEmailService.php:92` with
schema-declared `x-openregister-notifications` rules.

**Rationale:** Audit ref: `04-hardcoded.md:87,93`. The
`x-openregister-notifications` capability already exists in OR.

#### Scenario: Three transition notifications declared

- **THEN** the case schema SHALL include at least three
  `x-openregister-notifications` rules triggered respectively by:
  (a) the `start_parafering` transition,
  (b) the `accept` transition,
  (c) the `reject` transition.

#### Scenario: All notification copy is i18n keys

- **WHEN** a notification rule is declared
- **THEN** its `subject` and `body` SHALL be expressed as `i18nKey`
  references resolved through OR's `register-i18n` capability.
- **AND** no PHP-built `setSubject()` call SHALL be retained for
  these three notifications.

#### Scenario: Recipient resolves through schema fields or roles

- **WHEN** a notification rule is declared
- **THEN** its `recipient` SHALL be expressed as either a `field`
  reference (e.g. `createdBy`) or a `role` reference (e.g.
  `currentStep.assignees`), not as a hardcoded user list.

### Requirement: Bezwaar legal deadlines SHALL be declared as `x-openregister-calculations`

The bezwaar schema SHALL replace prose deadline calculation
requirements with declarative `x-openregister-calculations` entries,
one per AWB article cited in `bezwaar-lifecycle/spec.md:88,95`.

**Rationale:** Audit ref: `04-hardcoded.md:97`. AWB articles 6:7, 6:8,
7:10, 7:24 are legal calculation rules; OR's calculations annotation
is the correct home.

#### Scenario: AWB 6:7 deadline is declarative

- **WHEN** the bezwaar schema is loaded
- **THEN** `x-openregister-calculations` SHALL include an entry
  computing `termijnBezwaar` from `besluitDatum + 42 business days`
- **AND** the entry SHALL cite `AWB Art. 6:7` in a
  `legalSource` field.

#### Scenario: AWB 7:10 and 7:24 deadlines are declarative

- **WHEN** the bezwaar schema is loaded
- **THEN** `x-openregister-calculations` SHALL include entries for
  `termijnBeslissing` (6 weeks per AWB 7:10) and `termijnVerdaging`
  (12 weeks per AWB 7:24).

#### Scenario: No `BezwaarDeadlineService` PHP class is required

- **THEN** the spec SHALL NOT prescribe a custom PHP service for AWB
  deadline calculation; the calculations annotation is the contract.

### Requirement: Procest dashboards SHALL cite `x-openregister-aggregations`

`parafering-dashboard/spec.md` and `case-dashboard-view/spec.md` SHALL
cite OR's `aggregations-backend-native` capability and express their
count-by-status / overdue queries as `x-openregister-aggregations`
annotations on the case schema.

**Rationale:** Audit ref: `02-spec-rewrite.md:68,70`.

#### Scenario: Count-by-status aggregation declared

- **WHEN** the case schema is loaded
- **THEN** `x-openregister-aggregations` SHALL include a `casesByStatus`
  entry grouping by the lifecycle status field with a `count` aggregate.

#### Scenario: Dashboard widgets bind to OR aggregations endpoint

- **WHEN** a dashboard widget renders count-by-status
- **THEN** it SHALL consume the OR aggregations endpoint
- **AND** SHALL NOT issue per-status `findObjects` calls in a loop.

### Requirement: Procest case-location SHALL cite `geo-metadata-kaart`

`openspec/specs/case-location/spec.md` SHALL cite OR's
`geo-metadata-kaart` capability for storing and rendering geographic
metadata on cases.

**Rationale:** Audit ref: `02-spec-rewrite.md:69`.

#### Scenario: Case-location spec cites OR feature

- **WHEN** the case-location spec is read
- **THEN** it SHALL include an explicit citation to
  `openregister/openspec/changes/geo-metadata-kaart/`.
- **AND** SHALL describe geo metadata as annotation-driven, not as a
  custom location service.

### Requirement: Procest SHALL adopt the OR i18n source of truth

The system SHALL satisfy the behaviour described as "Procest SHALL adopt the OR i18n source of truth".

All user-facing case strings — state labels, transition action labels,
notification subjects/bodies, dashboard widget titles — SHALL be
sourced through OR's `register-i18n` capability, with Dutch and English
as the minimum language set.

**Rationale:** ADR-025 mandates a single i18n source of truth.
`feedback_i18n-requirement.md` requires nl + en for all apps.

#### Scenario: All annotation labels use `i18nKey` or `{ nl, en }` map

- **WHEN** a state, transition, notification, or aggregation declares a
  user-facing string
- **THEN** the string SHALL be an `i18nKey` reference OR an inline
  `{ nl, en }` translation map.
- **AND** SHALL NOT be a hardcoded single-language literal.

#### Scenario: API requests honour Accept-Language

- **WHEN** the case API receives a request with `Accept-Language: en`
- **THEN** the response SHALL render i18n keys in English per OR's
  `i18n-api-language-negotiation` capability.

### Requirement: Procest SHALL declare an app manifest

Per ADR-024, procest SHALL ship a `manifest.json` declaring its tier,
dependencies, consumed capabilities, and provided capabilities.

**Rationale:** Audit instruction; ADR-024.

#### Scenario: Dependencies include openregister and openconnector

- **WHEN** the manifest is read
- **THEN** `dependencies` SHALL include `openregister` and
  `openconnector`.
- **AND** SHALL explain in `consumes` which OR capabilities are
  required (lifecycle, audit, notifications, aggregations, geo, i18n,
  resolver, computed fields).

#### Scenario: Manifest tier reflects current adoption state

- **WHEN** the manifest is read
- **THEN** `tier` SHALL be 2 until the follow-up implementation change
  lands, after which procest may be promoted to tier 3.

#### Scenario: Manifest is generated from `appinfo/routes.php`

- **WHEN** the manifest is generated
- **THEN** the routes section SHALL be derived from
  `appinfo/routes.php` (no hand-maintained route list).

### Requirement: ZGW protocol bindings SHALL remain app-local

The system SHALL satisfy the behaviour described as "ZGW protocol bindings SHALL remain app-local".

`lib/Service/NotificatieService.php`, `lib/Controller/ZrcController.php`,
`lib/Controller/BrcController.php`, `lib/Controller/ZtcController.php`,
`lib/Controller/NrcController.php`, `lib/Service/ZgwZtcRulesService.php`,
`lib/Service/ZgwDrcRulesService.php`, and the `VERTROUWELIJKHEID_LEVELS`
/ `AFLEIDINGSWIJZE_*` constants SHALL remain app-local and SHALL NOT be
folded into OR notification annotations or OR lifecycle.

**Rationale:** Audit ref: `04-hardcoded.md:144–152`. These are bindings
to VNG ZGW protocol — published Dutch government standards.

#### Scenario: NotificatieService dispatches outbound ZGW Notificaties API

- **WHEN** a ZGW event must be published
- **THEN** `NotificatieService` SHALL dispatch via the VNG ZGW
  Notificaties API.
- **AND** OR's `AnnotationNotificationDispatcher` SHALL NOT be invoked
  for ZGW protocol messages.

#### Scenario: Internal NC notifications use OR annotations

- **WHEN** a transition triggers an in-app NC notification
- **THEN** the notification SHALL flow through OR's annotation
  dispatcher per the `x-openregister-notifications` rule.
- **AND** SHALL NOT use `NotificatieService`.

### Requirement: Procest SHALL adopt OR's register-resolver service

The system SHALL satisfy the behaviour described as "Procest SHALL adopt OR's register-resolver service".

When OR's `register-resolver-service` capability lands (per
`04-hardcoded.md:136–138`), procest SHALL replace direct
`getValueString(APP_ID, 'foo_register', '')` calls with
`RegisterResolver::resolve('case')` (and equivalents for other
schemas).

**Rationale:** Eliminate per-app slug-resolution logic; centralise in
OR with per-tenant override semantics (per ADR-022).

#### Scenario: All register/schema slug lookups go through the resolver

- **WHEN** a procest service needs the active case register slug
- **THEN** it SHALL call `RegisterResolver::resolve('case')`
- **AND** SHALL NOT call `IAppConfig::getValueString('procest', 'case_register', '')` directly.

### Requirement: Procest SHALL consume nextcloud-vue multi-tenancy context

The system SHALL satisfy the behaviour described as "Procest SHALL consume nextcloud-vue multi-tenancy context".

Per `feedback_design-system-cd-first.md`-style alignment with the
nextcloud-vue contract, procest's case Vue components SHALL consume
the `multi-tenancy-context` composable so that store calls and
register-resolver calls are tenant-scoped.

**Rationale:** Audit ref: `01-code-cleanup.md` notes
`createObjectStore('object', { plugins: [...] })` is already wired —
this requirement formalises it as the contract and adds the
`useTenantContext` citation.

#### Scenario: Case store uses createObjectStore

- **WHEN** the case store is instantiated
- **THEN** it SHALL use `createObjectStore('object', { plugins: [...] })`
  exactly as currently in `src/store/modules/object.js`.
- **AND** SHALL include the `filesPlugin`, `auditTrailsPlugin`, and
  `relationsPlugin`.

#### Scenario: Case detail components honour tenant context

- **WHEN** a case detail component renders
- **THEN** it SHALL resolve the active tenant via
  `useTenantContext`
- **AND** SHALL pass the tenant identifier to register-resolver calls.

## MODIFIED Requirements

### Requirement: Case-management spec is the canonical lifecycle home

The system SHALL satisfy the behaviour described as "Case-management spec is the canonical lifecycle home".

The previously distributed lifecycle requirements (across
`case-management`, `parafering-actions`, `parafering-audit-trail`,
`parafeerroute-engine`) are consolidated into a single
`case-management/spec.md`. The other three specs are retired with
closing notes.

**Rationale:** Audit ref: `02-spec-rewrite.md:96–105` — procest carries
~4 specs of state-machine duplication.

#### Scenario: Three sibling specs are retired with closing notes

- **WHEN** `parafering-actions/spec.md` is opened
- **THEN** it SHALL begin with a "**RETIRED — see
  `case-management/spec.md`**" note pointing to the consolidated home.
- **AND** the same SHALL apply to `parafering-audit-trail/spec.md` and
  `parafeerroute-engine/spec.md`.

#### Scenario: Migration map preserves no-information-loss

- **WHEN** `case-management/spec.md` is read
- **THEN** it SHALL include a "Migration map" appendix listing each
  retired spec's section → new section, ensuring no requirement is
  silently dropped.

### Requirement: Bezwaar-lifecycle spec uses x-openregister-calculations

The system SHALL satisfy the behaviour described as "Bezwaar-lifecycle spec uses x-openregister-calculations".

`openspec/specs/bezwaar-lifecycle/spec.md:88,95` is updated so the AWB
deadline calculation requirement is declarative
(`x-openregister-calculations`) rather than implying a
`BezwaarDeadlineService` PHP class.

**Rationale:** Audit ref: `04-hardcoded.md:97`.

#### Scenario: AWB articles cited in calculation entries

- **WHEN** the bezwaar schema is loaded
- **THEN** each AWB-derived calculation entry SHALL include a
  `legalSource` field naming the AWB article (e.g. `AWB Art. 7:10`).

### Requirement: Voorstel-management spec retro-documents the lifecycle

The system SHALL satisfy the behaviour described as "Voorstel-management spec retro-documents the lifecycle".

`openspec/specs/voorstel-management/spec.md` is updated to retro-
document the consolidated lifecycle annotation rather than describing
a custom paraferingflow.

**Rationale:** Audit ref: `04-hardcoded.md:99`.

#### Scenario: Voorstel-management cites the lifecycle annotation

- **WHEN** the voorstel-management spec is read
- **THEN** it SHALL cite `case-management/spec.md` § Lifecycle
  annotation as the authoritative source for paraferingflow rules.

### Requirement: Automatic-actions spec uses lifecycle annotation

The system SHALL satisfy the behaviour described as "Automatic-actions spec uses lifecycle annotation".

`openspec/specs/automatic-actions/spec.md` is updated so per-status
branching is expressed as lifecycle transitions rather than a
per-status PHP service.

**Rationale:** Audit ref: `04-hardcoded.md:98`.

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

## REMOVED Requirements

### Requirement: Custom parafering state machine in PHP

The system SHALL satisfy the behaviour described as "Custom parafering state machine in PHP".

**Removed.** The previous requirement (implied by
`ParaferingService.php:43-353` and described across four specs) for a
hand-maintained PHP state machine with `STATUS_*` constants and
guarded transition methods is removed. Replaced by ADDED
`x-openregister-lifecycle` annotation requirement above.

**Rationale:** Audit ref: `02-spec-rewrite.md:64,66,67;
04-hardcoded.md:85–86`.

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: Custom parafering audit trail

The system SHALL satisfy the behaviour described as "Custom parafering audit trail".

**Removed.** The previous requirement for a bespoke parafeeractie
versioning table is removed. Replaced by consumption of OR's
`audit-trail-immutable` capability with delegation tracking
(`actorType`, `onBehalfOf`) recorded as audit context.

**Rationale:** Audit ref: `02-spec-rewrite.md:65;
01-code-cleanup.md`.

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: Custom parafeerroute engine

The system SHALL satisfy the behaviour described as "Custom parafeerroute engine".

**Removed.** The previous requirement for a bespoke route-modification
engine is removed. Replaced by lifecycle transitions with `requires`
guards encoding skip-step / ad-hoc-step rules.

**Rationale:** Audit ref: `02-spec-rewrite.md:67`.

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: Custom case-status transition tables

The system SHALL satisfy the behaviour described as "Custom case-status transition tables".

**Removed.** The previous requirement for `CaseStatus` and
`CaseTransition` database tables is removed. State and transitions
are declared as schema annotation; OR provides the engine.

**Rationale:** Audit ref: `02-spec-rewrite.md:64`.

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: Hand-rolled parafering notification service

The system SHALL satisfy the behaviour described as "Hand-rolled parafering notification service".

**Removed.** The previous requirement implied by
`ParaferingNotificationService.php` for three hand-rolled
`notificationManager->createNotification()` calls per transition is
removed. Replaced by `x-openregister-notifications` rules.

**Rationale:** Audit ref: `04-hardcoded.md:87`.

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: PHP-built case email subject

The system SHALL satisfy the behaviour described as "PHP-built case email subject".

**Removed.** The previous requirement implied by
`CaseEmailService.php:92` for a PHP-built `setSubject()` call is
removed. Replaced by schema-declared notification copy via i18n keys.

**Rationale:** Audit ref: `04-hardcoded.md:93`.

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

## Deferred to follow-up implementation change

The following code-side moves are referenced by this spec but are NOT
performed here (this change is spec-only):

1. Replace `lib/Service/ParaferingService.php:43-353` guarded
   transitions with calls to OR's lifecycle engine. Convert
   `ParaferingService` into a thin facade.
2. Delete `lib/Service/ParaferingNotificationService.php` (subsumed by
   OR's `AnnotationNotificationDispatcher`).
3. Move `lib/Service/CaseEmailService.php:92` PHP-built subject to
   schema-declared `x-openregister-notifications`.
4. Move `lib/BackgroundJob/ShareMaintenanceJob.php:42`
   `REMINDER_DAYS = 3` to admin config.
5. Move `lib/Controller/MetricsController.php:43,48` `CACHE_TTL_*` to
   admin config.
6. Move `lib/Service/ZgwDocumentService.php:45` `STORAGE_BASE` to
   admin config (per-tenant override).
7. Fix the `lib/Service/ZgwDocumentService.php:312`
   `getUserFolder('admin')` fallback — throw on missing user, never
   silently fall back to admin's home folder.
8. Replace `IAppConfig::getValueString('procest', 'case_register', '')`
   call sites (when present) with `RegisterResolver::resolve('case')`
   once the OR resolver capability lands.
9. Audit `lib/Repair/LoadDefaultZgwMappings.php:1535-1550` for tenant
   safety; propose a tenant-aware seed.
10. Add the manifest.json sketched in `design.md`.

The follow-up change SHALL reference this spec and SHALL ensure the
annotation shapes here are honoured exactly.

## Out of scope

- VNG ZGW protocol code (`NotificatieService`, `ZrcController`,
  `BrcController`, `ZtcController`, `NrcController`,
  `ZgwZtcRulesService`, `ZgwDrcRulesService`, `VERTROUWELIJKHEID_LEVELS`,
  `AFLEIDINGSWIJZE_*`) — KEEP app-local.
- ZGW Zaak mapping spec (`zgw-api-mapping/spec.md`) — domain-legitimate.
- Case-type versioning (`zaaktype-versioning/spec.md`) — domain-specific.
- Bezwaar advisory committee, hearing, decision specs — bezwaar-specific
  workflow detail; only `bezwaar-lifecycle/spec.md` deadline
  calculation is touched here.

## Acceptance criteria

- [ ] Case schema declares `x-openregister-lifecycle` covering all 6
  STATUS_* values and all transitions in
  `ParaferingService.php:158-353`.
- [ ] Case schema declares `x-openregister-notifications` covering all
  3 `ParaferingNotificationService` call sites + the
  `CaseEmailService::setSubject()` path.
- [ ] Bezwaar schema declares `x-openregister-calculations` for AWB
  6:7, 6:8, 7:10, 7:24.
- [ ] Case schema declares `x-openregister-aggregations` referenced
  by parafering-dashboard and case-dashboard-view.
- [ ] All user-facing strings flow through OR i18n source of truth
  (Dutch + English minimum).
- [ ] Manifest sketched with `dependencies: ["openregister",
  "openconnector"]`.
- [ ] Three sibling specs retired with closing notes; migration map
  embedded in consolidated spec.
- [ ] ZGW protocol boundary explicitly preserved.
- [ ] No code is modified by this change.

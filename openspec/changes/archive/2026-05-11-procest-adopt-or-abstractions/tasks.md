# Tasks — procest adopts OR abstractions

> **Spec-only change.** All tasks below produce or modify spec files,
> closing notes, manifest sketches, or annotation designs. No code is
> modified by this change. Code refactor tasks are listed for traceability
> but are deferred to follow-up opsx changes.
>
> Audit references throughout: `.claude/audit-2026-05-03/01-code-cleanup.md`,
> `02-spec-rewrite.md`, `04-hardcoded.md`.

## Phase 1 — Lifecycle annotation design

Sketch the `x-openregister-lifecycle` annotation that consolidates the
parafering state machine currently spread across four specs and ~200 LOC
of `ParaferingService.php`.

- [ ] 1.1 Enumerate the six STATUS_* values from
  `lib/Service/ParaferingService.php:43-68` and map each to a lifecycle
  state name (lowercase snake_case):
  - [ ] STATUS_CONCEPT → `concept`
  - [ ] STATUS_IN_PARAFERING → `in_parafering`
  - [ ] STATUS_TERUGGESTUURD → `teruggestuurd`
  - [ ] STATUS_GEPARAFEERD → `geparafeerd`
  - [ ] STATUS_AANGEBODEN → `aangeboden`
  - [ ] STATUS_BESLOTEN → `besloten`
- [ ] 1.2 Enumerate the guarded transitions from
  `ParaferingService.php:158-353` and express each as a transition rule
  with a `requires` guard (for the `if status !== ...` checks).
- [ ] 1.3 Map each parafering action (`advies`, `parafering`,
  `accordering`) to a transition action name (cross-reference
  `parafering-actions/spec.md`).
- [ ] 1.4 Map ad-hoc step / skip-step semantics from
  `parafeerroute-engine/spec.md` to lifecycle `requires` guards.
- [ ] 1.5 Define role-based authorization per transition (ADR-023):
  which roles may invoke each transition.
- [ ] 1.6 Define audit-recording requirements per transition (cite OR
  `audit-trail-immutable`): actorType, onBehalfOf, comment, timestamp.
- [ ] 1.7 Capture delegation semantics (currently in
  `parafering-audit-trail/spec.md`): `actorType`, `onBehalfOf` recorded
  as audit context, not as separate fields on the case.
- [ ] 1.8 Write the full JSON annotation example into `design.md` §
  "Lifecycle annotation" with before/after sketch.

## Phase 2 — Notifications annotation design

Sketch `x-openregister-notifications` rules replacing
`ParaferingNotificationService.php`.

- [ ] 2.1 Enumerate the three `notificationManager->createNotification()`
  call sites in `ParaferingNotificationService.php:64,109,153` and
  identify the trigger transition for each.
- [ ] 2.2 Map each notification's: recipient (by role / user reference),
  subject template, body template, link.
- [ ] 2.3 Express subject / body as i18n keys (per ADR-025); cite
  `i18n-source-of-truth`.
- [ ] 2.4 Define notification triggering condition (always-on /
  conditional / role-filtered).
- [ ] 2.5 Replace `CaseEmailService::setSubject()` PHP-built copy
  (`lib/Service/CaseEmailService.php:92`) with schema-declared
  notification copy in the annotation.
- [ ] 2.6 Write the full JSON notification annotation into `design.md` §
  "Notifications annotation".
- [ ] 2.7 Confirm OR's `x-openregister-notifications` (already exists per
  `04-hardcoded.md:127–129`) supports all three rules; if a gap, add a
  note to the OR-side spec.

## Phase 3 — ParaferingService refactor design (deferred to code change)

Document target shape of `ParaferingService` after lifecycle adoption.
**No code changes here.**

- [ ] 3.1 Identify which methods of `ParaferingService` survive: methods
  that translate domain action names to lifecycle transitions (thin
  facade), Dutch-business-rule methods that aren't pure state.
- [ ] 3.2 Identify which methods are deleted: all six `STATUS_*`
  constants, all guarded transition `if`-blocks at lines 158–353.
- [ ] 3.3 Document the call shape: `paraferingEngine->transition(case,
  'advies_geven', $context)` calling OR lifecycle engine.
- [ ] 3.4 Note that the spec change does NOT include the refactor;
  reference to follow-up implementation change `procest-implement-or-lifecycle`.
- [ ] 3.5 Add a "future code change" note to `case-management/spec.md`
  pointing at the refactor task.

## Phase 4 — ParaferingNotificationService refactor design (deferred)

Document target shape: file is **deleted**.

- [ ] 4.1 Confirm all three call sites map to annotation rules from
  Phase 2.
- [ ] 4.2 Verify OR's `AnnotationNotificationDispatcher` is the
  consuming component (cross-reference OR `notificatie-engine` spec).
- [ ] 4.3 Document deletion intent in `case-management/spec.md` and in
  `tasks.md` for the follow-up code change.

## Phase 5 — Spec retirements (the consolidation)

The big move. Three specs fold into `case-management`. Each retired spec
gets a closing note pointing to the consolidated spec.

- [ ] 5.1 Rewrite `openspec/specs/case-management/spec.md`:
  - [ ] 5.1.1 Open with: "Procest case lifecycle is expressed as an OR
    `x-openregister-lifecycle` annotation on the case schema. This spec
    is the canonical home; previously sibling specs
    (`parafering-actions`, `parafering-audit-trail`,
    `parafeerroute-engine`) are retired and their requirements live
    here."
  - [ ] 5.1.2 Cite OR capabilities: `object-lifecycle`,
    `audit-trail-immutable`, `notificatie-engine`, `register-i18n`.
  - [ ] 5.1.3 Embed the full lifecycle annotation example from Phase 1.
  - [ ] 5.1.4 Embed the notification annotation example from Phase 2.
  - [ ] 5.1.5 Specify guard semantics (skip-step, ad-hoc step) inherited
    from `parafeerroute-engine`.
  - [ ] 5.1.6 Specify audit context (actorType, onBehalfOf, comment)
    inherited from `parafering-audit-trail`.
  - [ ] 5.1.7 Specify action-specific authorization inherited from
    `parafering-actions`.
  - [ ] 5.1.8 Add explicit cross-references to retired specs.
- [ ] 5.2 Add closing note to
  `openspec/specs/parafering-actions/spec.md`:
  - [ ] 5.2.1 Insert frontmatter or top-of-file: "**RETIRED — see
    `case-management/spec.md`.** Action-specific guards and audit
    recording moved to the consolidated lifecycle annotation."
  - [ ] 5.2.2 Preserve the original requirements as historical
    appendix; flag what unique requirement (if any) wasn't carried over
    and address it.
- [ ] 5.3 Add closing note to
  `openspec/specs/parafering-audit-trail/spec.md`:
  - [ ] 5.3.1 Insert: "**RETIRED — consume OR
    `audit-trail-immutable`.** Delegation tracking
    (`actorType`, `onBehalfOf`) is recorded as audit context."
  - [ ] 5.3.2 Preserve original requirements; ensure delegation tracking
    is in the consolidated spec.
- [ ] 5.4 Add closing note to
  `openspec/specs/parafeerroute-engine/spec.md`:
  - [ ] 5.4.1 Insert: "**RETIRED — route modification expressed as
    `requires` guards on lifecycle transitions.** Skip-step / ad-hoc
    step semantics live in the consolidated lifecycle annotation."
  - [ ] 5.4.2 Preserve original requirements; ensure skip / ad-hoc
    semantics are in the consolidated spec.
- [ ] 5.5 Verify no other procest spec references the retired specs by
  path; if found, update to point to `case-management`.
- [ ] 5.6 Add a "Migration map" appendix to
  `case-management/spec.md` listing each retired spec's section → new
  section in the consolidated spec.

## Phase 6 — Register-resolver consumption

procest must adopt OR's `register-resolver-service` (`04-hardcoded.md:136–138`)
when it lands. Spec-level adoption only here.

- [ ] 6.1 Inventory current procest call sites that resolve register /
  schema slugs from `IAppConfig` (procest is lighter on this than
  pipelinq's 8 sites; document the actual count).
- [ ] 6.2 Cite the OR `register-resolver-service` capability in
  `case-management/spec.md` and (forward-looking) in
  `openregister-integration/spec.md`.
- [ ] 6.3 Note the follow-up code change will replace direct
  `getValueString(APP_ID, 'foo_register', '')` calls with
  `RegisterResolver::resolve('case')`.
- [ ] 6.4 Document the per-tenant override semantics (per ADR-022 +
  multi-tenancy-context).

## Phase 7 — Bezwaar deadline calculation → x-openregister-calculations

- [ ] 7.1 Read current `openspec/specs/bezwaar-lifecycle/spec.md:88,95`
  ("system SHALL automatically calculate legal deadlines based on AWB
  articles 6:7, 6:8, 7:10, 7:24").
- [ ] 7.2 Replace prose calculation with declarative
  `x-openregister-calculations` annotation example for each AWB article.
- [ ] 7.3 Cite OR computed-fields capability (per `RenderObject.php:1418`,
  audit ref: `04-hardcoded.md:130–132`).
- [ ] 7.4 Note that `BezwaarDeadlineService` is no longer needed and
  flag for the follow-up code change.
- [ ] 7.5 Cross-reference Forum Standaardisatie / Algemene wet
  bestuursrecht (AWB) so calc rules are traceable to the legal source.
- [ ] 7.6 Update `openspec/specs/automatic-actions/spec.md` (audit ref:
  `04-hardcoded.md:98`) — per-status branching uses lifecycle
  annotation, not a per-status PHP service.
- [ ] 7.7 Update `openspec/specs/voorstel-management/spec.md` (audit
  ref: `04-hardcoded.md:99`) — retro-document the lifecycle annotation
  rather than describing a custom paraferingflow.

## Phase 8 — Citation-only spec updates

Three specs need OR-feature citations only — no rewrites.

- [ ] 8.1 `openspec/specs/case-location/spec.md`:
  - [ ] 8.1.1 Add citation block referencing OR `geo-metadata-kaart`
    (which is in `openregister/openspec/changes/geo-metadata-kaart/`).
  - [ ] 8.1.2 Note geo metadata is annotation-driven, not a custom
    location service.
- [ ] 8.2 `openspec/specs/parafering-dashboard/spec.md`:
  - [ ] 8.2.1 Add citation block referencing OR
    `aggregations-backend-native` (in
    `openregister/openspec/changes/aggregations-backend-native/`).
  - [ ] 8.2.2 Express count-by-status queries as
    `x-openregister-aggregations` annotations on the case schema.
- [ ] 8.3 `openspec/specs/case-dashboard-view/spec.md`:
  - [ ] 8.3.1 Same OR aggregations citation.
  - [ ] 8.3.2 Note KPI cards bind directly to the aggregations
    endpoint; no custom dashboard service.

## Phase 9 — Manifest adoption (ADR-024)

- [ ] 9.1 Sketch a `manifest.json` for procest at the proposal level
  (the manifest itself is created in the follow-up code change):
  - [ ] 9.1.1 `tier: 2` (ramps to 3 once full lifecycle adoption lands).
  - [ ] 9.1.2 `dependencies: ["openregister", "openconnector"]` (per
    audit instruction; openconnector is needed for ZGW protocol bindings).
  - [ ] 9.1.3 Routes generated from `appinfo/routes.php`; document
    generation process.
  - [ ] 9.1.4 `provides` block lists the case schema + parafering
    lifecycle entry point.
  - [ ] 9.1.5 `consumes` block lists the OR capabilities cited in
    Phases 1–8.
- [ ] 9.2 Cite hydra `adopt-app-manifest` change.
- [ ] 9.3 Cite ADR-024 (`hydra/openspec/architecture/adr-024-app-manifest.md`).
- [ ] 9.4 Document manifest in `case-management/spec.md` and link from
  `proposal.md`.
- [ ] 9.5 Audit the seed in `lib/Repair/LoadDefaultZgwMappings.php:1535-1550`
  for tenant safety:
  - [ ] 9.5.1 Document `procest-admin` group seed.
  - [ ] 9.5.2 Document `userId='admin'` ownership seed.
  - [ ] 9.5.3 Flag risk: in multi-tenant deployment, ownership crosses
    tenants. Defer fix to a follow-up change with explicit tenant
    propagation.

## Phase 10 — ZgwDocumentService security fix design

The `getUserFolder('admin')` fallback in
`lib/Service/ZgwDocumentService.php:312` is a security smell (audit ref:
`04-hardcoded.md:90`). Spec the fix; defer the code change.

- [ ] 10.1 Document current behaviour: when `getUser()` returns null,
  fall back to `getUserFolder('admin')`. This silently writes documents
  into admin's home folder, bypassing per-user authorization.
- [ ] 10.2 Specify target behaviour: throw `\RuntimeException` (or
  return 401) when no authenticated user is available. **Never** fall
  back to admin.
- [ ] 10.3 Identify legitimate "system" callers (background jobs,
  repair steps): they must use a system-context authentication, not
  admin's home folder.
- [ ] 10.4 Document `STORAGE_BASE = 'procest/documenten'` (audit ref:
  `04-hardcoded.md:90`) — should be admin-config (per-tenant override).
- [ ] 10.5 Add the fix as a follow-up change scope item.

## Phase 11 — Hardcoded magic-number cleanup design

Spec the moves; defer the code changes.

- [ ] 11.1 `lib/BackgroundJob/ShareMaintenanceJob.php:42` —
  `REMINDER_DAYS = 3`:
  - [ ] 11.1.1 Move to admin-config (`procest_reminder_days`).
  - [ ] 11.1.2 Document default value (3) as fallback only.
- [ ] 11.2 `lib/Controller/MetricsController.php:43,48` —
  `CACHE_TTL_DEFAULT = 30`, `CACHE_TTL_OVERDUE = 60`:
  - [ ] 11.2.1 Move to admin-config (`procest_metrics_cache_default`,
    `procest_metrics_cache_overdue`).
  - [ ] 11.2.2 Document defaults as fallback only.
- [ ] 11.3 `lib/Service/CaseEmailService.php:92` —
  PHP-built `setSubject()`:
  - [ ] 11.3.1 Replace with schema-declared notification copy via
    `x-openregister-notifications` (covered in Phase 2).
  - [ ] 11.3.2 i18n via the OR i18n source-of-truth.
- [ ] 11.4 Confirm ZGW protocol constants stay app-local (audit ref:
  `04-hardcoded.md:144–152`):
  - [ ] 11.4.1 `ZrcController::ZGW_API` — KEEP.
  - [ ] 11.4.2 `BrcController::ZGW_API` — KEEP.
  - [ ] 11.4.3 `ZtcController::ZGW_API` — KEEP.
  - [ ] 11.4.4 `NrcController::ZGW_API`, `VERTROUWELIJKHEID_LEVELS` —
    KEEP.
  - [ ] 11.4.5 `ZgwZtcRulesService::AFLEIDINGSWIJZE_*` — KEEP (VNG
    standard).
  - [ ] 11.4.6 `NotificatieService` — KEEP (ZGW Notificaties API).

## Phase 12 — i18n + multi-tenancy adoption

- [ ] 12.1 Confirm `src/store/modules/object.js` uses
  `createObjectStore('object', { plugins: [filesPlugin(),
  auditTrailsPlugin(), relationsPlugin()] })` (audit ref:
  `01-code-cleanup.md` confirms this is GOOD).
- [ ] 12.2 Add `useTenantContext` composable citation
  (nextcloud-vue `multi-tenancy-context`) to relevant Vue components in
  the spec.
- [ ] 12.3 Document i18n adoption in `case-management/spec.md`:
  - [ ] 12.3.1 Cite `i18n-source-of-truth`.
  - [ ] 12.3.2 Cite `i18n-api-language-negotiation`.
  - [ ] 12.3.3 Confirm Dutch + English minimum (per
    `feedback_i18n-requirement.md`).
  - [ ] 12.3.4 Note case status labels, transition action labels,
    notification subjects/bodies all flow through the OR i18n source of
    truth.
- [ ] 12.4 Note `register-i18n` (`openregister/openspec/specs/register-i18n/`)
  is the implementation home.

## Phase 13 — NotificatieService boundary documentation

`lib/Service/NotificatieService.php` is **legitimately ZGW-specific** and
must not be confused with OR's `AnnotationNotificationDispatcher`.

- [ ] 13.1 Document in `design.md` § "Boundary preserved":
  - [ ] 13.1.1 What `NotificatieService` does: dispatches messages to
    the VNG ZGW Notificaties API (NRC) per Dutch government protocol.
  - [ ] 13.1.2 What it does NOT do: handle OR-object lifecycle
    notifications.
  - [ ] 13.1.3 Why it stays: ZGW Notificaties API is a published
    Dutch government standard with strict outbound message format.
- [ ] 13.2 Add a note in `case-management/spec.md` explicitly:
  "ZGW Notificaties API outbound messages are dispatched by
  `NotificatieService` (legitimately app-local). Lifecycle notifications
  to NC users are dispatched by OR's notification engine via the
  schema annotation."

## Phase 14 — Cross-reference verification

- [ ] 14.1 Grep for remaining references to retired specs across
  procest/openspec; update each.
- [ ] 14.2 Confirm no broken citations after retirement.
- [ ] 14.3 Add a CHANGELOG note to procest/openspec for the
  consolidation (so future audits can trace).
- [ ] 14.4 Update `openspec/app-config.json` (if present) to reflect
  retired specs and consolidated spec.

## Phase 15 — Acceptance review

- [ ] 15.1 Verify all 4 NEEDS-REWRITE specs from `02-spec-rewrite.md`
  are addressed.
- [ ] 15.2 Verify all 3 MISSING-OR-DEP specs cite OR features.
- [ ] 15.3 Verify the lifecycle annotation example is complete (6
  states, all transitions, all guards, all roles, all audit context).
- [ ] 15.4 Verify the notifications annotation example covers all 3
  current call sites.
- [ ] 15.5 Verify the bezwaar deadline calculation is declarative.
- [ ] 15.6 Verify manifest sketch is present.
- [ ] 15.7 Verify ZGW protocol boundary is documented.
- [ ] 15.8 Verify no code is modified.
- [ ] 15.9 Run `openspec validate` (if tooling exists) on the new and
  modified specs.

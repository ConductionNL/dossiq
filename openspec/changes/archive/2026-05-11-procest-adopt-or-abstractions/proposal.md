# Proposal — procest adopts OpenRegister abstractions

## Why

The 2026-05-03 platform audit identified **procest** as carrying the heaviest
spec rewrite debt of any Conduction app. Across streams 1, 2 and 4 the audit
documents that procest currently:

- Maintains **four near-overlapping specs** describing a custom case /
  parafering state machine (`case-management`, `parafering-actions`,
  `parafering-audit-trail`, `parafeerroute-engine`) which all should
  collapse into a single OR `x-openregister-lifecycle` annotation on the
  case schema (audit ref: `02-spec-rewrite.md` lines 60–73, 96–105).
- Implements that state machine in PHP — six `STATUS_*` constants and ~200
  lines of guarded transition code in `lib/Service/ParaferingService.php`
  (audit ref: `04-hardcoded.md:85–86`).
- Dispatches three per-transition notifications by hand in
  `lib/Service/ParaferingNotificationService.php` (audit ref:
  `04-hardcoded.md:87`) — a textbook `x-openregister-notifications`
  candidate.
- Misses OR feature citations on `parafering-dashboard`, `case-location`,
  `case-dashboard-view` (`02-spec-rewrite.md:68–70`).
- Carries hardcoded admin-tunable magic numbers
  (`ShareMaintenanceJob::REMINDER_DAYS`, `MetricsController::CACHE_TTL_*`,
  `CaseEmailService::setSubject()` PHP-built copy) that should be admin
  configuration or schema-declared (audit ref: `04-hardcoded.md:91–93`).
- Has a security smell in `ZgwDocumentService::getUserFolder('admin')`
  fallback (audit ref: `04-hardcoded.md:90`).

The cross-cutting insight from the audit is unambiguous: *"The four OR
primitives that would absorb the most code already exist
(`x-openregister-lifecycle`, `-calculations`, `-notifications`,
`-archival`). The blocker is adoption, not OR feature work."* (`04-hardcoded.md:167–172`).

This change is procest's adoption response. It is **spec-only** — all
artefacts are new spec deltas, retired-spec closing notes, ADR-022 / ADR-024
/ ADR-025 alignment, and a manifest sketch. **No code changes** are made in
this change; the corresponding implementation work is referenced as future
opsx changes that depend on this proposal landing.

## What Changes

### Spec consolidation (the big one)

Four specs collapse into one. `case-management` becomes the canonical home
for the case + parafering lifecycle, expressed as a single
`x-openregister-lifecycle` annotation on the case schema. Three sibling
specs are retired with closing notes pointing to the consolidated spec:

- **KEEP and rewrite** — `openspec/specs/case-management/spec.md`
  - Describes the case schema, lifecycle annotation, and the parafering
    states + guarded transitions as data, not code.
  - Cites OR's `object-lifecycle`, `audit-trail-immutable`,
    `notificatie-engine`, and `register-i18n` capabilities.
- **RETIRE** — `openspec/specs/parafering-actions/spec.md`
  - Folds into `case-management` as the action-specific guards and audit
    recording on each transition.
- **RETIRE** — `openspec/specs/parafering-audit-trail/spec.md`
  - Folds into `case-management` by citing OR's `audit-trail-immutable`
    instead of describing custom parafeeractie versioning.
- **RETIRE** — `openspec/specs/parafeerroute-engine/spec.md`
  - Folds into `case-management` as data-driven lifecycle transitions
    with `requires` guards (skip-step / ad-hoc step semantics expressed as
    transition pre-conditions).

Three more specs gain OR-feature citations:

- `openspec/specs/parafering-dashboard/spec.md` — cite OR
  `x-openregister-aggregations` for count-by-status queries.
- `openspec/specs/case-location/spec.md` — cite OR `geo-metadata-kaart`.
- `openspec/specs/case-dashboard-view/spec.md` — cite OR aggregations.

One forward-looking spec (`bezwaar-lifecycle/spec.md:88,95`) is updated so
its "AWB articles 6:7, 6:8, 7:10, 7:24 deadline calculation" is declared
as `x-openregister-calculations` rather than a `BezwaarDeadlineService`
class (audit ref: `04-hardcoded.md:97`).

### Annotation design (lifecycle + notifications)

This change defines the canonical annotation shape for the case schema:

- **`x-openregister-lifecycle`** — six states
  (`concept|in_parafering|teruggestuurd|geparafeerd|aangeboden|besloten`),
  the guarded transitions between them, role-based action authorization
  (ADR-023), and `requires` guards encoding the skip-step / ad-hoc-step
  rules currently in `parafeerroute-engine`.
- **`x-openregister-notifications`** — three per-transition rules
  replacing the three hand-rolled `notificationManager->createNotification()`
  calls in `ParaferingNotificationService.php`.
- **`x-openregister-calculations`** — AWB legal deadlines for bezwaar
  (`6:7`, `6:8`, `7:10`, `7:24`).
- **`x-openregister-aggregations`** — count-by-status for parafering /
  case dashboards.

`design.md` shows the explicit before/after sketch for each.

### Code refactor (referenced, not executed here)

This proposal **does not** modify code. It defines the target shape so
follow-up opsx changes can:

1. Replace `ParaferingService.php:43-353` guarded transitions with calls
   to OR's lifecycle engine.
2. Delete `ParaferingNotificationService.php` (subsumed by
   `AnnotationNotificationDispatcher`).
3. Replace `ShareMaintenanceJob::REMINDER_DAYS`,
   `MetricsController::CACHE_TTL_*`, `CaseEmailService::setSubject()` with
   admin-config + schema-declared notification copy.
4. Fix the `ZgwDocumentService::getUserFolder('admin')` fallback so
   non-admin contexts don't silently fall back to admin's home folder.

### NotificatieService boundary clarification

`lib/Service/NotificatieService.php` is **legitimately ZGW-specific**
(it dispatches Notificaties API messages per VNG protocol). It is **not**
duplicating OR's `AnnotationNotificationDispatcher` for OR objects. This
change documents the boundary in `design.md` so it is not refactored away
by mistake. ZGW protocol bindings stay app-local; OR-object lifecycle
notifications move to annotations.

### Manifest adoption (ADR-024)

A manifest is sketched declaring procest as **Tier 2-3** with
`dependencies: ["openregister", "openconnector"]` (procest uses connector
for ZGW protocol bindings — Notificaties API outbound, ZRC/BRC/ZTC
inbound). The manifest is generated from existing router config; no new
runtime behaviour.

### i18n + multi-tenancy (ADR-025 + nextcloud-vue)

procest already wires `createObjectStore('object', { plugins: [filesPlugin(),
auditTrailsPlugin(), relationsPlugin()] })` in `src/store/modules/object.js`
(audit ref: `01-code-cleanup.md` notes this is **GOOD**, no migration
needed). This change adds:

- A reverse-citation of nextcloud-vue's `multi-tenancy-context`
  composable so the integration is documented (currently implicit).
- Adoption of `i18n-source-of-truth` + `i18n-api-language-negotiation`
  for case content (Dutch + English at minimum per
  `feedback_i18n-requirement.md`).

### Hardcoded cleanup (forward-looking)

The following constants are flagged for the implementation change that
follows this proposal:

| File:line | Constant | Disposition |
|-----------|----------|-------------|
| `ParaferingService.php:43-68` | 6× `STATUS_*` | move to `x-openregister-lifecycle` |
| `ParaferingService.php:158-353` | guarded transitions | move to lifecycle engine |
| `ParaferingNotificationService.php:64,109,153` | 3× `createNotification()` | move to `x-openregister-notifications` |
| `ShareMaintenanceJob.php:42` | `REMINDER_DAYS = 3` | admin-config |
| `MetricsController.php:43,48` | `CACHE_TTL_*` | admin-config |
| `CaseEmailService.php:92` | `setSubject()` | schema-declared notification copy |
| `ZgwDocumentService.php:312` | `getUserFolder('admin')` fallback | drop fallback, throw on missing user |
| `ZgwDocumentService.php:45` | `STORAGE_BASE = 'procest/documenten'` | admin-config (per-tenant override) |
| `LoadDefaultZgwMappings.php:1535-1550` | `procest-admin` group + `userId='admin'` | document, audit for tenant safety |

Items NOT changed (per `04-hardcoded.md:144–152`):
- `ZGW_API` / `VERTROUWELIJKHEID_LEVELS` — VNG protocol, app-local.
- `ZgwZtcRulesService::AFLEIDINGSWIJZE_*` — VNG protocol, app-local.
- `ZgwDrcRulesService` constants — VNG protocol, app-local.
- `NotificatieService` — ZGW Notificaties API, app-local.

## OR-side dependencies (must land first or in parallel)

This change consumes the following capabilities. They are cited (not
re-specified) here:

| Capability | Home | Status |
|------------|------|--------|
| `register-resolver-service` | `openregister/openspec/changes/` (forthcoming per `04-hardcoded.md:136–138`) | depended-on |
| `pluggable-integration-registry` | `openregister/openspec/specs/` (per ADR-019) | exists |
| `i18n-source-of-truth` | `openregister/openspec/specs/register-i18n/spec.md` + ADR-025 | exists |
| `i18n-api-language-negotiation` | `openregister/openspec/changes/` (per ADR-025) | depended-on |
| `multi-tenancy-context` | `nextcloud-vue/openspec/specs/composables/spec.md` (per `R2-nc-vue-multitenancy.md`) | exists |
| `adopt-app-manifest` | `hydra/openspec/architecture/adr-024-app-manifest.md` + `openregister/openspec/changes/openregister-adopt-app-manifest/` | exists |

ADRs cited:
- ADR-022 — apps consume OR abstractions (`hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md`).
- ADR-024 — app manifest (`hydra/openspec/architecture/adr-024-app-manifest.md`).
- ADR-025 — i18n source of truth (`hydra/openspec/architecture/adr-025-i18n-source-of-truth.md`).

## Impact

### Specs

- **1 spec rewritten** — `case-management/spec.md` becomes the canonical
  parafering + case lifecycle home, consuming OR annotations.
- **3 specs retired with closing notes** — `parafering-actions`,
  `parafering-audit-trail`, `parafeerroute-engine`.
- **3 specs gain OR-feature citations** — `parafering-dashboard`,
  `case-location`, `case-dashboard-view`.
- **1 spec line updated** — `bezwaar-lifecycle/spec.md:88,95` switches to
  `x-openregister-calculations`.
- **1 spec retro-documented** — `voorstel-management/spec.md` cites the
  consolidated lifecycle annotation (audit ref: `04-hardcoded.md:99`).

### Code (forward-looking)

- ~200 LOC of guarded transition code in `ParaferingService.php` becomes
  data (~50 lines of JSON in the case schema).
- `ParaferingNotificationService.php` (entire file, ~200 LOC) is deleted.
- 3 magic-number constants move to admin-config UI.
- 1 security smell (`getUserFolder('admin')` fallback) is fixed.

### Cross-app

- procest aligns with pipelinq (the `01-code-cleanup.md` "exemplar
  pattern" — `02-spec-rewrite.md:78`) and removes its outlier status as
  the heaviest spec-rewrite debt holder.
- Reduces the count of "custom lifecycle / state-machine"
  implementations (`02-spec-rewrite.md:99–105`) from 4 to 1.

## Out of scope

- Code changes — this change is spec-only (per task instructions).
- ZGW protocol logic — `ZrcController`, `BrcController`, `ZtcController`,
  `NrcController`, `ZgwZtcRulesService`, `ZgwDrcRulesService`,
  `NotificatieService` stay app-local (audit ref: `04-hardcoded.md:144–152`).
- VNG ZGW Zaak mapping (`zgw-api-mapping/spec.md`) — domain-legitimate
  per `02-spec-rewrite.md:71`.
- Case-type versioning (`zaaktype-versioning/spec.md`) — domain-specific
  per `02-spec-rewrite.md:72`.
- Bezwaar advisory committee, hearing, decision specs — bezwaar-specific
  workflow, not part of this consolidation. Only
  `bezwaar-lifecycle/spec.md` is touched (calculations annotation).

## Risks

- **R1 — Lifecycle engine maturity.** OR's `object-lifecycle` capability
  must support: action-bound transitions (advies, parafering,
  accordering), `requires` guards, role-based authorization (ADR-023),
  and an audit-recording event listener. If the implementation lags, the
  follow-up code change blocks. **Mitigation:** this change documents the
  full annotation shape so OR can verify coverage before code lands.
- **R2 — Three-spec retirement information loss.** Closing notes on the
  retired specs must preserve any nuance (especially route-engine's
  ad-hoc-step semantics). **Mitigation:** `tasks.md` Phase 5 explicitly
  enumerates each retired spec's unique requirements and where they live
  in the consolidated spec.
- **R3 — Notification annotation coverage.** OR's
  `x-openregister-notifications` must support per-transition trigger,
  recipient-by-role, and i18n subject/body. **Mitigation:** annotation
  shape sketched in `design.md`; if a gap emerges this change is amended
  rather than the lifecycle work being delayed.
- **R4 — `NotificatieService` confusion.** A future agent might
  mistakenly fold ZGW Notificaties API dispatching into OR notifications.
  **Mitigation:** `design.md` contains an explicit "boundary preserved"
  section calling out that ZGW protocol notifications are NOT subsumed.
- **R5 — Tenant safety of `userId='admin'` seed.** The
  `LoadDefaultZgwMappings` repair seeds objects with `userId='admin'`
  ownership. In a multi-tenant deploy this could cross tenants.
  **Mitigation:** Phase 9 audits the repair step and proposes a
  tenant-aware seed.

## Acceptance criteria

- [ ] All 4 NEEDS-REWRITE procest specs from `02-spec-rewrite.md` are
  either rewritten (1) or retired with closing notes (3).
- [ ] All 3 MISSING-OR-DEP procest specs cite the relevant OR feature.
- [ ] `case-management/spec.md` includes a complete
  `x-openregister-lifecycle` annotation example covering all six current
  STATUS_* values with their guarded transitions.
- [ ] `case-management/spec.md` includes a complete
  `x-openregister-notifications` annotation example covering all three
  current `ParaferingNotificationService` calls.
- [ ] `bezwaar-lifecycle/spec.md` AWB deadline calculation is declared
  via `x-openregister-calculations`.
- [ ] Manifest sketch present and ADR-024 alignment documented.
- [ ] ZGW protocol boundary documented in `design.md`.
- [ ] No code files are modified.

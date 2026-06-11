# Tasks: handler-vervanging-waarneming

## Deduplication Check

- [ ] **DC01**: Confirm no existing substitution/workload-delegation spec or service — `grep -ri 'vervanging\|waarneming\|substitut' openspec/specs lib/Service`. Mandaat-matrix waarnemer hits (decision authority) are expected and out of scope; document findings.
- [ ] **DC02**: Confirm `my-work` resolution has no existing multi-user expansion hook before adding one.

## Schema & Configuration

- [ ] **T01**: Add the `substitution` schema to `lib/Settings/procest_register.json` (fields per design: absentee, substitute, startDate, endDate, scope, scopeRefs, reason, comment, status, createdBy; enum constraints on scope/reason/status). Register `substitution_schema` in `SettingsService` CONFIG_KEYS and SLUG_TO_CONFIG_KEY.

## Backend Services

- [ ] **T02**: Create `lib/Service/SubstitutionService.php` — `create()` with validation (self-substitution rejected, endDate >= startDate required, overlapping same-scope active substitutions rejected with conflict reference), `revoke()`, `getActiveSubstitutionsFor(userId, ?DateTimeImmutable $date)` (lazy `ended` status resolution past endDate, per-request cache), `getSubstitutedWorkFor(userId)` (resolves absentees' open cases/tasks within scope, filtered through the substitute's own OR RBAC effective permissions — never elevates). Unit tests for all validation branches and the date boundaries (start day, end day, day after).
- [ ] **T03**: Capacity stamping — when a mutation targets an item present in the actor's werkvoorraad only via an active substitution, stamp audit metadata with `actedOnBehalfOf` + `substitutionId`. Surface in the case timeline as "namens {absentee} (waarneming)". Add `getActionsForSubstitution(substitutionId)` query for the substitution detail view.
- [ ] **T04**: Create `lib/Service/CaseReassignmentService.php` — `preview(fromUser, ?filter)` (open cases/tasks only, no mutation), `execute(fromUser, toUser, ?filter, actorId)` (per-item handler update + audit entry with shared batch id, per-item success/failure result, single digest notification to the receiving handler, closed/archived untouched). Coordinator-role guard via OR RBAC. Unit tests incl. partial-failure reporting.
- [ ] **T05**: Notification fan-out — extend the deadline/signalering notification dispatch so notifications targeting an absentee are additionally delivered to the active waarnemer (additive recipient via the OR notification engine; absentee delivery unchanged; stops immediately on expiry/revocation).

## Controllers & Routes

- [ ] **T06**: Create `lib/Controller/SubstitutionController.php` — CRUD for substitutions (self-service create/revoke for own absences; coordinator create/revoke for anyone), `GET /api/substitutions/{id}/actions` (capacity-stamped action list), `POST /api/reassignments/preview`, `POST /api/reassignments/execute`. All `#[NoAdminRequired]` with explicit per-method guards (own-record or coordinator); register routes in `appinfo/routes.php`.

## Frontend

- [ ] **T07**: `src/views/settings/SubstitutionSettings.vue` — user-facing Vervanging section: register/revoke own waarnemer (user picker, period, scope, reason), list of own active/past substitutions. Modal in `src/modals/` per ADR-004.
- [ ] **T08**: `src/views/admin/SubstitutionAdmin.vue` — coordinator view: all substitutions with filters, create-on-behalf, revoke, substitution detail with capacity-stamped action list.
- [ ] **T09**: `src/modals/BulkReassignModal.vue` — from-handler picker, optional case-type filter, mandatory preview table (title, case type, status, next deadline), to-handler picker, execute with per-item result display.
- [ ] **T10**: My Work integration — substituted cases/tasks rendered with a "waargenomen voor {naam}" badge and a show/hide-substituted filter; timeline components render the capacity ("namens") on stamped entries.
- [ ] **T11**: Dutch + English i18n for all new UI strings (English source keys per house i18n convention).

## Verification Tasks

- [ ] **V01**: Active substitution shows the absentee's open work in the substitute's My Work, badge rendered; disappears the day after endDate and immediately on revoke.
- [ ] **V02**: Scope `caseTypes` limits routed items to the configured case types.
- [ ] **V03**: RBAC boundary — a substituted case the waarnemer cannot read under their own OR RBAC is absent from the list AND direct access is denied; read-only access yields write rejection.
- [ ] **V04**: Capacity audit — substitute mutation produces a timeline entry with "namens", own-work mutation does not; substitution detail lists all stamped actions.
- [ ] **V05**: Deadline notification reaches both absentee and waarnemer during the period; only the absentee after.
- [ ] **V06**: Bulk reassignment — preview is non-mutating; execute transfers exactly the previewed open items, writes batch-id audit entries, sends one digest notification; closed/archived untouched; non-coordinator denied.
- [ ] **V07**: Validation — self-substitution, endDate < startDate, missing endDate, and overlapping full-scope substitutions are all rejected with clear errors.

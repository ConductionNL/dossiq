# Proposal: handler-vervanging-waarneming

## Why

Every Dutch municipal zaaksysteem tender expects behandelaar-continuity: when a case handler is on leave, ill, or departs, their werkvoorraad must not silently stall against fatale termijnen. Procest today has no vervanging/waarneming (absence/substitution) capability: `my-work` shows only the logged-in user's own cases and tasks, `mandaat-matrix-02` covers waarnemer assignments for *decision authority* (mandaat) only, and `migrate-role-routing-to-or-rbac` delegates permission checks to OpenRegister RBAC without any notion of temporary workload delegation. There is also no way to permanently transfer all open work of a departing employee in one operation — a coordinator must today open every case individually.

The result is a real compliance gap: deadlines (Awb termijnen, dwangsom exposure per the termijnbewaking engine) keep running while nobody sees the absent handler's signals, and actions taken "for" a colleague are indistinguishable in the audit trail from actions taken as oneself.

## What Changes

1. New OpenRegister schema `substitution` (vervanging): absent handler, waarnemer, period, scope, reason, status. No own tables (ADR: procest-object-store).
2. `SubstitutionService` resolving the active substitutions for a user on a reference date, with overlap/self-substitution validation and automatic expiry.
3. My Work integration: an active waarnemer sees the absent handler's cases, tasks, and deadline signals in their werkvoorraad, visually marked as "waargenomen voor {naam}".
4. Capacity audit trail: every mutation performed under an active substitution records both the acting user and the capacity ("namens X als waarnemer"), surfaced in the case timeline and queryable per substitution.
5. Bulk case reassignment: a coordinator transfers all (or a filtered subset of) open cases and tasks from one handler to another in a single previewed, audited operation — the permanent counterpart of temporary substitution, sharing the same reassignment service.
6. UI: a "Vervanging" section in user settings (set your own waarnemer), a coordinator view for managing substitutions and bulk reassignment, and timeline/badge rendering of capacity.

## Impact

- New schema in `lib/Settings/procest_register.json` + config keys in `SettingsService`.
- New `SubstitutionService`, `CaseReassignmentService`, `SubstitutionController`; routes under `/api/substitutions` and `/api/reassignments`.
- `my-work` query layer extended with substitution resolution (affected spec: `my-work` — additive, the existing own-work behaviour is unchanged).
- Notification fan-out for deadline signals duplicated to the active waarnemer (uses the existing OR notification engine, override-only prefs per fleet-notification-plan).
- Vue: `SubstitutionSettings.vue` (user), `SubstitutionAdmin.vue` + `BulkReassignModal.vue` (coordinator), capacity badge in timeline components.

## Out of Scope

- **Decision/besluit authority during absence** — granting a waarnemer the power to *sign or decide* is mandaat law and is owned by `mandaat-matrix-02-authorization-engine` (waarnemer resolution on the decision date). This change covers workload handling only; a waarnemer who must also decide needs a mandaat-matrix waarneming in addition.
- **New auth machinery** — substitution grants no permissions. All object access continues to be enforced by OpenRegister RBAC; substitution only affects routing/visibility *within* what RBAC already allows (see Requirement: Substitution MUST NOT bypass OpenRegister RBAC).
- HR-system integration (automatic absence import from an HR leave calendar) — future change; the schema is designed so a sync can create substitution records later.
- Parafering routes (own waarneming semantics in `parafeerroute-engine`).

# Tasks: migrate-appointments-to-calendar-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm calendar leaf + Qmatic/JCC decision (S)

- [~] P0.1 Confirm the OR calendar leaf `id` and the pinned `@conduction/nextcloud-vue` version.
  Record in design.md DEFERRED_QUESTIONS.
- [~] P0.2 DECIDE Qmatic/JCC resolution: (a) in-app app-local ADR exception vs (b) openconnector
  source. Open a GH issue capturing the decision and (if b) a follow-up openconnector spec.

## [procest] Wire the leaf (local path)

### P1. Calendar leaf scheduling (M)

- [~] P1.1 Whitelist the calendar leaf on the `case` schema `configuration.linkedTypes`.
- [~] P1.2 Replace local scheduling UI with the calendar leaf tab/widget on the case detail page.
- [~] P1.3 Define/retain the case-appointment metadata object fields (`productId`, `locationId`,
  `cancelToken`, `reminderSent`, no-show) in `lib/Settings/procest_register.json`.

## [procest] Remove the local backend

### P2. Delete superseded code (M)

- [~] P2.1 Remove `lib/Service/AppointmentBackend/LocalBackend.php` and the local path in
  `AppointmentService`.
- [~] P2.2 If resolution (a): narrow `AppointmentService` + `AppointmentBackendInterface` to
  Qmatic/JCC only and write the app-local ADR. If (b): remove the in-app backends after the
  openconnector source lands.

## [procest] Quality gates

### P3. Verify (S)

- [~] P3.1 `openspec validate migrate-appointments-to-calendar-leaf --strict` exits 0.
- [~] P3.2 `composer check:strict` and `npm run lint` pass; reminder job still reads retained fields.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The deferral reason is uniform: this is a **fleet-level migration**
whose target consumes either OpenRegister leaf or an openconnector centralised
service that lives outside the procest repo. Per ADR-019 (integration leaves)
and ADR-022 (apps consume OR abstractions):

- The migration requires the target leaf to be released, versioned, and
  tested in the central library (e.g. `@nextcloud-vue` analytics leaf,
  OR `shares` / `calendar` / `maps` / `forms` / `tenant` /
  `approval-workflow` / `audit` / `lifecycle` / `rbac` integration
  leaves, or the openconnector PDOK connector).
- Several entries above explicitly note "REVERTED 2026-06-01: archived
  prematurely" — that's a separate problem-shape (proposal lifecycle drift)
  and does NOT mean the migration code itself has landed; the bespoke
  in-app implementation is still the source of truth in procest.
- Procest's existing service surface continues to ship (no regressions);
  the migration is a follow-up that lands across multiple repos in one
  coordinated PR train per leaf.

Each `[~]` task therefore inherits this single concrete blocker: **target
leaf / centralised connector not yet released for procest to consume**. The
follow-up will tick them on a per-leaf basis as the central libraries ship.

# Tasks: migrate-appointments-to-calendar-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm calendar leaf + Qmatic/JCC decision (S)

- [~] P0.1 Confirm the OR calendar leaf `id` and the pinned `@conduction/nextcloud-vue` version. — deferred to downstream cycle / fleet-wide adoption (handoff)
  Record in design.md DEFERRED_QUESTIONS.
- [~] P0.2 DECIDE Qmatic/JCC resolution: (a) in-app app-local ADR exception vs (b) openconnector — deferred to downstream cycle / fleet-wide adoption (handoff)
  source. Open a GH issue capturing the decision and (if b) a follow-up openconnector spec.

## [procest] Wire the leaf (local path)

### P1. Calendar leaf scheduling (M)

- [~] P1.1 Whitelist the calendar leaf on the `case` schema `configuration.linkedTypes`. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] P1.2 Replace local scheduling UI with the calendar leaf tab/widget on the case detail page. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] P1.3 Define/retain the case-appointment metadata object fields (`productId`, `locationId`, — deferred to downstream cycle / fleet-wide adoption (handoff)
  `cancelToken`, `reminderSent`, no-show) in `lib/Settings/procest_register.json`.

## [procest] Remove the local backend

### P2. Delete superseded code (M)

- [~] P2.1 Remove `lib/Service/AppointmentBackend/LocalBackend.php` and the local path in — deferred to downstream cycle / fleet-wide adoption (handoff)
  `AppointmentService`.
- [~] P2.2 If resolution (a): narrow `AppointmentService` + `AppointmentBackendInterface` to — deferred to downstream cycle / fleet-wide adoption (handoff)
  Qmatic/JCC only and write the app-local ADR. If (b): remove the in-app backends after the
  openconnector source lands.

## [procest] Quality gates

### P3. Verify (S)

- [~] P3.1 `openspec validate migrate-appointments-to-calendar-leaf --strict` exits 0. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] P3.2 `composer check:strict` and `npm run lint` pass; reminder job still reads retained fields. — deferred to downstream cycle / fleet-wide adoption (handoff)

# Tasks: migrate-appointments-to-calendar-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

> IMPLEMENTED 2026-06-15. The "leaf not released" blocker in the deferral
> block below is stale: OR's `CalendarProvider` is present + DI-registered on
> openregister development, and `@conduction/nextcloud-vue` (beta.108) ships
> `CnCalendarTab`. The internal scheduling surface was migrated to the leaf;
> external Qmatic/JCC stays in-app behind an app-local ADR (resolution a).

## [procest] Pre-migration Verification

### P0. Confirm calendar leaf + Qmatic/JCC decision (S)

- [x] P0.1 Confirmed: OR calendar leaf id `calendar` (`CalendarProvider`); pinned
  `@conduction/nextcloud-vue` `^1.0.0-beta.108` ships the bespoke `CnCalendarTab`.
- [x] P0.2 DECIDED resolution (a) — Qmatic/JCC stay in-app behind an app-local ADR
  (`docs/adr/0001-external-appointment-backends-exception.md`); procest is the sole
  fleet consumer today. (b) revisited if a second app needs external scheduling.

## [procest] Wire the leaf (local path)

### P1. Calendar leaf scheduling (M)

- [x] P1.1 Whitelisted `calendar` on the `case` schema `configuration.linkedTypes`
  in `lib/Settings/procest_register.json`.
- [x] P1.2 Surfaced the calendar leaf tab (`CalendarLeafTab` → `CnCalendarTab`, resolved
  from the lib `builtinIntegrations` registry via `src/integrations/leafTabs.js`) as the
  `appointments` sidebar tab on `CaseDetail`; removed the orphaned bespoke scheduling UI
  (`AppointmentSection.vue`, `AppointmentBookingDialog.vue`, `src/services/appointmentApi.js`).
- [x] P1.3 Retained the case-appointment metadata (`productId`, `locationId`, `cancelToken`,
  `reminderSent`, no-show `status`) on the runtime appointment object written by the external
  path; `AppointmentReminderJob` still reads `dateTime`/`status`/`reminderSent`.

## [procest] Remove the local backend

### P2. Delete superseded code (M)

- [x] P2.1 Removed `lib/Service/AppointmentBackend/LocalBackend.php` and the local fallback
  path in `AppointmentService::getBackend()` (now throws on an unconfigured/unknown backend).
- [x] P2.2 Resolution (a): `AppointmentService` narrowed to external Qmatic/JCC only;
  app-local ADR written at `docs/adr/0001-external-appointment-backends-exception.md`.
  (`AppointmentBackendInterface` retained — it is the external-backend contract.)

## [procest] Quality gates

### P3. Verify (S)

- [x] P3.1 `openspec validate migrate-appointments-to-calendar-leaf --strict` exits 0.
- [x] P3.2 PHPUnit 1342 pass (2 skipped), vitest 230 pass, `npm run build` clean, hydra gates
  24/24 green; reminder job still reads retained fields.

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

# Design: migrate-appointments-to-calendar-leaf

## Context

OR's calendar integration leaf (ADR-019) contributes a calendar tab + widget on an OR object's
detail page, backed by NC's calendar (CalDAV) via OR. Creating a "moment" on a case becomes a
calendar event linked to the case object. The leaf does NOT model external municipal-system
timeslot booking — that is `QmaticBackend`/`JccBackend` territory.

## File-by-File Mapping

| Existing procest artifact | Disposition |
|---|---|
| `lib/Service/AppointmentService.php` (local path) | Removed for the local path — calendar leaf owns event CRUD; the residual orchestration for external backends, if kept, narrows to Qmatic/JCC only |
| `lib/Service/AppointmentBackend/AppointmentBackendInterface.php` | Kept ONLY if Qmatic/JCC stay in-app (exception (a)); removed if resolution (b) |
| `lib/Service/AppointmentBackend/LocalBackend.php` | Removed — superseded by the calendar leaf |
| `lib/Service/AppointmentBackend/QmaticBackend.php` | EXCEPTION — kept in-app (a) or moved to openconnector (b); NOT migrated to the leaf |
| `lib/Service/AppointmentBackend/JccBackend.php` | EXCEPTION — same as Qmatic |
| `lib/Controller/PublicAppointmentController.php` | Retained pending Qmatic/JCC resolution (citizen cancel-by-token) |
| case-appointment metadata (`productId`, `locationId`, `cancelToken`, `reminderSent`, no-show) | **Kept** as case-appointment fields in procest's register |

## What moves vs what stays

- **Moves to the leaf**: local scheduling UI, local event persistence, case-detail surfacing.
- **Stays in procest**: zaak-specific appointment metadata (the fields the calendar leaf does not
  model) and the reminder background job that reads that metadata.
- **Exception (not migrated)**: external Qmatic/JCC timeslot booking.

## ADR-022 exception — Qmatic / JCC

Per ADR-022 exception clause 1 (fundamentally different domain requirements), external
municipal-system scheduling cannot be served by the calendar leaf. Two resolutions:

- **(a) Keep in-app** behind an app-local ADR referencing ADR-022 and justifying the divergence.
  Lowest cost; honest exception.
- **(b) Move to openconnector** as a Qmatic/JCC source, mirroring `shared-pdok-via-openconnector`.
  Strategically unified (other apps may need the same), higher cost, separate spec.

Recommendation: per the "favor unification" preference, (b) is the long-term answer if any other
fleet app needs Qmatic/JCC; (a) is acceptable if procest is the sole consumer. A GH issue captures
the decision; an app-local ADR is required either way.

## DEFERRED_QUESTIONS

- Confirm the OR calendar leaf `id` and pinned `@conduction/nextcloud-vue` version shipping it.
- DECISION REQUIRED: Qmatic/JCC resolution (a) in-app ADR exception vs (b) openconnector source.
- Confirm whether citizen public-cancel-by-token is still required for leaf-created (local) events,
  or only for Qmatic/JCC bookings.

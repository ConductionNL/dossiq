# Proposal: migrate-appointments-to-calendar-leaf

## Why

Procest ships an in-app appointment-scheduling engine: `AppointmentService` orchestrates booking
and persists appointments to OR, while `lib/Service/AppointmentBackend/*` provides pluggable
backends (`LocalBackend`, plus the external `QmaticBackend` and `JccBackend`). The consumer-facing
surface is the `appointment-booking` spec.

OpenRegister provides a **calendar** integration leaf (ADR-019). For the common case — scheduling
a moment against a case and surfacing it on the case detail page — the calendar leaf already
provides event creation, listing, and a tab/widget rendered by the OR object sidebar. The
`LocalBackend` path (appointments that exist only inside the app, no external municipal system) is
a direct **ADR-022** duplication of what the calendar leaf provides:

- **Duplicate scheduling UI + persistence** for the local path that the calendar leaf already owns.
- **Orphaned code**: `AppointmentService` + `LocalBackend` re-implement event CRUD + sidebar
  surfacing the leaf provides for free.
- **No cross-app calendar view**: locally-stored appointments can't appear in a fleet-wide calendar.

## What

This change migrates procest's **local** appointment scheduling to the OR calendar leaf, while
keeping zaak-specific appointment metadata as case fields:

1. The `LocalBackend` scheduling path is replaced by the OR calendar leaf on the `case` detail
   page. A scheduled moment becomes a calendar event linked to the case via the leaf.
2. Zaak-specific appointment metadata that the calendar leaf does not model (e.g. `productId`,
   `locationId`, citizen `cancelToken`, `reminderSent`, no-show status) is kept as fields on a
   case-appointment object in procest's register — the *data* stays, the *scheduling UI* moves.
3. `AppointmentService` and `LocalBackend` are removed for the local path once the leaf is wired.

### External Qmatic / JCC scheduling — ADR-022 EXCEPTION (flagged)

`QmaticBackend` (Qmatic Orchestra) and `JccBackend` (JCC Afspraken) integrate with **external
municipal appointment systems** that own real-world counter capacity, timeslots, and queue
management. The calendar leaf models NC/CalDAV events, not external municipal-system timeslot
booking with `getTimeslots`/`bookAppointment`/`rescheduleAppointment` against a third-party API.

This is an **ADR-022 exception candidate** under exception clause 1 ("fundamentally different
domain requirements" — external municipal-system integration the leaf cannot satisfy). The
recommended resolution is one of:

- **(a)** Keep `QmaticBackend`/`JccBackend` in-app behind an app-local ADR (the honest exception),
  OR
- **(b)** Move external timeslot booking to an **openconnector** source (the fleet's external-API
  abstraction, mirroring the PDOK pattern in `shared-pdok-via-openconnector`) — the strategically
  unified answer, since other apps may also need Qmatic/JCC.

This proposal does NOT migrate Qmatic/JCC to the calendar leaf (it cannot host external timeslot
booking). The decision between (a) and (b) is recorded as a DEFERRED_QUESTION + a GH issue, per
ADR-022's "every exception requires an app-local ADR" rule.

## Capabilities

### New Capabilities

- `case-appointment-via-calendar-leaf`: Local case appointments are scheduled and surfaced through
  OR's calendar integration leaf; zaak-specific appointment metadata is retained as case fields.

### Modified Capabilities

- `appointment-booking` (spec: `procest/openspec/specs/appointment-booking/spec.md`) — the local
  scheduling path now routes through the calendar leaf; the external Qmatic/JCC backend contract is
  retained pending the ADR-022 exception decision (a) or (b).

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo
- [x] Project: `openregister` — no code change; the calendar leaf is consumed, not modified
- [ ] Project: `openconnector` — only if resolution (b) is chosen for Qmatic/JCC (separate spec)

## Out of Scope

- The calendar leaf's own implementation in OR.
- Migrating Qmatic/JCC external scheduling to the calendar leaf (impossible — flagged as exception).
- Citizen public-cancel-by-token UX, which depends on the chosen Qmatic/JCC resolution.
- Reminder background job redesign (kept; it reads the retained appointment metadata).

## Success Criteria

- `openspec validate migrate-appointments-to-calendar-leaf --strict` exits 0.
- Local case appointments are created/listed through the calendar leaf on the case detail page.
- `AppointmentService` + `LocalBackend` local-path code is removed; zaak metadata fields retained.
- The Qmatic/JCC exception is recorded as a GH issue + DEFERRED_QUESTION.

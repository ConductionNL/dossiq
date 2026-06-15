# ADR 0001 — External appointment backends (Qmatic / JCC) are an ADR-022 exception

- Status: Accepted
- Date: 2026-06-15
- Relates to: ADR-019 (integration leaves), ADR-022 (apps consume OR abstractions)
- Change: `openspec/changes/migrate-appointments-to-calendar-leaf`

## Context

Procest previously shipped a pluggable appointment-scheduling engine
(`AppointmentService` + `AppointmentBackend/{LocalBackend,QmaticBackend,JccBackend}`).
The `LocalBackend` path stored events inside the app and rendered its own
scheduling UI — a direct duplication of what OpenRegister's `calendar`
integration leaf (`CalendarProvider`) already provides.

`migrate-appointments-to-calendar-leaf` moves the **internal** scheduling
surface to the calendar leaf: a case appointment is now a calendar event
created/listed/linked/deleted through the leaf's `CnCalendarTab`, fetched
straight from OpenRegister. `LocalBackend` and the orphaned bespoke Vue
(`AppointmentSection.vue`, `AppointmentBookingDialog.vue`, `appointmentApi.js`)
were removed.

## Decision

`QmaticBackend` (Qmatic Orchestra) and `JccBackend` (JCC Afspraken) stay
in-app. They integrate with **external municipal appointment systems** that
own real-world counter capacity, timeslots and queue management
(`getTimeslots` / `bookAppointment` / `rescheduleAppointment` against a
third-party API). The calendar leaf models NC/CalDAV events, not
external-system timeslot booking, so it cannot host these backends.

This is an **ADR-022 exception under clause 1** ("fundamentally different
domain requirements — external integration the leaf cannot satisfy").

Resolution **(a) keep in-app** is chosen over **(b) move to openconnector**
because procest is currently the sole fleet consumer of Qmatic/JCC. Should a
second app need external municipal scheduling, this decision is revisited in
favour of (b) — an openconnector source mirroring `shared-pdok-via-openconnector`.

## Consequences

- `AppointmentService` is narrowed to external backends only; there is no
  local fallback. An unconfigured/unknown backend now throws a configuration
  error instead of silently scheduling locally.
- Zaak-specific appointment metadata the leaf does not model (`productId`,
  `locationId`, `cancelToken`, `reminderSent`, no-show status) is retained on
  the appointment object in procest's register, and `AppointmentReminderJob`
  continues to read it.
- The citizen public cancel-by-token surface (`PublicAppointmentController`)
  is retained for external bookings.
- Follow-up: GH issue tracks the (a)→(b) re-evaluation trigger.

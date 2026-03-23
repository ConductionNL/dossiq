# Proposal: Appointment Scheduling

## Summary

Integrate appointment scheduling (afsprakenbeheer) into Procest case flows. Citizens book balie appointments as part of case submission. Pluggable backends (JCC, Qmatic) with a local fallback. Self-service cancellation and rescheduling.

## Problem

Cases requiring physical service delivery (passport, marriage, permit discussion) currently require manual phone/email appointment coordination. This adds administrative overhead and loses the audit trail connection between the appointment and the case.

## Scope -- MVP

**In scope:**
- Appointment entity in OpenRegister with case linkage
- Appointment booking from case detail (case worker) and intake flow (citizen)
- Plugin interface for appointment backends (JCC Afspraken, Qmatic)
- Local fallback when no backend is configured
- Citizen self-service: cancel and reschedule via token link
- Appointment reminders via Nextcloud background jobs
- Appointment events in case timeline
- No-show tracking
- Products and locations configuration in admin settings

**Out of scope:**
- Nextcloud Calendar integration (V1)
- SMS notifications (email only for MVP)
- Queue management (real-time queue position)

## Dependencies

- OpenRegister for appointment storage
- OpenConnector for external backend adapters (JCC, Qmatic)
- NotificatieService for reminders

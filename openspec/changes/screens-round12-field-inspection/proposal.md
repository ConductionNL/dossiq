# The field inspection view

## Why

Board DqInspectie shows what an inspector sees on the phone: the day's
inspections available offline, the checklist in progress, and the changes
waiting to synchronise. The records exist (`fieldInspection`,
`inspectionChecklistRun`, `syncQueue`); no page showed them together.

## What changes

- `FieldInspection` (`/field-inspection`), a dashboard page whose one widget
  is nextcloud-vue's `device-frames` (L32, #1733): three phone-sized frames for
  Today (today's inspections for the reader plus the offline note), Checklist
  (the run in execution) and Synchronise (the outbox items still pending, in
  conflict or failed).

## Waits on

- nextcloud-vue #1733 for the frames; until it ships the page shows its header only.
- A checklist question widget (question, answers, photo with its location) for
  the middle frame; the frame lists the run in execution meanwhile.

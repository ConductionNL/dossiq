# Five background jobs, decided

## Summary

dossiq#3348 moved twelve jobs onto the background service account and stopped on five, because moving them would make something worse. Ruben decided each one (2026-10-07/08). Auto-close stays off until a case type opts in, and the account may only abort cases of those types. The daily digest is composed as its recipient and only written as the account. The appointment reminder and the Berichtenbox read-status poll are removed, because neither had anything real to do. The PDF retry is parked until the filinq adapter exists. The DSO deadline job filtered on words where the case stores uuids, so it never acted; it now finds open DSO cases by `dsoStatus`.

## Why

- **Auto-close.** Under the account, auto-abort becomes live. A case type that only declared a period never closed anything before, because every close was refused as Anonymous. Without an explicit opt-in, an upgrade would start closing those cases.
- **Digest.** Composed as nobody, the digest read nothing. Composed as the account, it could list cases the recipient cannot open.
- **Appointment reminder.** Never scheduled, no `appointment` schema, it sent nothing and only set a "reminder sent" flag.
- **Berichtenbox read status.** Logius Berichtenbox has no read status (integriq spec `berichtenbox-client`). The poll, the adapter method, the route and the daily job had nothing to ask.
- **PDF retry.** No filinq adapter yet. Scheduled, it burns three retries and drops every failed archival row.
- **DSO deadline.** `caseType = omgevingsvergunning` and `status in [submitted, in_handling]` never match a stored case.

## What changes

- `caseType.autoCloseOnSilence` (boolean, default false). `LifecycleCaseTypeRules::silenceDays()` answers 0 unless it is true. `LifecycleActorGate` lets the background account abort only a case of an opted-in type, and never finish or archive. OpenRegister cannot scope the case grant per case type, so dossiq enforces it.
- `AutoCloseOnSilenceJob` runs as the background account.
- `DailyDigestJob` composes each digest with the recipient as the volatile active user, restored in `finally`, and writes `workDigest` as the account.
- Removed: `AppointmentReminderJob`, `reminderSent`, `appointment_reminder_days`, `BerichtenboxReadStatusJob`, `pollReadStatus`, `getPendingMessages`, `getCaseIdForMessage`, `getReadStatus` on the adapter seam, `berichtenbox#poll`.
- `EmailPdfRetryJob` leaves `appinfo/info.xml`. Repair step `RetireUnscheduledBackgroundJobs` removes it and the two removed jobs from the job list.
- `DsoDeadlineJob` filters on `dsoStatus`, reads `assignee`, and patches `deadlineOverdue` (now declared) plus a journal entry.

## Impact

Nothing closes on an existing instance after the upgrade. A case type closes silent cases only after an administrator switches `autoCloseOnSilence` on.

# Proposal: letters-name-the-case-number

## Summary

Every letter to a requester names the case by its number, such as 2026-0018. The information request, the reminder on a paused term, the inadmissibility letter and the queued term notices no longer show the case UUID.

## Why

Live round 3 of the Zuiddrecht demo (14 Oct) showed the case UUID in letters where the case number belongs. PR #3915 fixed the receipt. The proposal of that change named the rest as a follow-up:

- The information request (`InformationRequestService::ask()`) and the pause reminder (`PauseChaseService`) pass the case UUID as `case`, because they hold no case row.
- The inadmissibility letter and the other decision messages (`ApplicantMessage::send()`) read `identification`, a field the case schema does not have, so they always fell back to the UUID.

## What changes

- `CaseNumberReader` answers the number of a case from its UUID, read as the system.
- `TermijnNotificationService::sendTermijnNotification()` renders every letter with the case number when the reference it was handed is a UUID. A reference that is already a number is left alone. The opt-out and the delivery record keep the UUID.
- `ApplicantMessage::send()` reads `identifier`, the field the case carries.

## Out of scope

The PDF document templates (filinq generate) read their own merge fields and are not touched.

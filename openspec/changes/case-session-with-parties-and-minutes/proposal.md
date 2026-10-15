# Case session with parties and minutes (C12)

## Why

ADR-118 (decisions 182, 184, 185) says a procedure is configuration and code is generic. Eight dossiq classes run one kind of session for one procedure: `Bezwaar\HearingService`, `HearingSchedulePlanner`, `HearingMinutesRecorder`, `HearingService`, `HearingCalendarService`, `ComplaintHearingController`, `BezwaarHearingController` and `BezwaarHearingScheduledListener`. Each hard-codes who is invited, the seven-day inspection floor of Awb art. 7:4, the waiver of art. 7:3, the minutes of art. 7:7, a one-hour attendance window and the status names.

Decision 191 (Ruben, 11 Oct, refactor Q11) settles where the generic version lives: sessions with parties and minutes stay in dossiq, configured per case type through `caseType.sessions[]`. They show in Calendar through OpenRegister's schema calendar configuration, which retires `HearingCalendarService`. They open as decidiq meetings once decidiq's `meeting` carries subject fields (`subjectRegister`, `subjectSchema`, `subjectId`; separate decidiq PR).

This change builds that capability. It is capability C12 in hydra `openspec/changes/procedures-are-configuration/design.md`.

## What changes

- A case type declares its sessions in `sessions[]`: key, label, invitee roles, minimum invitees, inspection floor in days, whether a party may waive, whether minutes and recording consent are required, the attendance grace window in hours, and the status written per step. `SessionDefinition` is the single authority on that shape.
- `SessionService` plans, waives, records attendance at and closes a declared session. Each session record carries `sessionKey` and `title`.
- The session schema (`hearingSession`) gains `sessionKey`, `title` and a `calendarProvider` block, so OpenRegister's virtual calendar shows sessions on their planned start, under the viewer's own rights.

The procedure classes do not move in this change. They move when the bezwaar package ships on R1's installer, and each move deletes its class.

## Not in this change

- The Awb-tagged audit entries the bezwaar classes write (`BezwaarAuditTrail`). OpenRegister's trail records every save of a session; whether the bezwaar package needs the tagged entries on top is decided with C14 (bezwaar audit onto the OpenRegister trail).
- A default session planned when a case opens (`seedDefaultHearing`). That becomes a flow event with C24.
- An HTTP surface. The moves reuse the existing controllers' routes and guards.

## Impact

- New: `lib/Service/Session/SessionDefinition.php`, `lib/Service/Session/SessionService.php`, fragment `lib/Settings/register.d/87-case-type-sessions.json`.
- `caseType` gains `sessions`; `hearingSession` gains `sessionKey`, `title` and `configuration.calendarProvider`.
- No class is deleted here, so `.hydra/procedure-names.json` does not shrink yet.

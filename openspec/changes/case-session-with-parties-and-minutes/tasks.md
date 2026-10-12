# Tasks: case-session-with-parties-and-minutes

- [x] 1.1 `caseType.sessions[]` in the register, validated by `SessionDefinition`
- [x] 1.2 `SessionService`: plan with the inspection floor and invitee roles, waive when declared waivable, attendance with a grace window, minutes and recording consent as declared
- [x] 1.3 `hearingSession.sessionKey`, `title` and `configuration.calendarProvider`
- [x] 1.4 Unit tests on a neutral fixture case type, payloads validated against the real `hearingSession` schema
- [ ] 2.1 decidiq `meeting` gains `subjectRegister`, `subjectSchema`, `subjectId` (decidiq PR), then a session opens as a decidiq meeting
- [ ] 2.2 Bezwaar package declares its hearing session (waits on R1's installer)
- [ ] 2.3 Move `Bezwaar\HearingService`, `HearingSchedulePlanner`, `HearingMinutesRecorder`, `HearingService`, `HearingCalendarService`, `ComplaintHearingController`, `BezwaarHearingController`, `BezwaarHearingScheduledListener` onto this, deleting each class and its unit tests once the package journey passes

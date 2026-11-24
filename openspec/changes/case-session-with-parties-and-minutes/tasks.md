# Tasks: case-session-with-parties-and-minutes

- [x] 1.1 `caseType.sessions[]` in the register, validated by `SessionDefinition`
- [x] 1.2 `SessionService`: plan with the inspection floor and invitee roles, waive when declared waivable, attendance with a grace window, minutes and recording consent as declared
- [x] 1.3 `hearingSession.sessionKey`, `title` and `configuration.calendarProvider`
- [x] 1.4 Unit tests on a neutral fixture case type, payloads validated against the real `hearingSession` schema
- [x] 2.1 decidiq `meeting` gains `subjectRegister`, `subjectSchema`, `subjectId` (decidiq PR), then a session opens as a decidiq meeting
  - decidiq: the fields were already on `Meeting` (fragment `123-meeting-subject.json`); the command seam is decidiq `MeetingRequestedEvent` + `MeetingCommandService` (spec `meeting-requested-event`, REQ-MRE-001..003), keyed on the subject so a repeat moves the one meeting.
  - dossiq: `lib/Service/Session/SessionMeeting.php` dispatches it after `SessionService::schedule()` plans a session and the session keeps the id in `meeting` (fragment 87, register 0.20.102); fails open without decidiq (REQ-CSS-6). Tests: `tests/Unit/Service/Session/SessionMeetingTest.php`, `SessionServiceTest::testAPlannedSessionOpensAsAMeetingAndKeepsItsId` and `testWithoutAMeetingTheSessionIsStillPlanned`.
- [ ] 2.2 Bezwaar package declares its hearing session (waits on R1's installer)
- [ ] 2.3 Move `Bezwaar\HearingService`, `HearingSchedulePlanner`, `HearingMinutesRecorder`, `HearingService`, `HearingCalendarService`, `ComplaintHearingController`, `BezwaarHearingController`, `BezwaarHearingScheduledListener` onto this, deleting each class and its unit tests once the package journey passes

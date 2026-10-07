# Tasks

- [x] 1 Auto-close is off until a case type opts in (`autoCloseOnSilence`), and the background account may abort only those cases
- [x] 2 The daily digest is composed as its recipient and written as the background account
- [x] 3 Remove AppointmentReminderJob and the Berichtenbox read-status poll, with their flags, route and tests
- [x] 4 Park EmailPdfRetryJob and take the retired jobs off the job list on upgrade
- [x] 5 The DSO deadline job finds open DSO cases by `dsoStatus` and patches a declared overdue mark
- [x] 6 Live proof on NC 35 (`dqa-live`)

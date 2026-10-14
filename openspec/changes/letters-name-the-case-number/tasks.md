# Tasks: letters-name-the-case-number

- [x] 1. `CaseNumberReader` reads a case's `identifier` from its UUID, as the system, and answers '' when it cannot.
- [x] 2. `TermijnNotificationService` swaps a UUID case reference for the case number before rendering, for every template.
- [x] 3. `ApplicantMessage::send()` reads `identifier` instead of the non-existent `identification`.
- [x] 4. Unit tests: the information request names the number when handed the UUID; a number is left alone; an unreadable case keeps the reference.

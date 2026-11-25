# Tasks: task-list-subject-links-its-case

- [x] 1.1 Test: the Subject column links a dossiq task to CaseDetail and shows `{subject.identifier}` (`tests/vitest/simpleTaskListSubjectLink.spec.js`, red on development).
- [x] 1.2 `src/menu-layout.simple.json` Tasks Subject column.
- [x] 1.3 Live: DqTaken subjects link to their case with the case number under them on :8080 (needs openregister feat/task-subject-identifier).
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq and opencatalogi development, openregister main, Zuiddrecht set), Dutch locale, as Pieter and Thomas: each subject links to /apps/dossiq/cases/<case uuid>, with the case number (2026-4842, 2026-4845) on the second line.

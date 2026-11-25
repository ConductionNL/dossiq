# Tasks: task-list-states-in-task-words

- [x] 1.1 Test: the Tasks Status column reads `state` with the TaskDetail pill's word for an active task and tones keyed on the state (`tests/vitest/boardPillTones.spec.js`, red on development).
- [x] 1.2 `src/menu-layout.simple.json` Tasks Status column on `state` with `enumLabels`.
- [x] 1.3 Live: DqTaken shows "In uitvoering" on :8080.
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq and opencatalogi development, openregister main, Zuiddrecht set), Dutch locale, as Pieter and Thomas: the Status column reads "In uitvoering" on open tasks and "Beschikbaar" on a pooled one.

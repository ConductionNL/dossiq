# Tasks: task-list-deadline-tones

- [x] 1.1 Test: the Deadline column's due options and the overdue rowClass (`tests/vitest/simpleTaskListDeadlineTones.spec.js`, red on development).
- [x] 1.2 Config + "Done on {date}" en/nl.
- [x] 1.3 Live: DqTaken deadline tones and row edge on :8080 after the nextcloud-vue release.
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq and opencatalogi development, openregister main, Zuiddrecht set), Dutch locale, as Pieter and Thomas: "2 dagen te laat" and "Vandaag" read in the danger tone, a later date neutral; both open rows due today or earlier carry cn-row--overdue (red left edge).

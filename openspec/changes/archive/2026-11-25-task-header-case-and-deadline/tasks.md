# Tasks: task-header-case-and-deadline

- [x] 1.1 Test: the TaskDetail header meta names the case number and the deadline, in Dutch "bij zaak ..., deadline ..." (`tests/vitest/taskDetailManifestPage.spec.js`, red on development).
- [x] 1.2 `headerMeta` + en/nl key.
- [x] 1.3 Live: DqTaak reads "bij zaak 2026-0074, deadline vandaag" on :8080 (needs openregister feat/task-subject-identifier).
  - Live 25 Nov 2026 (NC 34.0.6, OpenRegister main, dossiq development, Zuiddrecht set, nl): the header reads "bij zaak 2026-4842, deadline vandaag" (and "bij zaak 2026-4845, deadline 28 nov" on another task).

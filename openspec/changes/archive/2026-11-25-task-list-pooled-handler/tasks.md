# Tasks: task-list-pooled-handler

- [x] 1.1 Test: the Behandelaar column names the candidate team, nl "{group}, niet geclaimd" (`tests/vitest/simpleTaskListPooledHandler.spec.js`, red before).
- [x] 1.2 Config + en/nl key.
- [x] 1.3 Live: a pooled task reads "Team Woo, niet geclaimd" on :8080 after the nextcloud-vue release.
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq and opencatalogi development, openregister main, Zuiddrecht set), Dutch locale, as Pieter and Thomas: Thomas's pooled, unclaimed task reads "Behandelaars, niet geclaimd" in the Behandelaar column (the group display name of the set; on the board that group is Team Woo).

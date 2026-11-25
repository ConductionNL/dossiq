## 1. Config

- [x] 1.1 `primaryActionByStage.closed` in `src/menu-layout.simple.json` carries the `case-archive` dialog config (test: `tests/vitest/archiveStageOpensCaseArchive.spec.js`, red on development).
- [x] 1.2 Live check on :8080 after merge: Archiveren in the what-now card of a closed case opens "Zaak archiveren" with "Afronden · <zaaknummer>" and the requester checkbox (COMPDIFF DqArchiveren).
  - Live 25 Nov 2026 (NC 34.0.6, OpenRegister main, dossiq development, Zuiddrecht set, nl): on closed Woo case 2026-4871, Archiveren opens "Zaak archiveren" with "Afronden · 2026-4871" and the "Sanne de Vries laten weten" checkbox.
- [x] 1.3 The "Read only afterwards" note shows a lock (`note.icon` LockOutline, nextcloud-vue fix/action-dialog-board-summary-facts; an older library keeps the info icon)
- [x] 1.4 Bewaartermijn: the retention period OpenRegister nominated (`@self._retention.retentionPeriod`) in words (nextcloud-vue fix/action-dialog-board-summary-facts: templates read through the formatters)
- [ ] 1.5 Live 25 Nov 2026: the dialog on case 2026-4871 shows Bewaartermijn "10 jaar" with Vernietigingsdatum "24 nov 2031" (five years). OpenRegister's annotation on the case says `effectiveRetention` P10Y, `expiresAt` 2036-11-25. The two facts must come from the same nomination.

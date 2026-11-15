## 1. Config

- [x] 1.1 `primaryActionByStage.closed` in `src/menu-layout.simple.json` carries the `case-archive` dialog config (test: `tests/vitest/archiveStageOpensCaseArchive.spec.js`, red on development).
- [ ] 1.2 Live check on :8080 after merge: Archiveren in the what-now card of a closed case opens "Zaak archiveren" with "Afronden · <zaaknummer>" and the requester checkbox (COMPDIFF DqArchiveren).
- [x] 1.3 The "Read only afterwards" note shows a lock (`note.icon` LockOutline, nextcloud-vue fix/action-dialog-board-summary-facts; an older library keeps the info icon)
- [x] 1.4 Bewaartermijn: the retention period OpenRegister nominated (`@self._retention.retentionPeriod`) in words (nextcloud-vue fix/action-dialog-board-summary-facts: templates read through the formatters)

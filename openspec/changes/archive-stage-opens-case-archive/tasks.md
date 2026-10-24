## 1. Config

- [x] 1.1 `primaryActionByStage.closed` in `src/menu-layout.simple.json` carries the `case-archive` dialog config (test: `tests/vitest/archiveStageOpensCaseArchive.spec.js`, red on development).
- [ ] 1.2 Live check on :8080 after merge: Archiveren in the what-now card of a closed case opens "Zaak archiveren" with "Afronden · <zaaknummer>" and the requester checkbox (COMPDIFF DqArchiveren).

# Tasks: lists-and-dashboards-as-drawn

- [x] 1.1 Five signal tiles on the Dashboard (simple structure)
  - Files: src/menu-layout.simple.json, l10n/
  - Test: tests/vitest/dashboardSignalTiles.spec.js, tests/vitest/simpleListAndDashboard.spec.js
- [x] 2.1 Ending first on the term dashboard, drawn once its endpoint answers
  - Files: src/manifest.json, l10n/
  - Test: tests/vitest/termijnEndingFirst.spec.js
- [ ] 2.2 GET /api/deadlines/ending-first (SIG, R20/DQB-C/FOR-SIG.md)
- [x] 3.1 The tags filter reads Label on the case list
  - Files: src/manifest.json
  - Test: tests/vitest/casesLabelChip.spec.js
- [x] 4.1 Complaint pattern cards on the process analysis page, drawn once their endpoint answers
  - Files: src/manifest.json, l10n/
  - Test: tests/vitest/processComplaintPatterns.spec.js
- [ ] 4.2 GET /api/complaints/patterns (SIG, R20/DQB-C/FOR-SIG.md)
- [ ] 5.1 Live: the five tiles on /dashboard, Ending first and the complaint cards after SIG lands

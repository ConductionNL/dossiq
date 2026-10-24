# Tasks

- [x] 1.1 `FieldInspection` page with the three frames over existing schemas
- [x] 1.2 Device frames render: nextcloud-vue #1733 (L32) merged on 17 Oct and ships in the ^2.126.0 dossiq requires (`device-frames` in the library widget keys); `72-field-inspection.json` already places the widget
- [x] 1.3 Checklist question with photo: the checklist-run widget over the reader's run in execution (nextcloud-vue #1764/#1766)
- [x] 1.4 Board rows: today's inspections read "{time} · {checklist}", inspection and outbox states in words with the board's tones (Klaar green, Bezig blue, Gepland neutral; Wacht amber, Conflict red); test `tests/vitest/fieldInspectionBoard.spec.js`
- [x] 1.5 The running checklist opens at its first open question (`startAt: "firstOpen"`, nextcloud-vue feat/checklist-run-start-at-first-open; ignored by an older library)
- [x] 1.6 Outbox rows read their `label` ("Molenweg 4, checklist"), the technical `targetEntity` as fallback: `syncQueue` 1.1.0 gains an optional `label` (nextcloud-vue feat/offline-queue-label writes it on the device); example outbox for Pieter (tests: fieldInspectionBoard.spec.js, ZuiddrechtOutboxTest)

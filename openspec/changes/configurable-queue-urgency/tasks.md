# Tasks: configurable-queue-urgency

Kind: code. Decision of 2026-10-09 on board `dossiq/DqAanMijToegewezen`.
Overlap: the term engine lane edits `businessDaysBetween` and
`nearestActiveTermDeadline` in `lib/Service/WorkQueueService.php`. Neither
method is edited here.

## 1. Backend

- [x] 1.1 `lib/Service/Queue/QueueUrgencySettings.php`: read and normalise the
  four IAppConfig keys (D-1, D-2); register the keys in
  `lib/Service/Settings/ConfigKeys.php`.
  - `tests/Unit/Service/Queue/QueueUrgencySettingsTest.php`
- [x] 1.2 `lib/Settings/dossiq_register.json`: `caseType.queueCriticalDays` and
  `caseType.queueWarningDays` (D-2).
  - `tests/Unit/Settings/QueueThresholdSchemaTest.php`
- [x] 1.3 `WorkQueueService::scoreItem()`: thresholds and weights as inputs,
  idle part instead of age, `deadlineTier` instead of `tier` (D-1, D-3, D-5).
- [x] 1.4 `WorkQueueService::queueCaseItems()`: the list's filters plus
  `_limit` 1000, per-case-type thresholds looked up once per type, idle days
  from `@self.updated` and the journal, the case row on each item (D-3, D-4).
  - `tests/Unit/Service/WorkQueueServiceTest.php`
- [x] 1.5 Rename the wire key for every reader: `Queue\PersonalQueueService`,
  `Queue\DailyDigestComposer`, `Queue\QueueOrdering`.
- [x] 1.6 `lib/Settings/AdminSettings.php`: initial state
  `queueUrgencySettings`.

## 2. Frontend

- [ ] 2.1 `src/views/settings/tabs/QueueUrgencySettingsTab.vue` and its
  section in `AdminRoot.vue`; bounds validated before save.
  - `tests/vitest/queueUrgencySettings.spec.js`
- [ ] 2.2 Case type General tab: the two threshold fields.
  - `tests/vitest/caseTypeQueueThresholds.spec.js`
- [ ] 2.3 `src/utils/workQueueHelpers.js`: `deadlineTierPillClass()`, pill
  labels including Normaal, the ranked list builder, search and filter over it,
  the mode resolver with its deadline fallback.
  - `tests/vitest/workQueueHelpers.spec.js`
- [ ] 2.4 `MyWorkCards.vue` / `MyWorkCaseCard.vue`: Urgentie renders the ranked
  rows; Nieuwste keeps the self-fetch; the pill on every card.
- [ ] 2.5 `src/views/queue/PersonalQueueView.vue`: read `deadlineTier`.
- [ ] 2.6 l10n: Late / Te laat, Soon / Bijna, and the section's strings.
- [ ] 2.7 `tests/e2e/configurable-queue-urgency.spec.ts` for the scenarios
  without an exclude.

## 3. Board

- [ ] 3.1 design-system: the Queue urgency section on the dossiq admin settings
  board, in its own PR.

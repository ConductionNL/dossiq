# Tasks: one-date-write-path

Tier: V1. Kind: code. Proposed row 8.22. Reads openregister
`calendar-time-zone` (openregister#3688) once it lands; until then
`tenantConfiguration.timezone` answers. Pairs with dossiq
`terms-on-the-engine-calendar`, which states the zone for term dates.

- [x] 1.1 `tests/Unit/Architecture/OneDateWritePathTest.php` first, red.
  It enumerates the writers and the normalisers, so the count in the
  proposal stops being a number somebody typed (D-1, D-6).
  - `@spec openspec/changes/one-date-write-path/specs/case-management/spec.md`
- [x] 1.2 `lib/Service/CaseDateNormaliser.php`: `toCalendarDate()`,
  `toMoment()`, `parse()`. `parse()` throws on an unreadable value
  (D-2, D-3).
  - `tests/Unit/Service/CaseDateNormaliserTest.php`
- [x] 1.3 The zone resolves inside the normaliser: engine calendar first,
  `tenantConfiguration.timezone` second, `Europe/Amsterdam` last (D-4,
  D-5).
- [x] 2.1 `TermijnController` `hervat`, `verleng` and `voltooi` onto the
  normaliser; `DeadlineExtensionService` stops taking a raw string.
- [x] 2.2 `ZrcController` `create` and `update`: the `datumStatusGezet`
  default and the `endDate` write.
- [x] 2.3 `ComplaintController` through `ComplaintService`, including the
  `date('Y-m-d')` fallback at :301.
- [x] 2.4 `ConsultationController` through `ConsultationService`: the
  three `date('Y-m-d\TH:i:s')` writes gain an offset.
- [x] 2.5 `AdviceController` through `AdviceService`: `deadline` is parsed
  instead of stored verbatim, and a bad value is refused (D-3).
- [x] 2.6 `WOOAssessmentController` through `WOODeadlineService`;
  `parseIsoDate()` and `requireIsoDate()` retire.
- [x] 2.7 `ContactMomentController` through `QuickActionService` and
  `ContactMomentService`: `startDate`, the 42 day deadline and the
  activity timestamps.
- [ ] 2.8 `DwangsomController` through `DwangsomCalculationService`: the
  accrual clock and the day granularity comparison.
- [ ] 2.9 `DwangsomPaymentCallbackController`: its private `parseDate()`
  retires; `actualPaymentDate` goes through the normaliser.
- [ ] 3.1 Retire the remaining private normalisers:
  `CaseEnricher::normaliseDate()`, `WorkQueueService::parseDateOnly()`,
  `TermijnTimerService::dateOrNull()`,
  `ProcessMiningService::parseDate()`,
  `ProcessMining/DwellTimeAnalyzer::parseDate()`,
  `ProcessMining/ThroughputTrendCalculator::parseDate()`,
  `BesluitMigrationService::asDateTime()`.
- [ ] 3.2 The five StUF literals read the tenant zone:
  `StufMessageBuilder`, `Stuf/StufMessageHandler` (two sites),
  `Stuf/StufCaseMappingStore`, `Stuf/ContactBetrokkeneMapper` (D-4).
- [ ] 4.1 `OneDateWritePathTest` green, and it fails on a planted private
  normaliser and a planted zone literal. Prove both plants red before
  removing them (D-6).
- [ ] 4.2 `tests/e2e/one-date-write-path.spec.ts`: one date through all
  nine paths, one stored value out; a Belgian tenant reads Belgian
  offsets (D-7).
- [ ] 4.3 `openspec validate one-date-write-path --strict`.

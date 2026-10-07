---
kind: code
depends_on: [terms-on-the-engine-calendar]
---

# Proposal: one-date-write-path

Competitor gap register, proposed row 8.22 "Do all write paths to a date
field agree, and does the product show one date for that field"
(`procest/_round4/compare/proposed-rows-batch11.md` and
`findings-batch11.md` in ConductionNL/market-intelligence, 2026-09-13).
Rated no for dossiq, from the source. Size M.

Not row 8.15 (a moment or the end of a day) and not Q8.19 (whose time
zone). Given that a product has settled both, do all the endpoints that
write the field actually obey the answer?

## Why

Gitea 1.27.3 stores three different instants for one submitted date.
Create keeps the raw instant, edit makes it end of day in the caller's
zone, and the dedicated deadline endpoint makes it end of day in the
instance's zone. Only the third reads the administered setting. The
result is visible without an API: the issue timeline reads "January 31,
2028" and the sidebar three centimetres away reads "February 1, 2028",
for the same stored value.

Batch 11 wrote that up as an audit instruction for dossiq, not a
competitive row. The audit was run against `development` at `d28e11aa`
and it is worse than the register recorded.

**Nine write paths set a date on a case or a case-linked object.** Each
normalises it its own way, or not at all.

| controller | route | what it writes, and how |
|---|---|---|
| `lib/Controller/TermijnController.php` | `POST /api/termijn/instances/{id}/hervat` :221, `/verleng` :251, `/voltooi` :295 | `hervat` and `voltooi` parse the body into `DateTimeImmutable` with no zone; `verleng` passes `newEinddatum` through as a raw string to `DeadlineExtensionService` :172 |
| `lib/Controller/ZrcController.php` | `POST /api/zgw/zaken/v1/{resource}` :1840 | `date('Y-m-d')` in the server's zone as the default, then `$caseData['endDate'] = $dateStatusGezet` :1845, the body value unnormalised |
| `lib/Controller/ComplaintController.php` | `POST /api/complaints`, `PUT /api/complaints/{id}` :169 | `ComplaintService` :132 and :133 derive two deadlines through `WorkingDayCalculator`, formatted `Y-m-d`; :301 falls back to `date('Y-m-d')` |
| `lib/Controller/ConsultationController.php` | `POST /api/consultations` and four more :291 | `ConsultationService` :141, :241 and :412 write `date('Y-m-d\TH:i:s')`, a local time with no offset and no zone; :286 writes `date('Y-m-d')` |
| `lib/Controller/AdviceController.php` | `POST /api/vth/cases/{id}/advice-requests` :199 | `AdviceService` :361 stores `$data['deadline']` verbatim. No parse, no format, no zone |
| `lib/Controller/WOOAssessmentController.php` | `POST /api/cases/{id}/woo/extend-deadline` :152 | `WOODeadlineService` :104, :177 and :193 write `expectedResolution` as `Y-m-d`; :294 measures against `new DateTimeImmutable('today')` |
| `lib/Controller/ContactMomentController.php` | `POST /api/contactmomenten` :130, `/api/kcc/quick-actions/nieuwe-zaak` :270, `/klacht-registreren` :303 | `QuickActionService` :118 and :165 write `startDate` as `date('c')`; :158 derives a 42 day deadline from `new DateTimeImmutable('today')` and formats it `Y-m-d` |
| `lib/Controller/DwangsomController.php` | `POST /api/termijn/dwangsom/{id}/beschikking` :242 | `DwangsomCalculationService::stopForBeschikking` :337 accrues through a clock defaulting to `new DateTimeImmutable()` :145, then compares day granularity at :213 and :214 |
| `lib/Controller/DwangsomPaymentCallbackController.php` | `POST /api/dossiq/openconnector/dwangsom-payment-callback` :121 | its own private `parseDate()` :191, then `DwangsomUitbetalingService` :321 writes `actualPaymentDate` as `Y-m-d` |

The register named `DeadlineReportingController` as the ninth writer.
It is not one. Its three routes are all GET
(`appinfo/routes.php:753-755`) and it renders what the nine wrote. That
makes it the other half of row 8.22, the render side, and it is where a
disagreement between the nine becomes visible to a handler.

**The tree has nine private date normalisers, not one.** The register
found `CaseEnricher::normaliseDate()` and reported it as the only one.
Every one below is `private`, so no caller outside its own class can
reach it, and each has a different rule.

| normaliser | rule |
|---|---|
| `lib/Service/Doorlooptijd/CaseEnricher.php:252` `normaliseDate()` | trims to `Y-m-d`, null on failure |
| `lib/Controller/DwangsomPaymentCallbackController.php:191` `parseDate()` | keeps the time, null on failure |
| `lib/Service/WOODeadlineService.php:361` `parseIsoDate()` | forces `00:00:00` |
| `lib/Service/WorkQueueService.php:545` `parseDateOnly()` | reparses its own `Y-m-d` output |
| `lib/Service/TermijnTimerService.php:397` `dateOrNull()` | keeps the value as given |
| `lib/Service/ProcessMiningService.php:397` `parseDate()` | takes a fallback |
| `lib/Service/ProcessMining/DwellTimeAnalyzer.php:326` `parseDate()` | takes a fallback |
| `lib/Service/ProcessMining/ThroughputTrendCalculator.php:125` `parseDate()` | takes a fallback |
| `lib/Service/BesluitMigrationService.php:528` `asDateTime()` | returns mixed |

**The time zone is administered and nothing reads it.**
`tenantConfiguration.timezone` is a declared property in the register
(`lib/Settings/dossiq_register.json:7899`, described "IANA timezone")
and `TenantConfigurationService::ALLOWED_TIMEZONES` :57 holds the five
values it accepts. Not one date write path reads it. The only five
`DateTimeZone` uses in `lib/` hard-code `Europe/Amsterdam` as a literal,
all of them in StUF mapping: `StufMessageBuilder.php:426`,
`Stuf/StufMessageHandler.php:256` and :266,
`Stuf/StufCaseMappingStore.php:135`,
`Stuf/ContactBetrokkeneMapper.php:201`. Every other write runs in
whatever `date.timezone` the PHP process happens to carry.

That is one step worse than Gitea. Gitea had one of three paths reading
the administered setting. dossiq has zero of nine, and five paths
asserting a zone the administrator never chose.

**This has already cost us once, in production.** `tests/bootstrap.php`
:63-65 records it: `easter_date()` returns a fixed CEST midnight
timestamp, so `date('Y-m-d', easter_date($y))` read one day early under
`date.timezone=UTC`, and the test suite could not see it because the
bootstrap handed the tests a function production did not have. The Awt
holiday computus was off by a day. Same failure shape, same field, same
tree.

**Nothing asserts the nine agree.** `grep -rliE "timezone|DateTimeZone"
tests/` returns `TenantConfigurationServiceTest` (the allow-list),
`WorkingDayCalculatorTest` (the computus), the bootstrap comment and
three e2e specs. No test submits one date through more than one path and
compares what is stored.

## What changes

- One public date normaliser, `CaseDateNormaliser`, with three named
  methods: a calendar date (`Y-m-d`), a moment (`DateTimeInterface::ATOM`
  with a real offset), and a parse that refuses rather than returns null
  on an unreadable value.
- The zone is read, once, from `tenantConfiguration.timezone`, with
  `Europe/Amsterdam` as the fallback the register already declares.
  Nothing else resolves a zone.
- Every one of the nine write paths calls it. The nine private
  normalisers are deleted or become thin calls to it.
- The five StUF literals read the same tenant zone, so a Belgian tenant
  stops sending Dutch timestamps.
- A structural test fails any new `private function` in `lib/` that
  parses or formats a date, and fails any bare `date(` or
  `new DateTimeImmutable(` outside the normaliser. It names the
  normaliser in its failure message.
- One round-trip test per write path: the same date in, one stored value
  out, compared across all nine.

## Ownership

dossiq builds the normaliser, moves the nine paths onto it, writes the
structural test and the round-trip test. The zone itself belongs to the
engine calendar: dossiq reads it and keeps no second answer.

## Consumes from

- openregister `calendar-time-zone` (openregister#3688, register row
  Q8.19): the administered zone the calendar counts in. Once it lands,
  `CaseDateNormaliser` reads that instead of `tenantConfiguration`, and
  the tenant property becomes the fallback.
- dossiq `terms-on-the-engine-calendar` (open, rows 8.11, 8.12, Q8.19,
  Q8.17): that change states the zone for term dates. This one makes
  every other case date obey the same statement, through one code path.

## ADRs

- Company ADR-022: one answer to "what zone", the engine's. dossiq reads
  it.
- Company ADR-031: the zone is administered, not coded. The five StUF
  literals are the violation this change removes.
- dossiq ADR-011: search before implementing a utility. Nine private
  normalisers is that rule failing nine times, and the structural test is
  what makes it hold.

## Capabilities

- Modified: `case-management`: every date on a case is written through
  one path, in one declared zone, and the product shows one date for it.

## Impact

New `lib/Service/CaseDateNormaliser.php`. Edits to
`lib/Controller/TermijnController.php`, `ZrcController.php`,
`ComplaintController.php`, `ConsultationController.php`,
`AdviceController.php`, `WOOAssessmentController.php`,
`ContactMomentController.php`, `DwangsomController.php`,
`DwangsomPaymentCallbackController.php`; the nine private normalisers
listed above; the five StUF files. New
`tests/Unit/Architecture/OneDateWritePathTest.php` and
`tests/Unit/Service/CaseDateNormaliserTest.php`.

## Out of scope

- Changing what any date means. The Awt roll is
  `terms-on-the-engine-calendar`; this change makes the nine paths agree
  on the date they already compute.
- Migrating stored values. Existing rows keep what they hold. The
  structural test stops new disagreement; a backfill is its own change
  once the corpus is measured.
- Display formatting in the frontend. One stored value first.

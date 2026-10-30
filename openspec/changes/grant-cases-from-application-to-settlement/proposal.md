# Grant cases from application to settlement

## Why

Row 15.2 (process grants from application through advances to final settlement). The `subsidie` package (fully ticked `subsidie-package`) installs the case type and flows, and the register holds nine schemas (`lib/Settings/register.d/50-subsidie.json`: regeling, aanvraag, beoordeling, beschikking, uitvoering, interimReport, vaststelling, terugvordering, bewijsstuk). `SubsidieController` serves them under `/api/subsidies/...`. Nothing under `src/` calls those routes and no page shows a grant, so a handler cannot run a grant past the application. Board DqSubsidie is still missing (gap list b), so the screens follow it once drawn.

## What changes

- The grant case page shows the grant's chain as widgets on the case (configured on the subsidie case type, decision 182): the decision with its advance schedule, the interim reports, the evidence, the settlement and any recovery.
- Header actions for the steps a handler takes: record an interim report, assess it, settle the grant, start a recovery. Each calls the existing route.
- Advances are a generic payment schedule on a decision: dates and amounts, each raised with the finance app as a payable (decision 192) when due.
- The procedure-named services (`lib/Service/Subsidie/*`) move onto generic names in the refactor programme (decision 184); this change only adds callers and does not add any new `Subsidie*` class.

## Not in this change

Case costs. Decision 258 (30 October): ADR-081 wins, so settling a grant appends nothing to the case's `kosten`. The settled amount stays on the vaststelling. `subsidie-settlement-appends-no-case-costs` moves `subsidie-settlement-case-costs` to that rule; this change builds on it and adds no cost line to the case.

## Impact

- `src/manifest.json` CaseDetail widgets with `visibleWhen` on the subsidie case type's config; header actions `interim-report-record`, `interim-report-assess`, `grant-settle`, `grant-recover`.
- Generic payment schedule: a `paymentSchedule` on the decision schema and a job that raises due payments through the shillinq payable seam.

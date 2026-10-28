# Notices of default by case

## Why
DqTermijnen draws "Ingebrekestellingen en dwangsom" as one row per notice: the case (a link), when it came in, the assessment as a pill, the penalty it started ("€ 1.442, vastgesteld", "€ 207, loopt sinds 16 okt", "Geen") and where that money stands ("Betaalsignaal verstuurd", "Bedrag nog niet vast", "Niet van toepassing"). The card was a plain list of the notice objects: four of their own fields, raw codes ("valid", "e-mail"), no case and no money (r34).

## What changes
- `GET /api/deadlines/notices` (`deadlineReporting#notices`, the reporting audience gate): every notice joined to its term, its case and the penalty calculation it started (`NoticeOfDefaultRows`). A calculation with a definitive amount is fixed and has gone to finance as a payment signal; any other is running money, its amount not fixed yet; a notice without one has no penalty.
- The card is an object table over that endpoint, each row opening its case. Two generic formatters word the receipt day and the penalty. The finance column uses nextcloud-vue's column `emptyLabel` for "Not applicable" (feat/r34-column-empty-label); an older library shows a dash there.

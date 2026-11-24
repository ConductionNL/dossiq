# Tasks

- [x] 1.1 caseType `overviewCards` (schema 1.25.0, register.d 99)
- [x] 1.2 Generic Overview cards step aside while the case type lists its own
- [x] 1.3 Summary card for the social domain (DqSociaalDomein De melding)
- [x] 1.4 jeugdwet and bezwaar-beroep 1.3.0 list their cards; the seeded Bezwaar case type too
- [x] 1.5 Spec: overviewCardsPerCaseType.spec.js
- [x] 1.6 Opt-in of single generic cards: a case type that lists its own cards also shows a generic card it names in `overviewCards` (`contains`, nextcloud-vue 2.126)
  - `tests/vitest/overviewCardsPerCaseType.spec.js`
- [x] 1.6b Order of the generic cards a case type opts into: the Overview tab draws its cards in the tab's order, not the list's
  - Open: needs CnTabsWidget in nextcloud-vue to order a stack by a context list; it draws `widgetIds` in authored order only (2.180.0)
  - Built (24 Nov 2026): nextcloud-vue's CnTabsWidget has `orderFrom` (change `tab-stack-order-from`, in 2.213.0): the listed widgets take the places of the listed ones in the list's order, every other widget keeps its place. The case page's Overview tab (`src/menu-layout.simple.json`, case-panels tab `overview`) sets `orderFrom: '@context.caseType.overviewCards'`. Test: `tests/vitest/overviewCardsPerCaseType.spec.js`.
- [x] 1.7 Grant decisions on the Overview tab of a subsidy case (DqSubsidie Beschikkingen); the subsidie package (1.2.0) lists it, with the requester and banner side cards
- [x] 1.8 DqSubsidie Vaststelling card: the determination hangs off the execution, which hangs off the application, two references away from the case
  - `GET /api/cases/{caseId}/settlements` (`SettlementController`, `Service/Money/SettlementOverview`) walks case → application → execution → settlement; the `case-settlement` card reads it; subsidie 1.8.0 lists it. `tests/vitest/caseSettlement.spec.js`, `SettlementOverviewTest`, `SettlementControllerTest`

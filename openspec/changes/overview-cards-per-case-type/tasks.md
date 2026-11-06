# Tasks

- [x] 1.1 caseType `overviewCards` (schema 1.25.0, register.d 99)
- [x] 1.2 Generic Overview cards step aside while the case type lists its own
- [x] 1.3 Summary card for the social domain (DqSociaalDomein De melding)
- [x] 1.4 jeugdwet and bezwaar-beroep 1.3.0 list their cards; the seeded Bezwaar case type too
- [x] 1.5 Spec: overviewCardsPerCaseType.spec.js
- [x] 1.6 Opt-in of single generic cards: a case type that lists its own cards also shows a generic card it names in `overviewCards` (`contains`, nextcloud-vue 2.126)
  - `tests/vitest/overviewCardsPerCaseType.spec.js`
- [ ] 1.6b Order of the generic cards a case type opts into: the Overview tab draws its cards in the tab's order, not the list's
- [x] 1.7 Grant decisions on the Overview tab of a subsidy case (DqSubsidie Beschikkingen); the subsidie package (1.2.0) lists it, with the requester and banner side cards
- [ ] 1.8 DqSubsidie Vaststelling card: the determination hangs off the execution, which hangs off the application, two references away from the case

# Tasks: portal-case-page-withdraws

- [x] **T1**: the resident contribution declares `pages`; the `mijnZaken` page carries `collection`, `detail` and `citizenCase` blocks; every other listable collection keeps the page portaliq would build (REQ-PORTAL-021)
  - PHPUnit `PortalCasePageTest::testTheCasePageCarriesTheResidentsOwnCase`, `::testMyCasesOpensACaseOnThePageThatCanWithdrawIt`, `::testEveryCollectionKeepsThePagePortaliqGaveIt`, `::testEveryBlockResolvesWithinTheContribution`, `::testOtherAudiencesDeclareNoPages`
- [x] **T2**: `mijnZaken` declares `statusLabelField: statusPublicLabel`, a projected field (REQ-PORTAL-022)
  - PHPUnit `PortalCasePageTest::testMyCasesShowsTheStatusInWords`

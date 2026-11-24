# Tasks: case-page-shows-the-outcome

## 1. The declaration

- [x] 1.1 `outcome` on `mijnZaken` naming `resultPublicLabel` and `resultPublicDescription`
  - Spec ref: specs/portal-contribution/spec.md, "The case collection MUST name its outcome fields"
  - Files: lib/Portal/CitizenCaseCollection.php, tests/Unit/golden/citizen-manifest.json
  - Test: tests/Unit/Portal/PortalCasePageTest.php (`testTheCaseNamesItsOutcomeFields`)

## 2. Open

- [ ] 2.1 Live check against the ZaakAfgerond board on the Zuiddrecht example site (portaliq `case-outcome-on-the-case-page` T06; needs a live instance)

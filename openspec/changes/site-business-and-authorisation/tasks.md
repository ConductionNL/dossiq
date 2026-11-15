# Tasks: site-business-and-authorisation

Tier: V1. Kind: code. Programme: portal-design (2026-10-02). Mockups:
`DossiqBusiness.dc.html`, `DossiqPhone.dc.html`.

Builds on `portal-case-list-declarations` (its open tasks 1.1, 3.1 and 3.2 land here or before)
and `site-resident-portal-design` (the pages). Depends on portaliq
`site-mandates-the-represented-manage` (on portaliq `development`) for the mandate list,
invite, revoke, expiry, typed parties and the company as holder, and on its task **T0** (the
company's KVK number on an eHerkenning session) before 2.1 can write `kvk:` parties. The
switcher and the acting-for bar already exist in portaliq.

## 0. Decisions

- [x] 0.1a Typed party form agreed: portaliq REQ-SMR-001 (`kvk:` plus 8 digits, `subject:`).
- [ ] 0.1 Portaliq takes up the two asks in the proposal: the session's party reaches dossiq on
      a write (stamped `mandateField`, or a claim on the `X-Portal-Subject` assertion), and a
      business session's "Zaken" matches its own `kvk:` party.
- [ ] 0.2 Ruben: a mandate scope finer than case types ("bekijken, aanvullen, aanvragen") is
      a portaliq change; confirm it is wanted.

## 1. Audience

- [x] 1.1 (`PortalContributionProvider::BUSINESS_GROUP`/`BUSINESS_PAGE_LABELS`, `PortalPages` `lopendeZaken` label; `PortalCaseDeclarationsTest::testACompanyOnAMunicipalPortalReadsTheResidentPagesWordedForACompany`, `PortalWooRequestControllerTest::testACompanyIsServed`) `business` in `getAudiences()`, the resident manifest with company labels for it
      (design D1); `business` in `PortalWooRequestController::AUDIENCES`.
  - unit: `PortalContributionProviderTest` for `business`, `supplier` unchanged, `inspector` unchanged

## 2. Party and branch

- [ ] 2.1 (half: `case.portalParty` in `register.d/75-portal-case-declarations.json`; `WooRequestIntake` writes `subject:<subjectRef>`, `IntakeFanOut` carries a submission's party; `WooRequestIntakeTest` asserts it, `BackfillPortalPartyTest` that a BSN-shaped value is refused. The `kvk:` and mandate rows wait on portaliq signing the company or mandate into the assertion: Q-dossiq-L2-5) `case.portalParty` in a register fragment; `WooRequestIntake` and `IntakeFanOut`
      write it from the session (design D2).
  - unit: one test per row of the D2 table, and that no BSN is ever written
- [x] 2.2 (`CitizenManifest::PARTY_FIELD`; `PortalCaseDeclarationsTest::testTheCaseListNamesThePartyAMandateReaches`) `mandateField: 'portalParty'` and `portalParty` in the projection on `mijnZaken`.
- [x] 2.3 (the business manifest is the resident's, so it carries `branchField` from portal-case-list-declarations 3.2) `portalBranch` and `branchField` for `business` (`portal-case-list-declarations`
      3.1, 3.2).
  - The declaration can be built now. Its live check waits on Ruben's broker decision D1:
    until a broker vendor is chosen, no eHerkenning session carries a branch to stamp.
    Requested by portaliq row dem-cl-eherkenning-branch; 2.2 by portaliq row cas-mandate-org-cases.
- [x] 2.4 (`lib/Repair/BackfillPortalParty.php`, post-migration, `BackfillPortalPartyTest`) A repair step that backfills `portalParty` from `portalSubject` on cases that have
      one and no party.

## 3. Who acted

- [ ] 3.1 (built, waits on its portaliq dependency to land before ticking: `ApplicantPortalActs::recordWrite()` takes the event's mandate and writes a PUBLIC entry "Namens {label}: ..." (the label from portaliq's write-record-names-the-mandate PR, the party reference without it); portaliq hands over no acting person's name, so the entry names the party only: D4's fallback. `ApplicantPortalActsTest::testAWriteForSomeoneElseIsToldToThemByTheMandatesLabel`) `caseTimeline` names "{name}, namens {party}" for a portal write with `actingFor`
      (design D4).
  - unit: with and without a mandate label

## 4. Validation

- [ ] 4.1 `openspec validate site-business-and-authorisation --strict`, `npm run lint`,
      `composer check:strict` once before push.
- [ ] 4.2 Live on :8090 with a dev eHerkenning login mapped to `business` and a seeded
      company mandate: the company's cases show; with a bezwaar-only mandate only bezwaren
      show.

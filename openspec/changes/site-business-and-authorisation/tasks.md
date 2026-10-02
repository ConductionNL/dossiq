# Tasks: site-business-and-authorisation

Tier: V1. Kind: code. Programme: portal-design (2026-10-02). Mockups:
`DossiqBusiness.dc.html`, `DossiqPhone.dc.html`.

Builds on `portal-case-list-declarations` (its open tasks 1.1, 3.1 and 3.2 land here or before)
and `site-resident-portal-design` (the pages). Depends on portaliq `site-mandates-the-represented-manage` for the mandate list,
invite, revoke, expiry and the typed `onBehalfOf`. That change is NOT YET WRITTEN and is not in
portaliq#1110. The switcher and the acting-for bar already exist in portaliq.

## 0. Decisions

- [ ] 0.1 Portaliq agrees the typed party form `kvk:<number>` / `subject:<subjectRef>` for
      `onBehalfOf` in `site-mandates-the-represented-manage` (not yet written).
- [ ] 0.2 Ruben: a mandate scope finer than case types ("bekijken, aanvullen, aanvragen") is
      a portaliq change; confirm it is wanted.

## 1. Audience

- [ ] 1.1 `business` in `getAudiences()`, the resident manifest with company labels for it
      (design D1); `business` in `PortalWooRequestController::AUDIENCES`.
  - unit: `PortalContributionProviderTest` for `business`, `supplier` unchanged, `inspector` unchanged

## 2. Party and branch

- [ ] 2.1 `case.portalParty` in a register fragment; `WooRequestIntake` and `IntakeFanOut`
      write it from the session (design D2).
  - unit: one test per row of the D2 table, and that no BSN is ever written
- [ ] 2.2 `mandateField: 'portalParty'` and `portalParty` in the projection on `mijnZaken`.
- [ ] 2.3 `portalBranch` and `branchField` for `business` (`portal-case-list-declarations`
      3.1, 3.2).
- [ ] 2.4 A repair step that backfills `portalParty` from `portalSubject` on cases that have
      one and no party.

## 3. Who acted

- [ ] 3.1 `caseTimeline` names "{name}, namens {party}" for a portal write with `actingFor`
      (design D4).
  - unit: with and without a mandate label

## 4. Validation

- [ ] 4.1 `openspec validate site-business-and-authorisation --strict`, `npm run lint`,
      `composer check:strict` once before push.
- [ ] 4.2 Live on :8090 with a dev eHerkenning login mapped to `business` and a seeded
      company mandate: the company's cases show; with a bezwaar-only mandate only bezwaren
      show.

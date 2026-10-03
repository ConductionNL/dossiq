# Tasks: site-resident-portal-design

Tier: V1. Kind: code. Programme: portal-design (2026-10-02). Mockups:
`DossiqHome.dc.html`, `DossiqOverview.dc.html`, `DossiqCase.dc.html`.

Lands after dossiq#3245 and dossiq#3247. Depends on portaliq changes written by the portaliq
lane in portaliq#1110: `site-mijn-omgeving-components` (blocks `tasks`, `inbox`, `cases`,
`steps`, `documents`, `timeline`; page keys `menu`, `home`, `group`, `record`; `steps`,
`dueField`, `turnField`) and `site-nlds-widget-palette` (`summary`, `audiences`, start tiles).

## 0. Decisions for Ruben

- [ ] 0.1 Should every `aanvullingsverzoek` also become a portaliq portal task, so it counts
      under "Taken"? This change shows it through `vragenAanU` instead (proposal, not in
      this change).
- [ ] 0.2 The seeded Woo type has five public steps; the mockup draws four ("Afgerond" for
      "Besluit genomen" and "Afgehandeld"). Change the case type's public labels, or accept
      five.

## 1. The question to the resident

- [ ] 1.1 `portalSubject` on `aanvullingsverzoek` in `register.d/63-aanvullingsverzoek.json`;
      `InformationRequestService::ask()` copies it from the case.
  - unit: ask on a case with and without `portalSubject`
- [ ] 1.2 The ask form tells the handler that the summary and the missing items go to the
      applicant.
- [ ] 1.3 Collection `vragenAanU` (design D1), with `defaultFilters: {state: open}` (design D4).
  - unit: `PortalContributionProviderTest` asserts the scope and the projection; a test asserts
    every projected field exists on the schema

## 2. Who acts, steps, team

- [ ] 2.1 `portalTurn` on the case schema; `portalTurn`, `waitingOnApplicant` and `waitingOn`
      in `CITIZEN_CASE_FIELDS` and the detail fields; `turnField`, `dueField` and value labels
      on `mijnZaken` (design D2).
  - unit: one test per row of the D2 table
- [ ] 2.2 `OCA\Dossiq\Portal\CaseSteps` and `caseSteps()` on the provider; `steps` on
      `mijnZaken` (design D3).
  - unit: the seeded Woo type folds to five steps; a withdrawn case stops at its step
- [ ] 2.3 `publicName` on `organisatieRol`, `assignedGroupPublicName` on `case`, on the detail
      fields.
  - unit: an empty public name projects as empty

## 3. Pages and start points

- [ ] 3.1 The four pages of design D4 with `menu: false`, `home: true` on `overzicht`, the
      record key on `mijnZaken`, the documents label "Documenten".
  - unit: `PortalCasePageTest` asserts the blocks, the order and that `mijnZaken` still opens a case
  - check against portaliq#1110's `PortalPageResolver`: no block dropped
  - ask portaliq: does the overview's `cases` block count as a list block under REQ-SMO-010
    (it must not, or cases open on the overview), and does a `tasks` block honour
    `defaultFilters`
- [ ] 3.1a `recordField: 'caseId'` on `replyToMessage`, for the `withRecord` cta.
  - unit: the provider test asserts the key and the cta
- [ ] 3.2 `summary` and `audiences` on `startWooVerzoekAlgemeen`, `createBezwaar` and
      `createKlacht` (design D5).

## 4. Validation

- [ ] 4.1 `openspec validate site-resident-portal-design --strict`, `npm run lint`,
      `composer check:strict` once before push.
- [ ] 4.2 Live on :8090 with the portaliq changes: Sanne de Vries sees "Dit moet u nog doen",
      two case cards with "Stap 2 van 5" and who acts, and opens 2026-0003 on a page laid out
      as `DossiqCase.dc.html`.

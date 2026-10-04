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
- [x] 0.2 The seeded Woo type has five public steps; the mockup draws four ("Afgerond" for
      "Besluit genomen" and "Afgehandeld"). **Ruben, 4 October: show the five the case type
      declares and do not relabel the case type.** The mockup's four were a simplification.
      Nothing changes in the code: `CaseSteps` already folds the type's own statuses, and
      `CaseStepsTest::testTheSeededWooTypeFoldsToFiveSteps` asserts the five. These are the
      STATUS steps a resident reads on their case; the Woo FORM's four steps (your question,
      period and documents, your details, check and send) are a different thing and are
      unaffected.

## 1. The question to the resident

- [x] 1.1 `portalSubject` on `aanvullingsverzoek` in `register.d/63-aanvullingsverzoek.json`;
      `AanvullingsverzoekService::ask()` copies it from the case (the ask lives there, not on
      `InformationRequestService`, which suspends the clock).
  - unit: `AanvullingsverzoekServiceTest::testTheRequestNamesWhoMayReadItInThePortal` and
    `::testACaseWithoutAPortalSubjectWritesNone`. The lookup sits inside the try: without that
    an instance with no register configured turned a working ask into a refusal, which the
    existing ask tests caught.
- [ ] 1.2 The ask form tells the handler that the summary and the missing items go to the
      applicant.
  - **Blocked, and not by this change.** There is no ask form: `requestInformation()` in
    `src/services/caseTermsApi.js` has no caller anywhere in `src/`, and the manifest declares
    no action on `/api/cases/{caseId}/information-request`. The desk sends a request through
    the API alone, so there is no screen to put the sentence on. Building that form is a
    change of its own; this one cannot carry it. Reported rather than ticked.
- [x] 1.3 Collection `vragenAanU` (design D1), with `defaultFilters: {state: open}` (design D4).
  - unit: `PortalContributionProviderTest::testTheQuestionsToTheResidentAreScopedAndMinimal`
    asserts the scope, the projection, the six internal fields it leaves out and the filter;
    the projected fields are checked against the merged register's own schema.

## 2. Who acts, steps, team

- [x] 2.1 `portalTurn` on the case schema; `portalTurn`, `waitingOnApplicant` and `waitingOn`
      in `CITIZEN_CASE_FIELDS` and the detail fields; `turnField`, `dueField` and value labels
      on `mijnZaken` (design D2).
  - unit: `::testTheCaseCardSaysWhoIsAtTurnAndWhereTheCaseStands`, over every row of D2 and
    every projected field against the schema. The words are the collection's `valueLabels`,
    not the calculation, so a site can reword them.
- [x] 2.2 `OCA\Dossiq\Portal\CaseSteps` and `caseSteps()` on the provider; `steps` on
      `mijnZaken` (design D3).
  - unit: `CaseStepsTest`, five tests: the seeded Woo type folds to five steps, a withdrawn
    case stops at its step, the label falls back to the status name, an unknown status leaves
    no current step, and no status types means no steps while a bad history costs only the
    dates.
- [x] 2.3 `publicName` on `organisatieRol`, `assignedGroupPublicName` on `case`, on the detail
      fields.
  - unit: in `::testTheCaseCardSaysWhoIsAtTurnAndWhereTheCaseStands`; the calculation
    coalesces to '' so a team with no public name projects empty and the portal shows no team.

## 3. Pages and start points

- [x] 3.1 The four pages of design D4 with `menu: false`, `home: true` on `overzicht`, the
      record key on `mijnZaken`, the documents label "Documenten".
  - unit: `PortalCasePageTest` asserts the blocks in order, the record key and `menu: false`;
    `PortalContributionProviderTest::testTheCasePageAsksOnlyAboutTheCaseOnScreen` asserts the
    question block is scoped by `recordField: case`.
  - checked against portaliq `origin/development`'s real resolvers
    (`PortalManifestNormaliser`, which composes `PortalPageResolver`, `PortalBlockResolver`,
    `CollectionConfigNormaliser` and `ActionConfigNormaliser`): 4 collections, 5 actions and 4
    pages survive, and after the `requiredFields` fix below **nothing** of ours is dropped.
  - still to ask portaliq: whether the overview's `cases` block counts as a list block under
    REQ-SMO-010 (if it does, a case opens on the overview instead of the case page), and
    whether a `tasks` block honours `defaultFilters`. Neither is answered by the resolvers,
    which keep both keys; it shows at read time.
- [x] 3.1a `recordField: 'caseId'` on `replyToMessage`, for the `withRecord` cta.
  - unit: `::testTheReplyActionNamesTheFieldARecordLandsIn`. The key is the one `crossRefs`
    already guards, so the preset is checked like any typed value.
- [x] 3.2 `summary` and `audiences` on `createBezwaar` and `createKlacht` (design D5).
  - unit: `::testTheStartPointsCarryTheirOwnSentence`, which also holds the summary to 200
    characters and keeps the mockup's service promise off the tile.
  - **Two tiles of three.** `startWooVerzoekAlgemeen` does not exist yet: it is task 2.2 of
    `site-woo-request-in-steps`. The Woo action that does exist carries `attachTo` and
    `rowField`, so it can only be started from a dossier and must not be offered as a tile
    without one. REQ-SRPD-006's third tile lands with that change.
- [x] 3.3 (added) `requiredFields: ['onderwerp']` on `startWooVerzoek`, in place of
      `fieldConfigs.onderwerp.required`.
  - Found by running the manifest through portaliq's resolvers: `required` on a field config
    was the one key of ours they dropped, so the Woo form's only indispensable question read as
    optional. Portaliq reads a required marker from the written schema's `required` list or the
    action's `requiredFields` (REQ-SMF-023/024), and this action forwards to dossiq's own
    endpoint rather than writing a schema, so nothing else could mark it.
  - unit: `::testARequiredFieldIsDeclaredWherePortaliqReadsOne`, which also fails if any action
    declares `required` on a field config again.

## 4. Validation

- [x] 4.1 `openspec validate site-resident-portal-design --strict`, `npm run lint`, and the
      analysers over the changed files once before push (php -l, phpcs, phpmd, psalm, phpstan),
      plus the unit tests of every touched class.
- [ ] 4.2 Live on :8090 with the portaliq changes: Sanne de Vries sees "Dit moet u nog doen",
      two case cards with "Stap 2 van 5" and who acts, and opens 2026-0003 on a page laid out
      as `DossiqCase.dc.html`.

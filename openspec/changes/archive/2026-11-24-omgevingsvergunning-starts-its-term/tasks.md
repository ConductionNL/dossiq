# Tasks: omgevingsvergunning-starts-its-term

- [x] 1.1 Add `td-omgevingsvergunning` to `lib/Settings/termijnbewaking_seed_data.json`.
  - `tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testTheRegistersOmgevingsvergunningHasATermijnDefinitie`
- [x] 2.1 Decide the legal basis for the other register case types with a
  `processingDeadline` and no definition (listed in the proposal).
  - Decided by Ruben on 10, 11 and 23 Oct; every case type the proposal lists now has a row in
    `lib/Settings/termijnbewaking_seed_data.json`: `klacht-behandeling` Awb 9:11, the two event permit
    versions APV 1:2 with Awb 4:13 lid 2, `subsidieaanvraag` and the two subsidy schemes Awb 4:13 lid 2,
    `melding-openbare-ruimte` a 14-day service norm, the English demo types service norms (demo).
  - Pinned in `tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php`: `DECIDED` (kind, days,
    extension, basis per case type), `testEveryShippedCitationNamesAnArticleAndAnOfficialSource`, and
    `OPEN_QUESTIONS` is empty, held there by `testTheOpenQuestionsAreStillOpen`.

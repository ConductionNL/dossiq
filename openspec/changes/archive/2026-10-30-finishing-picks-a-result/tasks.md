# Tasks: finishing-picks-a-result

- [x] 1.1 `CaseResultWriter::resolveClosingResult` refuses an unknown result with 422 before writing.
  - `tests/Unit/Service/Transitions/CaseResultWriterTest.php`
- [x] 1.2 `/acts` carries `resultTypes` (`CaseEndingActs::resultChoicesOn`, `CaseActsOverview`).
  - `tests/Unit/Service/Lifecycle/CaseActsOverviewTest.php`
- [x] 1.3 Finish and Abort offer the results in a picker; the free field is removed.
  - `tests/vitest/finishingPicksAResult.spec.js`
- [x] 1.4 nl and en catalogues carry the new sentence.

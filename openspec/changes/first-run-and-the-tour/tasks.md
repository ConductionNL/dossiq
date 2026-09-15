# Tasks: first-run-and-the-tour

Tier: V1. Kind: code. Size S. Round 4 discovery cluster 3, candidates
C-configuration-43 and C-configuration-96. Decision D6 admits both on
relevance. Waits on nothing to report readiness; the tour runner is
buildiq's and dossiq's declaration is read by the walkthrough block that
already ships.

- [ ] 1.1 Declare the five readiness items in `src/manifest.json` beside
  the existing `setup` block: the organisation, the mail account, a
  published case type, a role with a holder, the working calendar (D-1).
  - `@spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md`
- [ ] 1.2 `SetupController`: read each item live, per request, reporting
  not done and the failure when a read raises (D-2).
  - `tests/unit/Controller/SetupControllerReadinessTest.php`
- [ ] 1.3 Keep `register-check` the only gate; no readiness item blocks
  the shell (D-1).
  - `tests/vitest/firstRunReadiness.spec.js`
- [ ] 2.1 Name the satisfying screen per item, and assert the declared and
  reported lists agree in both directions (D-3).
  - `tests/unit/Controller/SetupDeclaredMatchesReportedTest.php`
- [ ] 3.1 Move `walkthrough_completed_version` to completion per person
  and per surface, so a later joiner and a later surface are both taught
  (D-4).
  - `tests/unit/Service/WalkthroughCompletionTest.php`
- [ ] 3.2 Report a tour step whose surface is gone beside the readiness
  items (D-5).
  - `tests/vitest/walkthroughBrokenStep.spec.js`
- [ ] 4.1 Hand buildiq the tour runner with the candidate id
  C-configuration-96 and the three properties it needs: per surface, per
  person, and a broken step reported.
- [ ] 4.2 Dutch and English strings.
- [ ] 4.3 `tests/e2e/first-run-and-the-tour.spec.ts`: a missing case type
  read as not done, an item satisfied and re-read, an item followed to
  its screen, a new handler offered the tour, and a new surface offered to
  someone who finished the rest;
  `openspec validate first-run-and-the-tour --strict`.

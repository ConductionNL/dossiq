# Tasks: first-run-and-the-tour

Tier: V1. Kind: code. Size S. Round 4 discovery cluster 3, candidates
C-configuration-43 and C-configuration-96. Decision D6 admits both on
relevance. Waits on nothing to report readiness; the tour runner is
buildiq's and dossiq's declaration is read by the walkthrough block that
already ships.

- [x] 1.1 Declare the five readiness items: the organisation, the mail
  account, a published case type, a role with a holder, the working
  calendar (D-1). **Not in `src/manifest.json`**: the v2 app-manifest
  schema sets `additionalProperties: false` at the top level and on the
  `setup` block, in all three copies that matter (dossiq
  `tests/schemas/`, `@conduction/nextcloud-vue` 3.1.0, and the
  `.github` hydra-gates vendored copy synced in ConductionNL/.github#776),
  so the key would fail `validate-manifest.js` and gate 22 the day it
  shipped. They live in `lib/Settings/first_run_readiness.json`; carrying
  them in the manifest needs a nextcloud-vue schema change first.
  - `@spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md`
- [x] 1.2 `SetupController`: read each item live, per request, reporting
  not done and the failure when a read raises (D-2).
  - `tests/unit/Controller/SetupControllerReadinessTest.php`
- [x] 1.3 Keep `register-check` the only gate; no readiness item blocks
  the shell (D-1).
  - `tests/vitest/firstRunReadiness.spec.js`
- [x] 2.1 Name the satisfying screen per item, and assert the declared and
  reported lists agree in both directions (D-3).
  - `tests/unit/Controller/SetupDeclaredMatchesReportedTest.php`
- [x] 3.1 Completion per person and per surface (D-4). **Nothing to move**:
  `useWalkthrough` already addresses `completionConfigKey` through
  `/apps/{appId}/api/preferences/{key}`, a per-user preference rather than
  app config, and `composeSteps` gates each step on its own
  `sinceVersion`. So a later joiner gets the whole tour and a finisher is
  offered only what a later surface added. Both properties are now held by
  a test instead of being rebuilt.
  - `tests/unit/Service/WalkthroughCompletionTest.php`
- [x] 3.2 Report a tour step whose surface is gone beside the readiness
  items (D-5).
  - `tests/vitest/walkthroughBrokenStep.spec.js`
- [x] 4.1 Hand buildiq the tour runner with the candidate id
  C-configuration-96 and the three properties it needs: per surface, per
  person, and a broken step reported. ConductionNL/buildiq#785.
- [x] 4.2 Dutch and English strings.
- [x] 4.3 `tests/e2e/first-run-and-the-tour.spec.ts`: a missing case type
  read as not done, an item satisfied and re-read, an item followed to
  its screen, a new handler offered the tour, and a new surface offered to
  someone who finished the rest;
  `openspec validate first-run-and-the-tour --strict`.

---
kind: code
depends_on: []
---

# Proposal: the-getting-started-tour-starts-on-the-dashboard

## Why

Live test on 13 October: a staff user's first visit to dossiq was a link to a Woo request. The getting-started tour "dossiq:getting-started" (`trigger: first-visit`) opened its centered "Welcome to Dossiq" card over the case. On :8080 the user's preferences hold the tour paused at that first step. The setup wizard was not involved; it was already completed.

A centered first step is not route-gated in nextcloud-vue, so the tour starts wherever the user lands.

## What changes

1. The tour declares `startOn: ["Dashboard"]` (Ruben, 14 October: the tour starts only from the dashboard, never on a deep link). The key is generic and lives in nextcloud-vue: ConductionNL/nextcloud-vue#1598, schema 2.94.0.
2. Until a nextcloud-vue release carries schema 2.94.0, the vendored schema in `tests/schemas/` is 2.94.0, and `tests/validate-manifest.js` reports the key as a library lag, not a defect. The lag classifier learns the walkthrough tour shape, resolved by path like the others, so an unknown tour key still fails.
3. The shipped-manifest lag test reads both output streams. It read stdout alone, so a lag line, which goes to stderr, could never be seen, and the branch that expects one could not pass.

## Not changed

- The demo staff accounts are not marked as having seen the tour. The tour's state is the Nextcloud user value `dossiq` / `walkthrough_completed_version` (the manifest's `completionConfigKey`, served by `/api/preferences/{key}`). The OpenRegister example-set loader (`lib/Service/ExampleSet/*` on development) writes groups, service accounts, app config and objects, and has no per-user value. Loader request: a `userPreferences` block, `[{user, app, key, value}]`, written with `IConfig::setUserValue` and removed with the set.
- Three tests that validate a built manifest against the installed library's schema now accept the validator's lag verdict, the same rule `tests/validate-manifest.js` applies. An error of the app's own still fails them.

## Rollback

Remove the `startOn` line. Without the nextcloud-vue release the key has no effect at runtime: the installed library ignores it.

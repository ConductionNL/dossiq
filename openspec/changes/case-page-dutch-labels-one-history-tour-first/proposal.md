---
kind: code
depends_on: []
---

# Proposal: case-page-dutch-labels-one-history-tour-first

## Why

The live review on :8098 (11 Oct) found three things on a Dutch case page and on
a new user's first load.

- N-3: "Requester" on the create form, the edit form and the Overview tab,
  "Deadline" on the term tile, and "This act needs an administrator." in the
  lifecycle dialog read English. The first two have their Dutch in `nl.json`:
  nextcloud-vue 2.79.0 copies a field override's `label` over the translated
  schema title as written, and CnCountdownWidget draws its `label` as written.
  The third is a sentence the server wrote in English and the dialog shows as
  it arrives.
- N-6: the case page showed two "Geschiedenis" tabs. The simple profile names
  the timeline tab in the strip History, as DqZaak draws it ("Historie"), and the
  sidebar named the audit trail History as well. DqZaakHistorie keeps both: the
  history in the strip, and "Volledige audit trail in de zijbalk".
- N-7: a new user got the "Steun Dossiq" support note on the first load and the
  welcome tour only on the next. CnAppRoot holds the tour while the support note
  is open. ADR-043 says every app demonstrates itself on first open, and no spec
  orders the support note, so the tour goes first.

## What Changes

- `translateFieldOverrideLabels()` runs the `label` of every field override in
  the built manifest through the host translate, and `translateBannerCopy()`
  translates a countdown widget's `label`. `caseCreateForm.js` translates its
  requester label.
- The simple profile drops the countdown's own label in the side column: the
  card is already headed Termijn by the widget title, as DqZaak draws it.
- `LifecycleActorGate` words its three refusals through `IL10N`, with Dutch in
  the catalogue.
- The sidebar tab over the audit trail on the case page is called "Audit trail"
  (Dutch "Audittrail"), so the strip's History and the sidebar's trail no longer
  share a name.
- A listener on dossiq's page render hands `walkthrough_seen` to the frontend, and App.vue
  turns the support note off on a load where the welcome tour is not yet behind
  the user.

## Not in this change

- nextcloud-vue #1460 (merged on its development, not in 2.79.0) translates
  override labels in the library. Once dossiq moves to a release that has it,
  the dossiq pass is redundant but harmless: a Dutch label has no catalogue
  entry and comes back as it is.
- CnCountdownWidget should translate its own `label` the way CnStatWidget does.
  That is a library change, listed for the library lane.

## Impact

- `src/utils/translateLensLabels.js`, `src/main.js`, `src/utils/caseCreateForm.js`,
  `src/menu-layout.simple.json`, `src/manifest.json`, `src/App.vue`,
  `src/utils/onboardingOrder.js` (new).
- `lib/Service/Lifecycle/LifecycleActorGate.php`,
  `lib/Service/Onboarding/WalkthroughCompletion.php`,
  `lib/Listener/WalkthroughSeenInitialStateListener.php`,
  `lib/AppInfo/Registrar/OnboardingListenerRegistrar.php` (new),
  `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`.
- `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`.
- Tests: `tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js` (new),
  `tests/Unit/Service/Onboarding/WalkthroughCompletionTest.php`,
  `tests/Unit/Listener/WalkthroughSeenInitialStateListenerTest.php` (new),
  `tests/Unit/Service/CaseLifecycleGateTest.php`.

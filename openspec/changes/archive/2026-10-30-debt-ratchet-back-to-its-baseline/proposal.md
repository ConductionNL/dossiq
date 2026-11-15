---
kind: code
depends_on: []
---

# Proposal: debt-ratchet-back-to-its-baseline

`npm run check:debt-ratchet` fails on `development` (CI run 38700178033). Three counters sit above
the baseline that `.debt-baseline.json` records:

| counter | baseline | on development | grown by |
| --- | --- | --- | --- |
| `eslint.inlineDisables` | 14 | 15 | 1 |
| `phpmd.suppressWarnings` | 182 | 187 | 5 |
| `psalm.inlineSuppressions` | 253 | 254 | 1 |

## Summary

Seven suppressions were added without the baseline moving with them. Three of the seven hide
nothing at all and are removed. Four are deliberate exceptions, already reasoned at their site,
and the baseline is raised by exactly those four.

## What each one is

Measured with `node scripts/debt-ratchet.js --list` against the tree at `4fda01a70`, the commit
that last wrote the baseline, so every entry below is a suppression added after it.

### Removed, because it suppresses nothing

- `lib/BackgroundJob/LinkCollectionToCaseJob.php`, `@psalm-suppress UnusedClass`
  (added in `63e35729d`). `psalm.xml` carries `<UnusedClass errorLevel="suppress"/>` as a blanket
  issue handler, and has since PR #4, so the inline tag has never suppressed anything.
- `lib/Controller/Support/TranslatesRefusals.php`, `@SuppressWarnings(PHPMD.StaticAccess)`
  (added in `f563706fb`). PHPMD does not apply `StaticAccess` inside a trait. A control proves
  the rule is alive: removing the same suppression from `lib/Lifecycle/HoorzittingAfzienGuard.php`,
  a class, makes PHPMD report two findings at once. The reason the static access is acceptable
  moves to a comment at the call site, where it stays readable.
- `lib/Service/Transitions/DocumentAssessmentGuard.php`,
  `@SuppressWarnings(PHPMD.UnusedFormalParameter)` (added in `ecd28bffb`). `composer phpmd` scans
  `lib` whole, so `GuardEvaluatorInterface` is in the scan set and PHPMD treats
  `evaluate()`'s `$userId` as interface-mandated. Its three siblings, `CapacityGuard`,
  `RequiredDocumentGuard` and `RequiredFieldGuard`, leave `$userId` unused with no suppression
  and no finding. The `@param` line already says `(unused)`.

### Kept, with the baseline raised by four

- `src/views/widgets/FirstTodayWidget.vue`, `vue/no-unused-properties` on `title`
  (added in `b5f1eb0f7`). Nine dashboard widgets declare `title` and let the Nextcloud host draw
  the heading; drawing it in the widget too is the dashboard-in-dashboard antipattern (hydra#316).
  Dropping the declaration does not remove the prop, it makes it a fallthrough attribute and puts
  a `title=""` tooltip on the root element. `eslint.inlineDisables` 14 to 15.
- `lib/Service/Transitions/GuardRegistry.php`, `PHPMD.ExcessiveParameterList`
  (added in `ecd28bffb`). Ten parameters, one evaluator per guard kind, which is what the
  registry is. Nothing calls `registerEvaluator()`, so a guard kind that is not a parameter is
  not registered at all and every transition of that kind is refused.
- `lib/Service/WooPublicationService.php`, `PHPMD.CouplingBetweenObjects` and
  `PHPMD.ExcessiveParameterList` (added in `ecd28bffb`). Thirteen collaborators and ten
  parameters, five of them optional and each defaulting to the behaviour before it existed.
  `phpmd.suppressWarnings` 182 to 185, which is 187 less the two removed above.

`psalm.inlineSuppressions` returns to its baseline of 253 and is not raised.

## Why the baseline moves at all

The ratchet exists so that hiding a finding costs something. Three of these seven cost nothing
because they hide nothing, and they go. The other four are threshold rules meeting a registry and
a composition root, which is where parameter and coupling counts fit worst. Each already carried
its reason in the code before this change; this proposal names them so the raise is reviewed
rather than assumed.

## Out of scope

243 further files under `lib/` carry `@psalm-suppress UnusedClass`, every one of them redundant
against the same blanket handler. Removing them would take `psalm.inlineSuppressions` from 253 to
about 10. That is a debt sweep across 243 files, not this change.

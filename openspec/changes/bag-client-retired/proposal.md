# bag-client-retired

## Why

Standards adapters live in integriq, not in dossiq (ADR-118, hydra design section 5). Dossiq kept a
BAG client of its own (`BagAdapterInterface` with a Kadaster, a PDOK and a dormant flavour,
`BagResponseMapper`, `BagLookupResult`, `BagRegistrar`) and `PdokBagService` behind it. Its one
caller left was `LocationBagValidationListener`, and that listener passed in silence when no BAG
source was switched on, when the BAG did not answer and when the answer was unclear. Decision 224
says a guard refuses when it is unsure.

## What changes

- `LocationBagValidationListener` asks integriq's `BagObjectReader` whether a nummeraanduiding
  exists, through `Service/Registry/BagObjectReader`, which reaches integriq by class name at
  runtime (dossiq runs without integriq).
- The check runs only where an administrator set `integration.bag.check` to `on`. There, a save is
  refused when the BAG holds no such nummeraanduiding, and also when no clear answer comes:
  integriq is not installed, PDOK does not answer, PDOK answers about another object, or the
  answer is simulated (integriq's PDOK clients in their mock flavour). Where the check is off, the
  location is saved and marked `bagCheck: not-checked`; a confirmed one is marked `checked`.
- The `case-location` schema gains `bagCheck`. Its version stays 1.1.0: OpenRegister's importer sees a change to `properties` whatever the version says, and the register version moves.
- The BAG client classes, `BagRegistrar`, `PdokBagService` and their tests are deleted. The
  Kadaster client is dropped, not moved (Q-R2-11): PDOK serves the same register without a key.
- `PdokLocatieserverService` stays: `lib/Service/PdokService.php` uses it for address search.
- The config keys `pdok_bag_endpoint` and `pdok_bag_source` leave `ConfigKeys`.

## Impact

App config keys no longer read, which an administrator can delete: `integration.bag.mode`,
`integration.bag.baseUrl`, `integration.bag.apiKey`, `integration.bag.source`, `pdok_bag_endpoint`
and `pdok_bag_source`. An instance that ran the check before (`integration.bag.mode` test or live,
or `integration.bag.source` pdok) sets `integration.bag.check` to `on` to keep it, and needs
integriq with `pdok.feature_flag` on. No repair step.

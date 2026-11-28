# bag-routes-retired

## Why

Standards adapters belong in integriq, not in dossiq (ADR-118, hydra design section 5). Dossiq
served three BAG lookup routes, `GET /api/external/bag/address`, `/pand/{id}` and
`/verblijfsobject/{id}`, through `BagController`, with a frontend shim in `src/services/bagApi.js`.
Nothing called them. `git grep` on development for the paths, `bag#`, `dossiq.bag` and `bagApi`
found only the route table, the controller, the shim and its own spec. No file under `src/`
imports the shim, and pipelinq, shillinq, opencatalogi, openregister, launchpad and nextcloud-vue
have no caller either.

## What changes

- The three routes, `BagController`, `src/services/bagApi.js` and their tests are deleted.
- The requirement on the BAG HTTP surface is removed from `bag-register-adapter`.
- Parity rows 5.10 and 12.16 cited `BagController` as evidence. They now cite
  `lib/Service/PdokService.php` and `src/components/map/AddressSearch.vue`, which is what a user
  reaches to find an address. Both ratings hold.

## Impact

No caller changes, because there was none. The BAG adapter itself (`BagAdapterInterface`, its
three implementations and `BagRegistrar`) stays for now: `LocationBagValidationListener` still
uses it. It moves to integriq in a later change. No schema change, no repair step, no version
bump.

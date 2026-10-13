# Tasks: zuiddrecht-example-set

Kind: data. No PHP changes. Depends on openregister#4603 (the loader) and dossiq#3865 (the group `behandelaars` on the Woo case type).

## 1. The fragment

- [x] 1.1 `lib/Settings/example-sets/zuiddrecht.json` with `after: ["opencatalogi"]`, 6 group entries and 19 objects.
- [x] 1.2 Requesters: 3 `brpPerson` (Henk, Linda, Fatima) and 2 `kvkCompany` (Slagerij Van der Berg, CloudGemeente B.V.), filled from the cast.
- [x] 1.3 Daan as `kccAgent`, and the planned action type `afspraak-balie` for the report case type.
- [x] 1.4 Five cases on shipped case types: `parkeervergunning-bewoners`, `kenteken-wijzigen-parkeervergunning`, `melding-openbare-ruimte`, `omgevingsvergunning`, `woo-verzoek`.
- [x] 1.5 Two KCC contact moments, the Gemachtigde role, the planned desk appointment, two results and the Woo decision.

## 2. Checks

- [x] 2.1 Static check: every placeholder resolves, every lookup finds a shipped row, every object validates against the merged register schema, no undeclared key. A control file with seven planted faults is refused on all of them.
- [ ] 2.2 After openregister#4603 merges: `occ openregister:example-set:install zuiddrecht --check` exits 0 on an instance with dossiq#3865, then the install exits 0.

## 3. Requests on the loader (openregister#4603)

- [ ] 3.1 Service accounts: dossiq's background jobs need an enabled account in `dossiq-background-service`, named in app config `dossiq background_service_account`. The loader can neither create an account outside the cast nor set app config.
- [ ] 3.2 App config: `dossiq woo_publication_catalog_slug` should be `zuiddrecht` so new Woo publications land in the Zuiddrecht catalog.
- [ ] 3.3 The instance base URL: the case's `wooPublicationUrl` is an absolute URI, and the loader has no placeholder for the instance URL.
- [ ] 3.4 Updating an earlier object: the case's `result` points at the result row, which is written after the case. The loader writes each object once, so the case keeps no `result` link.

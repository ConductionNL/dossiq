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
  - Live 25 Nov 2026 (sandbox Nextcloud 34.0.6 + MariaDB, OpenRegister main 987938d4, dossiq development, opencatalogi development): `--check` refused 4 objects. Two were dossiq's: the DqAanbesteding and DqSubsidie board cases in `zuiddrecht.d/65-procedure-board-cases.json` looked up the on-request `aanbesteding` and `subsidie` case types, and `ZuiddrechtSetNeedsNoOnRequestPackageTest` read only `zuiddrecht.json`, never `zuiddrecht.d/`. Fixed: both cases and their terms now ship as those packages' `examples/` (aanbesteding 1.13.0, subsidie 1.11.0), and the test reads the fragments (red first on the old fragment). The other two came from the instance: the `lhs-matrix` auto package was not installed yet (`occ dossiq:package:install lhs-matrix` installs it), and the set's `after: opencatalogi` was missing. With both in place, **`--check` exits 0**.
  - The install still exits 2, for three reasons that are not dossiq set data: (1) the grant `woo-fietspad-juridische-zaken` is refused by OpenRegister's sharing service ("Only the owner or an administrator may change sharing on this object", because the case is not Pieter's own; the same OR share refusal the launchpad manifest-grants lane hit); (2) Collectives is not installed in the sandbox; (3) the audit entry `jeugdwet-toestemming-vastgelegd` needs the jeugdwet package's example consent, which only installs when `demo_data_decided` was `installed` before the auto packages ran. Also sandbox-only: opencatalogi publications need a TOOI on the organisation (set by hand in the database). Left unticked until the OR grant path lets a share be made as its recorded sharer.

## 3. Loader features used (openregister#4603)

- [x] 3.1 Service account `dossiq-background` in `dossiq-background-service` (REQ-EXS-014).
- [x] 3.2 App config `background_service_account: {{account:background.uid}}` and `woo_publication_catalog_slug: zuiddrecht` (REQ-EXS-015).
- [x] 3.3 `wooPublicationUrl` needs nothing: dossiq computes it.
- [x] 3.4 Changes `parkeren-henk-uitkomst` and `woo-zaaksysteem-uitkomst` set each case's `result` once the result exists (REQ-EXS-018).

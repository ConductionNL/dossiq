# Tasks: example-set-pieter-reads-the-household

- [x] Pieter in `dossiq-sensitive` in the Zuiddrecht set
- [x] Unit test
- [x] Pieter in `dossiq-sociaal-domein`, the melding-jeugdwet handling team (case read without a grant), with a unit test
- [ ] Re-install the set on :8080 and check DqSociaalDomein's household card
  - 25 Nov 2026 sandbox (NC 34.0.6, MariaDB, openregister main 55f2aa3e): the Oosthaven case now installs; Pieter still gets 403 `household-not-allowed`. Dossiq's `dossiq.case-type-rights` provider hands OpenRegister a read row for `dossiq-sociaal-domein` (the case's `assignedGroup`, confidentiality vertrouwelijk), but OpenRegister's `find` as Pieter still answers DoesNotExist, so the case read fails on the OpenRegister side.

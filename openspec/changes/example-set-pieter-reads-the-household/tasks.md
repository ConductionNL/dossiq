# Tasks: example-set-pieter-reads-the-household

- [x] Pieter in `dossiq-sensitive` in the Zuiddrecht set
- [x] Unit test
- [x] Pieter in `dossiq-sociaal-domein`, the melding-jeugdwet handling team (case read without a grant), with a unit test
- [ ] Re-install the set on :8080 and check DqSociaalDomein's household card
  - 25 Nov 2026 sandbox (NC 34.0.6, MariaDB, openregister main 55f2aa3e): the Oosthaven case now installs; Pieter still gets 403 `household-not-allowed`. Dossiq's `dossiq.case-type-rights` provider hands OpenRegister a read row for `dossiq-sociaal-domein` (the case's `assignedGroup`, confidentiality vertrouwelijk), but OpenRegister's `find` as Pieter still answers DoesNotExist, so the case read fails on the OpenRegister side.
  - Cause found 25 Nov 2026 (same sandbox): multitenancy, not RBAC. OpenRegister's resolved read rules do grant `dossiq-sociaal-domein` on this case (by case type and by assignedGroup), but OpenRegister's own Zuiddrecht cast (`openregisterOrganisations` > `waterland` > `members`) puts Pieter in "Gemeente Waterland", which becomes his active organisation, and the example cases sit in the Default Organisation, so every OpenRegister read as Pieter (`GET /api/objects/26/47/<case>` 404) filters them out. Next step, in openregister's cast: give Waterland a member other than Pieter, or make Pieter's active organisation the one the cases are in; then re-check this task.

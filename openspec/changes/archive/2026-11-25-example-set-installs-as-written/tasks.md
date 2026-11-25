# Tasks

- [x] 1.1 Test: an object refers only to objects of this fragment written before it (fails on development: 4 contact moments)
- [x] 1.2 Test: every value has the scalar type its schema declares (fails on development: Afdelingshoofd plafond null)
- [x] 1.3 Test: the social-domain group has a worker without the sensitive grant (fails on development)
- [x] 2.1 Move the four Buurtvereniging Oost contact moments below their cases
- [x] 2.2 Leave `plafond` out of the Afdelingshoofd mandate
- [x] 2.3 Add Fatma Kaya to `dossiq-sociaal-domein`
- [x] 3.1 Re-run `occ openregister:example-set:install zuiddrecht --check` on :8080 after merge
  - Done 25 Nov 2026 on a sandbox instance (NC 34.0.6, MariaDB, openregister main, opencatalogi and dossiq development, packages aanbesteding/subsidie/lhs-matrix installed): "Every object passes its schema", exit 0. The real install then refused two social-domain cases (no `gdprClassification`); fixed in the same PR, requirement added.

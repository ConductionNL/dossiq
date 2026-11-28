# Tasks: organisations-list-cells-as-drawn

- [x] 1.1 Test: the Name column wraps and no open cases reads "None" (`tests/vitest/organisationsBoardCells.spec.js`, red on development).
- [x] 1.2 Organisations columns.
- [x] 1.3 Live: DqOrganisaties names wrap and zero open cases read "Geen" on :8080.
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq/opencatalogi development, openregister main, Zuiddrecht set), Dutch locale: Naam cells carry cn-cell--wrap; every organisation without open cases reads "Geen" under Lopende zaken (Buurtvereniging Oost reads 1).

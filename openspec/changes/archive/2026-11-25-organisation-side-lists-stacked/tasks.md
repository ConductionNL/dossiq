# Tasks: organisation-side-lists-stacked

- [x] 1.1 Test (red first): `tests/vitest/organisationSideListsStacked.spec.js`.
- [x] 1.2 `density: "stacked"` on `side-open-case` and `side-open-ticket`.
- [x] 1.3 Next measurement of DqOrganisatie after nc-vue `fix/board-side-lead-gaps` is released.
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq/opencatalogi development, openregister main, Zuiddrecht set), Dutch locale, as admin with nextcloud-vue 2.224.0: the "Lopende zaken" side list is cn-record-list--stacked and its first row starts 10px under the title, with 0px row padding and no hairline (border 0). "Open tickets" is not drawn on this instance (pipelinq not installed); it uses the same density.

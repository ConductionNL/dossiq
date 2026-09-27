# Tasks: woo-requests-gather-documents-from-sources

Tier: V1. Kind: code. Row: opencatalogi `wr-search-sources`.

## 1. Sources and search

- [ ] 1.1 `GET /api/cases/{id}/woo/sources` (design D-2): the platform sources
  always, integriq's when `FleetAppId::getService()` resolves it and a Graph
  connection exists.
  - unit: controller test with integriq absent and present, asserting status and body
  - route auth and IDOR gates green
- [ ] 1.2 `POST /api/cases/{id}/woo/sources/search`: dispatch integriq's search
  command; 50 rows per source with the remaining count; `requireCaseMutationAccess()`.
  - unit: refusal for a caller without case access; a stubbed integriq answer
    mapped to rows
  - blocked on integriq's search operation for the live path; the stub test lands first

## 2. Adding to the case

- [ ] 2.1 `POST /api/cases/{id}/woo/sources/add` (design D-3): copy files as the
  caller, fetch integriq results, link other cases' documents; a result per pick.
  - unit: a file the caller cannot read is refused and the rest are added
  - integration: an added file appears as a document on the case and in
    `getOutstanding()`
- [ ] 2.2 `provenance` on the document projection (design D-4), written at the add.
  - unit: the projection carries source, location, terms, searchedAt, searchedBy

## 3. The dialog

- [ ] 3.1 `src/dialogs/GatherDocumentsDialog.vue` and header action
  `woo-gather-documents` on `#CaseDetail`, offered on a Woo case: terms, a
  period, the sources as checkboxes, results grouped per source, Add selected.
  - vitest: the dialog calls the unified search API per platform source and the
    dossiq endpoint for integriq
  - `npm run check:manifest` and `npm run lint` exit 0
- [ ] 3.2 `tests/e2e/woo-gather-documents.spec.ts`: a handler searches files,
  adds two, and finds them outstanding for assessment; citing the scenarios.

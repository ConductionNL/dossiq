# Tasks: case-files-list-columns

- [ ] 1 `src/services/documentRowData.js`: a function of the listed nodes that asks the document projection for those file ids in one call and returns `{ [fileId]: { documentType, direction, party, verdict } }`. Reuse the existing projection read; add no endpoint if one already answers by file ids.
- [ ] 2 `src/manifest.json`, CaseDetail files widget `props`: `columns` for `documentType`, `direction`, `party`, `verdict` as `source: 'row'`, labels in Dutch as DqZaakDocumenten draws them; `rowData` names the provider from task 1 (register it in `src/registry.js` if the widget resolves functions by name).
- [ ] 3 The verdict column reads the review verdict only when `@context.caseType.documentReview.enabled`; otherwise the column is not declared.
- [ ] 4 `tests/vitest/documentRowData.spec.js`: one call for 40 nodes, missing file ids map to empty.
- [ ] 5 `tests/e2e/case-files-columns.spec.ts` for the first scenario of REQ-ZAK-COL-01.
- [ ] 6 Live pass on :8080 against board DqZaakDocumenten (pixel check of the column order).
- [ ] 7 `openspec/parity/capabilities.json` row 4.8: built, evidence the manifest path and the provider.

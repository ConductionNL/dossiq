# Tasks: files-dropped-on-the-list

Kind: code. Board canon: DqZaakDocumenten and DqZaakDocumentenSlepen
(design-system#148). Needs nextcloud-vue#1419 (change
`caption-edit-link-and-files-drop-state`) released and installed.

- [x] 1.1 `src/manifest.json`, `case-files` widget: `uploadButton`,
  `dropOverlay` and `dropHint` set to true, with a `_dropNote`.
  - `tests/vitest/filesDroppedOnTheList.spec.js`
- [x] 1.2 e2e: the button, the hint and the drop state on a real case.
  - `tests/e2e/case-documents-on-the-case.spec.ts`
- [ ] 2.1 Bump `@conduction/nextcloud-vue` to the release that carries
  nextcloud-vue#1419 (not in this change: no release exists yet).

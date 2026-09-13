# Tasks: document-correspondents

Tier: V1. Kind: config plus two writers. Row 5.12. Waits on
`documents-on-the-case` task 1 (the projection schema).

- [ ] 1.1 The projection schema: `sender`, `recipient`, `direction` with
  titles and `facetable` on `direction`.
  - `@spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md`
- [ ] 1.2 Beschikking delivery writes `recipient` and `direction` when it
  files the letter.
  - unit over the store stub: the projection carries both after delivery
- [ ] 1.3 Mail intake writes `sender` and `direction`; party matched by
  e-mail address when one matches, name otherwise.
  - unit with a fixture message
- [ ] 2.1 `src/manifest.json` `#CaseDetail` Files tab: columns Sender and
  Recipient (read by nextcloud-vue `files-browser-columns` when it lands).
- [ ] 2.2 Remove `dispatch` from `lib/Settings/dossiq_register.json` and its
  seed; grep first and record the count.
- [ ] 3.1 `tests/e2e/document-correspondents.spec.ts`; `openspec validate
  document-correspondents --strict`.

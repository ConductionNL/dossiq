# Tasks: the-contact-360-shows-documents

Tier: V1. Kind: config. Row 5.4. No backend: the filter reads
`informatieobject.sender` and `informatieobject.recipients`, which
`document-correspondents` already stores as party references.

## 1. The panel

- [ ] 1.1 `src/manifest.json`, page `ContactDetail`: widget
  `contact-documents`, `type: object-list` over `dossiq`/`informatieobject`,
  filtered on this contact as sender or as recipient, sorted on date
  descending, limit 25. Columns title, direction, type, date and case.
  - `@spec openspec/changes/the-contact-360-shows-documents/specs/kcc-klantcontact-integratie/spec.md`
- [ ] 1.2 The same widget on `OrganisationDetail`, over the same schema
  and the same two fields. An organisation is a party the way a person is,
  so the two pages differ only in the record they are bound to.
- [ ] 1.3 `rowRoute` lands on `CaseDetail` for the case the document is on,
  and the Files tab of that case is where the file itself opens. A document
  route of its own is deliberately not added: a document out of its case is
  a file with no reason attached.
- [ ] 1.4 `emptyText` reads as a sentence about this contact, not as a
  blank panel. A contact with no documents and a listing that failed must
  not look the same.

## 2. What a reader may not see

- [ ] 2.1 Confirm against a live instance that the objects endpoint counts
  what it refuses rather than dropping it, the way
  `ContactCasesPanel` does in openregister#3893. Record the measured
  answer in this file. If it drops, the count is wrong on every page that
  reads it, and that is an openregister row rather than a dossiq one.
  - measured: [pending]

## 3. Verification

- [ ] 3.1 `tests/vitest/contactDocuments.spec.js`: both pages declare the
  widget, both filter on the two correspondent fields, and the layout
  leaves no gap under it.
- [ ] 3.2 `tests/e2e/contacts-domain.spec.ts` gains one scenario: a contact
  who received one letter shows it, with its direction and its case.
- [ ] 3.3 `openspec validate the-contact-360-shows-documents --strict`.

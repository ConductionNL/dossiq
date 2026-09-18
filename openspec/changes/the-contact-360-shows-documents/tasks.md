# Tasks: the-contact-360-shows-documents

Tier: V1. Kind: config. Row 5.4. No backend: the panel reads the `dispatch`
join `document-correspondents` already writes.

## 1. The panel

- [x] 1.1 `src/manifest.json`, page `ContactDetail`: widget
  `contact-documents`, `type: object-list` over `dossiq`/`dispatch`,
  filtered on `involvedParty` being this contact, sorted on `sendDate`
  descending, limit 25. Columns document title, direction, type, date and
  case.
  - `@spec openspec/changes/the-contact-360-shows-documents/specs/kcc-klantcontact-integratie/spec.md`
  - **THE SOURCE IN THIS TASK WAS THE WRONG ONE, and the reason is the
    filter.** A document names its correspondents in TWO fields,
    `informatieobject.sender` (one party) and `informatieobject.recipients`
    (an array). An object-list `filter` is a MAP: every entry NARROWS, and
    there is no cross-field OR in the grammar. Filtering `informatieobject`
    would therefore have listed half the answer, or needed two panels for
    one question, and the row's direction would have come from which panel
    it landed in rather than from the record. `dispatch` is the join
    `document-correspondents` wrote for exactly this: one row per document
    per party per role, carrying the case. `involvedParty` alone is the
    whole filter, `relationshipType` IS the direction, and
    `content.extend` inlines the document and the case beside it.
  - The direction column carries its own `enumLabels`. The stored codes are
    `afzender` and `geadresseerde`; a row reading `afzender` at a KCC agent
    with a caller on the line says nothing.
- [x] 1.2 The same widget on `OrganisationDetail`, over the same schema and
  the same field. An organisation is a party the way a person is, so the
  two pages differ only in the record they are bound to.
- [x] 1.3 The case deep link is the `open-case` row action, a function
  handler in `src/utils/contactDocuments.js`, NOT `rowRoute`.
  - **MEASURED in the library source.** `CnObjectListWidget.onRowClick`
    pushes `{ name: rowRoute, params: { id } }` where `id` is
    `row.id ?? row['@self'].id`: the ROW's own id. Here the row is a
    dispatch and the destination is its case, so `rowRoute: "CaseDetail"`
    would have opened a case page for a uuid no case has. That renders as a
    missing case, which reads as a deleted case rather than as a bug, and
    is the kind of wrong answer nobody reports.
  - A function handler for the reason `claimCase` is one: the row
    dispatcher's vocabulary is `navigate`, `open-page` and a handler name,
    and none of the three can read an id out of the row. It leaves through
    `window.location.assign`, the way `openIntegriqConnections` does,
    because a bare handler has no `$router` to push onto.
  - A document route of its own is still deliberately not added: a document
    out of its case is a file with no reason attached.
- [x] 1.4 `emptyText` reads as a sentence about this contact, and
  `errorText` is declared beside it. The widget draws its error line above
  the empty state and never both (`CnObjectListWidget`, the `v-else-if` on
  `error` and the `!this.error` in its empty computed), so a contact with
  nothing on file and a listing that failed cannot look the same.

## 2. What a reader may not see

- [x] 2.1 Confirm against a live instance that the objects endpoint counts
  what it refuses rather than dropping it.
  - **measured: NOT MEASURED, and this task should not claim otherwise.**
    There is no instance this lane may drive: the shared instance belongs
    to other sessions and the browser service is down on this box. What was
    done instead is to make the panel add nothing of its own on top of the
    endpoint's answer, so whatever OpenRegister does is what a reader sees,
    in one place rather than two. The requirement is reworded to say that,
    and to say plainly that if the endpoint drops rather than counts, the
    count is wrong on every page in the fleet that reads it and the fix is
    an openregister row.
  - `limit: 25` is declared so a capped list shows its footer rather than
    reading as the whole answer.

## 3. Verification

- [x] 3.1 `tests/vitest/contactDocuments.spec.js`: both pages declare the
  widget, both filter on `involvedParty`, both carry the direction with its
  labels, both inline the document and the case, neither declares a
  `rowRoute`, both say something in words when empty, and the layout row
  sits straight under the contact moments with no gap. Twenty assertions,
  plus a walk of the whole manifest asserting the widget is placed exactly
  twice, so a helper that matched nothing cannot satisfy the per-page arms
  by running zero assertions.
- [x] 3.2 `tests/e2e/contacts-domain.spec.ts` gains two scenarios: a contact
  who received one letter shows it with its direction, and a contact with
  none is told so in a sentence. The letter and its dispatch are seeded by
  the spec, because a panel whose filter names a property the schema does
  not carry renders empty and "no documents" and "the filter is wrong" look
  identical. Written and tagged, not run: there is no Playwright run on
  this box.
- [x] 3.3 `openspec validate the-contact-360-shows-documents --strict`.
- [x] 3.4 Mutation check: making `openCaseOfDocument` navigate to the row's
  own id instead of its case reddens all three of its arms, including
  `expect(assign).not.toHaveBeenCalled()` on the row that names no case.
  That is the exact defect `rowRoute` would have shipped. Restored, green.

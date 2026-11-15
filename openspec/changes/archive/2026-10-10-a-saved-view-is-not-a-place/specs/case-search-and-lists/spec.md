# case-search-and-lists delta: a-saved-view-is-not-a-place

## MODIFIED Requirements

### Requirement: A saved list of cases is a lens, not a place (REQ-CSL-05)

A saved view on the Cases and Queue pages SHALL be applied in place. The page's own
address SHALL carry the view's filters, its search term and its sort, and no saved
view SHALL have an address of its own. A handler still sends a colleague the list
rather than a description of it: the address they copy reproduces it.

A sort in an address SHALL be spelled `_order`, the JSON-encoded ordered array
`[{"key": …, "order": "asc"|"desc"}, …]`. That is the spelling every other list in
this app already writes (`src/manifest.json` dashboard `viewAllRoute.query`) and the
only one OpenRegister's object API reads. No other spelling SHALL be written.

Clearing the filters SHALL leave nothing of an applied view in the address — its
filter keys, its search term and its sort all go. A view sets state the reader did
not choose and cannot see the origin of, so anything left behind is state no control
on the page can undo.

**Reason**: the route worked, but what it wrote into the address was a sort format
nothing in the stack reads — not `parseSortKeysFromQuery`, which seeds a list's sort
on load, and not OpenRegister, which accepts `_order` alone. A view's sort therefore
survived only in memory, the two keys reached `searchObjects()` as filters on
whole-result bulk selections, and clearing the filters could remove neither them nor
the view's own filter keys. A view stores filters, a search term and a sort, which is
not enough to be somewhere you go.

#### Scenario: A saved view narrows the list at the page's own address
@e2e tests/e2e/a-saved-view-is-not-a-place.spec.ts

- **GIVEN** a saved view on the Cases page
- **WHEN** it is applied
- **THEN** the address SHALL stay the page's own, carrying the view's filters, its
  search term and its sort

#### Scenario: The sort a view carries survives a reload
@e2e tests/e2e/a-saved-view-is-not-a-place.spec.ts

- **GIVEN** the address a view left behind, opened in a tab that never applied it
- **WHEN** the page loads
- **THEN** the list SHALL be sorted the way the view was

#### Scenario: Clearing the filters leaves nothing of the view behind
@e2e tests/e2e/a-saved-view-is-not-a-place.spec.ts

- **GIVEN** a view carrying filters, a search term and a sort has been applied
- **WHEN** the handler clears the filters
- **THEN** the address SHALL carry none of them

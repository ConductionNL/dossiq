---
status: done
---

# case-search-and-lists Specification (dossiq consumer, OpenRegister engine)

**Scope:** dossiq. The query layer is OpenRegister's.
**Depends on:** OpenRegister search quality, operators and facets (openregister#3768, openregister#3806), the objects provider behind Nextcloud unified search, saved views, and the task inbox (openregister#3581).
**Capability area:** `search` in `openspec/parity/capabilities.json`.

## Purpose

A handler types `dakkapel` and expects the four cases about dakkapellen. A
coordinator wants yesterday's list back without rebuilding its filters. A team lead
wants the tasks due next week on one case. This capability is what dossiq does with
a typed term and with the list it produces.

Decision D22 puts the query layer in OpenRegister. dossiq writes no search engine,
no index and no query planner. What dossiq owns is what a case type says about
itself: which of its fields are worth typing a term against, which are worth
filtering on, which belong in a column, and what the page does when the platform
refuses a term.

That ownership is the point. Before it, every surface guessed which fields to scan,
and they guessed differently, so `2026-0042` found the case with that number and the
four that merely mentioned it.

This spec is the index of that capability for the features page. Each requirement
names the change that built it rather than restating that change's rules.

## Where the line runs

| Question | Answered by | Rule lives in |
|---|---|---|
| Which rows match this term | OpenRegister | the search index and the query layer |
| How is this field searched, and with which control | dossiq declares | `lib/Settings/register.d/39-search-declarations.json` |
| Which fields of this case type are worth filtering on | dossiq | the case type's own declaration |
| Which columns does this list show | dossiq | the case type layout and `src/manifest.json` |
| Where is a saved list kept, and who may open it | OpenRegister | saved views, its owner's and the public ones |
| Does a case appear in the platform's own search bar | OpenRegister | the objects provider |

## Requirements

### Requirement: A term matches the fields the case declares, and no others (REQ-CSL-01)

Each `case` property a person types a term against SHALL declare a `matchType`, and
each property a list offers as a filter SHALL declare an `inputControl`. The
declaration SHALL live in the register, so that the list, the facet, the API and the
citizen portal read one answer instead of four.

An identifier SHALL be matched whole. A free text field SHALL be matched as text. A
boolean SHALL be filterable without joining the free text scan, because a column
answering to the word "true" is a wrong answer rather than a better one.

Built by `case-search-declares-its-fields`, requirement REQ-CSD-01.

#### Scenario: The identifier answers to the whole case number
@e2e tests/e2e/case-search-declares-its-fields.spec.ts

- **GIVEN** a case whose identifier is a case number, and a second case that only
  mentions that number in its description
- **WHEN** a handler searches for the number
- **THEN** the first case SHALL be found by its identifier
- **AND** the second SHALL NOT be found by its identifier

### Requirement: A refused term says where it broke (REQ-CSL-02)

A search term takes the platform's operators, brackets and quoted phrases. When the
platform refuses a malformed term, dossiq SHALL show the position and the term
beside the box it was typed into, and SHALL NOT render the refusal as a list with
nothing in it. A reader who cannot tell a broken query from an empty result stops
trusting the count.

A term the platform accepted SHALL draw no hint.

Built by `case-search-declares-its-fields`, requirement REQ-CSD-02.

#### Scenario: The Cases page says where a refused term broke
@e2e tests/e2e/case-search-declares-its-fields.spec.ts

- **GIVEN** the Cases page
- **WHEN** a handler searches with an unbalanced bracket
- **THEN** a hint SHALL name the position and the term
- **AND** the list SHALL NOT report that nothing matched

#### Scenario: A term the page could read draws no hint
@e2e tests/e2e/case-search-declares-its-fields.spec.ts

- **GIVEN** the Cases page
- **WHEN** a handler searches for a plain word
- **THEN** the term SHALL reach the platform as typed
- **AND** no hint SHALL be shown

### Requirement: A case type's own fields filter the list it appears in (REQ-CSL-03)

A case type SHALL say which of its own fields are worth filtering on, and the Cases
page SHALL offer exactly those once that type is picked. A field the case type did
not declare SHALL NOT appear in the bar. With no case type picked there SHALL be no
bar, because a filter over fields no row shares filters nothing.

The filter SHALL be answered by the platform, not applied over a page already
fetched, so that the count under the list and the list itself cannot disagree.

Built by `case-type-fields-filter-the-case-list`, archived 2026-09-20, requirements
REQ-CTF-01 and REQ-CTF-02.

#### Scenario: The bar offers the declared field and not the silent one
@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

- **GIVEN** a case type declaring one of its two extra fields as filterable
- **WHEN** a handler picks that case type on the Cases page
- **THEN** the filter bar SHALL offer the declared field
- **AND** it SHALL NOT offer the one the case type left silent

#### Scenario: A field filter keeps the matching case and drops the other
@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

- **GIVEN** two cases of one type differing in one declared field
- **WHEN** a handler filters on that field
- **THEN** only the matching case SHALL remain in the list

### Requirement: The columns of a list follow the case type (REQ-CSL-04)

A case type SHALL carry its own list layout, so that picking it changes the columns
of the list rather than leaving a reader to read a permit through the columns of a
complaint. Clearing the case type SHALL restore the shared columns.

The layout SHALL live on the case type record, not only in a shipped file, so an
administrator can change it without a release.

Built by `columns-follow-the-case-type`, archived 2026-09-20.

#### Scenario: Picking a case type changes the header
@e2e tests/e2e/columns-follow-the-case-type.spec.ts

- **GIVEN** a case type carrying a list layout of its own
- **WHEN** a handler picks it on the Cases page and then clears it again
- **THEN** the columns SHALL follow the case type
- **AND** clearing it SHALL bring the shared columns back

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

Retracts `cases-views-are-places` (requirement REQ-CM-44), which gave a view a route
and a pinned entry in the navigation. The route was built and it worked; what it
wrote into the address was a sort format nothing in the stack read, so the list it
addressed came back unsorted on every reload. A view stores filters, a search term
and a sort — not enough to be somewhere you go.

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

### Requirement: The task list answers its search fields at the engine (REQ-CSL-06)

The Tasks page SHALL offer filters on case, due date range, state and priority, each
answered by the task engine server side. No filter SHALL be applied over a page
already fetched. A lens and a field SHALL compose rather than replace one another,
and both SHALL be carried in the address.

Built by `task-search-fields`, requirement REQ-TASK-021.

#### Scenario: Narrowing the tasks to one case
@e2e tests/e2e/task-search-fields.spec.ts

- **GIVEN** tasks on two cases
- **WHEN** a handler picks one case in the sidebar
- **THEN** only that case's tasks SHALL remain
- **AND** the address SHALL carry the case filter

#### Scenario: A due window narrows inside a lens
@e2e tests/e2e/task-search-fields.spec.ts

- **GIVEN** the Mine lens is active on the Tasks page
- **WHEN** a handler sets a due window
- **THEN** the list SHALL hold their tasks due in that window
- **AND** the lens SHALL still be active

## What this capability does not cover

Stated here rather than left for a reader to discover.

### Answered by a sibling capability

- **A case in the platform's own search bar.** `case-search-via-or-unified-search`
  covers the objects provider and the deep links that make a hit open a page rather
  than a JSON document.
- **Results narrowed to what you may see.** The provider delegates to OpenRegister's
  access rules, so a case outside your grants is not in the answer.
  `case-access-control` holds that half.

### Not built

- **Full text over the content of a document.** The term reaches the case and its
  metadata. It does not reach the words inside an attached file.
- **A saved list shared with a department or a role.** A saved view belongs to the
  person who made it. There is no shared one, with or without rights on it.
- **An alert when a saved list crosses a number.** Threshold alerts sit on complaint
  analytics, not on a saved search.
- **A filter on what a case has ever been.** Every transition is stored and queried
  by process mining, and no list filter asks "was ever in status X".

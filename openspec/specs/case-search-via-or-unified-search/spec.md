# case-search-via-or-unified-search Specification

## Purpose
TBD - created by archiving change case-search-via-or-unified-search. Update Purpose after archive.

## Requirements

### Requirement: Searchable schema opt-in

A schema in the dossiq register is searchable unless it opts out.
OpenRegister's `Schema` entity declares `protected bool $searchable = true`,
so a definition that carries no `searchable` key imports as searchable and
`ObjectsProvider` includes it. Measured on the running instance 2026-09-08:
`brpPerson` and `kvkCompany` both report `searchable: true` with no flag
anywhere in `lib/Settings/register.d/25-brp-kvk.json`, and a search for a
seeded person returned that person.

The dossiq register definition therefore SHALL NOT be read as the allow-list
for unified search. It flags `case`, `objectionProceeding` and `beroep`
explicitly, which is a statement of intent and changes nothing at runtime; a
schema SHALL only be flagged `searchable: false` when it should be kept OUT of
search, and a schema SHALL NOT be flagged `true` merely to document that it is
searchable, because the flag only lands on an instance behind a schema version
bump and a re-import.

🔴 A task is not on that list any more, and that is a gap rather than a
tidy-up. `remove-casetask` deleted the `caseTask` schema, so there is no
object left to index and no `deepLinks` entry left to resolve. Engine tasks
have no unified-search provider at all today: the engine keeps its tasks in
its own table, outside the object index this opt-in feeds, and no `deepLinks`
shape addresses them. dossiq cannot close this, because the shape is
OpenRegister's to define. The route `/tasks/:id` is unchanged, so the task
page is ready the day a provider exists, and
`tests/vitest/searchableSchemas.spec.js` pins the list at three. The gap is
recorded in `openspec/changes/remove-casetask/tasks.md`.

What decides whether a result is USEFUL is the deep link, not the flag. See
the next requirement.

#### Scenario: Case findable in unified search

- **GIVEN** a case titled "Kapvergunning Dorpsstraat 12" exists and the user may read it
- **WHEN** the user types "Kapvergunning" in the Nextcloud unified search bar
- **THEN** the case appears as a result under the OpenRegister objects provider
- **AND** activating it navigates to `/apps/dossiq/cases/{uuid}`

@e2e exclude Requires the OR ObjectsProvider pipeline and NC search UI; provider behaviour is covered by openregister's own unified-search-provider e2e suite.

#### Scenario: Non-flagged schema absent from search

- **GIVEN** a `decisionType` config object exists
- **WHEN** a user searches for its title
- **THEN** it does not appear in unified search results (schema not flagged searchable)

@e2e exclude Same rationale — declarative flag asserted by unit test on the register JSON.

#### Scenario: A schema flagged out stays out

- **GIVEN** a schema flagged `searchable: false` in the register definition
- **WHEN** a user searches for the title of one of its objects
- **THEN** it does not appear in unified search results

@e2e exclude Declarative opt-out asserted by unit test on the register JSON; the provider's allow-list behaviour is openregister's.

#### Scenario: RBAC-restricted case hidden

- **GIVEN** a confidential case the user has no OR RBAC read access to
- **WHEN** the user searches for its title
- **THEN** the case does not appear (OR provider delegates to `searchObjectsPaginated(_rbac: true)`)

@e2e exclude Enforced and tested in openregister (provider security contract); dossiq adds no code path.

### Requirement: Version-gated re-import

The change SHALL bump both the register `info.version` and the app version in `appinfo/info.xml`, because the OR register repair step only re-imports schema definitions when the version advances.

#### Scenario: Upgrade re-imports searchable flags

- **GIVEN** an instance running the previous dossiq version with the register already imported
- **WHEN** the app upgrades and the repair step runs
- **THEN** the register import re-runs (version gate passes) and the five schemas carry `searchable: true` in OR

@e2e exclude Repair-step import mechanics are owned and tested by openregister; the version bump is asserted by unit test comparing info.xml and register JSON versions advanced together.

### Requirement: Deep links resolve to real pages

Every dossiq schema that has a standalone detail page SHALL have a `deepLinks`
entry in `src/manifest.json` mapping `(dossiq, <schemaSlug>)` to that route,
and the entry's `urlTemplate` SHALL name a route the manifest carries.

This is the requirement that bites. `ObjectSearchResultFormatter` asks
`DeepLinkRegistryService::resolveUrl()` and, when no app has claimed the
`(register, schema)` pair, falls back to `openregister.objects.show` — the
JSON API endpoint. A search result with no deep link therefore looks correct
and opens a JSON document; a search result whose template names a route the
manifest does not carry looks correct and opens a 404. Neither failure is
visible in the search itself.

`brpPerson` maps to `/apps/dossiq/contacts/{uuid}` and `kvkCompany` to
`/apps/dossiq/organisations/{uuid}`, the pages `contacts-domain` added.

#### Scenario: A search hit on a person opens the contact page
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded `brpPerson` row
- **WHEN** the OpenRegister objects provider is searched for its display name
- **THEN** a result SHALL carry the resource url `/apps/dossiq/contacts/<id>` for that row

#### Scenario: A search hit on an organisation opens the organisation page
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded `kvkCompany` row
- **WHEN** the OpenRegister objects provider is searched for its trade name
- **THEN** a result SHALL carry the resource url `/apps/dossiq/organisations/<id>` for that row

#### Scenario: Every deep link names a real route

- **GIVEN** the manifest at HEAD
- **WHEN** each `deepLinks[].urlTemplate` is compared against `pages[].route`
- **THEN** each SHALL correspond to a page route, with `{uuid}` standing where the route has `:id`

@e2e exclude Declarative cross-file consistency asserted by unit test (vitest) on the manifest.

### Requirement: REQ-CSD-06 A boolean a register fragment adds declares its control

Every boolean property a register fragment adds to the case schema SHALL
declare `inputControl: boolean` and SHALL NOT declare a `matchType`, the
same as the booleans of the case schema itself.

#### Scenario: The DSO overdue flag is filterable

- GIVEN the DSO fragment adds the boolean `deadlineOverdue` to the case
- WHEN the case's search declarations are read
- THEN `deadlineOverdue` declares `inputControl: boolean` and no `matchType`

<!-- Folded 2026-10-10 from archive 2026-09-20-case-type-fields-filter-the-case-list, whose archive step folded nothing. -->

### Requirement: REQ-CTF-01 A case type says which of its fields are worth filtering on

A `propertyDefinition` SHALL carry a `filterable` boolean. A definition that
declares nothing SHALL NOT be offered as a filter.

The declaration SHALL live on the definition rather than on the Cases page,
because the same answer has to serve the list, the API and the portal, and a
page config gives each of them its own.

#### Scenario: A definition that declares nothing is not offered

- **GIVEN** a case type with three property definitions, one of which
  declares `filterable: true`
- **WHEN** a handler narrows the Cases page to that case type
- **THEN** the filter bar SHALL offer that one definition
- **AND** the other two SHALL NOT appear

@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

### Requirement: REQ-CTF-02 The case list filters on a case type's own fields

When the Cases page has narrowed to one case type, it SHALL offer that case
type's filterable definitions as filters, and SHALL compile each filled
field to one `_related[caseProperty][case][…]` block over openregister's
related-row query.

Two filled fields SHALL produce two numbered blocks. They SHALL NOT be
merged into one block, because one block asks for a single `caseProperty`
row that is two property definitions, and no row is that, so the handler
would get an empty list and no reason for it.

The number SHALL sit after the foreign key, as
`_related[caseProperty][case][0][…]`. `RelatedRowFilterParser` reads the
schema, then the foreign key, and only then counts numbered rows, so a
number one level higher names a foreign key called `0` and the whole query
is refused.

The blocks SHALL reach the fetch, and the route query alone does not carry
them. `CnIndexPage` builds its filters with `resolveQueryFilters()`, which
skips the whole underscore namespace because that namespace is the library's
own, so a `_related` key written only to the URL is dropped before the
request is built and the list answers the unfiltered register under an
address that says it is filtered. Nothing is refused, so nothing is said.

The control offered per definition SHALL follow the definition's own
`propertyType`: a number is a range, a date is a date range, an enumeration
backed by `enumValues` is a select, and everything else is text.

#### Scenario: Two fields narrow to the cases that satisfy both

- **GIVEN** a case type with filterable definitions "construction cost" and
  "district", and three cases of that type
- **AND** one case costs 150000 in Noord, one costs 90000 in Noord, and one
  costs 150000 in Zuid
- **WHEN** a handler filters on cost over 100000 and district Noord
- **THEN** only the first case SHALL be listed

@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

#### Scenario: Clearing the case type clears its field filters

- **GIVEN** a handler has narrowed to one case type and filled two of its
  field filters
- **WHEN** the handler clears the case type
- **THEN** the field filters SHALL be cleared with it
- **AND** the list SHALL show every case type again

@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

### Requirement: REQ-CTF-03 A refused field filter says which field broke

A `_related` block openregister refuses SHALL be shown to the handler under
the filter bar, naming the field, and the list SHALL NOT be rendered as
empty.

The notice SHALL be rendered by the filter bar and not by `CaseSearchRefusal`.
That component reads `_search` and its position-in-term shape, and the bar is
the one that knows which definition an id belongs to, which is what turns
"pd-7 is not a valid filter" into a sentence a handler can act on. A refused
`_search` SHALL NOT be reported as a refused field filter.

An empty list and a refused query look the same on screen, and only one of
them is an answer.

#### Scenario: A refused filter is not an empty result

- **GIVEN** a filter openregister refuses
- **WHEN** the handler applies it
- **THEN** the page SHALL show the refusal and name the field
- **AND** SHALL NOT show the empty-result state

@e2e exclude Refusal shapes are openregister's contract; the dossiq half is asserted by vitest over a stubbed ApiError.

### Requirement: REQ-CTF-04 A whole-result act carries the field filters

`readListFilters()` SHALL carry `_related` into a whole-result bulk act
alongside `_search`.

A bulk act that drops the filter acts on every case in the register while
the screen said 43, which is the surprise the selection module exists to
prevent.

#### Scenario: Select all acts on the narrowed set

- **GIVEN** a filtered list of 43 cases out of 400
- **WHEN** the handler selects the whole result and starts a bulk act
- **THEN** the job SHALL receive 43 cases

@e2e exclude Asserted by vitest over `selectionScope`, mutation-checked; the bulk job itself is covered by `bulk-actions-report-progress`.

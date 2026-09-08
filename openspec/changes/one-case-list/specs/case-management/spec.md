## ADDED Requirements

### Requirement: REQ-CM-31 Open work by default, closed work on request

Your work list shows open cases only unless you ask for closed ones. On the
`Cases` page the chips Mine and Unclaimed MUST carry `isFinalStatus = false`
and the chip Closed MUST carry `isFinalStatus = true`, so a closed case
appears under Closed and under All and nowhere else. `isFinalStatus` is a
stored boolean on every case row, so plain equality reaches it and no
derived filter is needed.

#### Scenario: A closed case leaves Mine
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** a case assigned to the signed-in user whose status is final
- **WHEN** you open the Cases page with the chip Mine active
- **THEN** the list SHALL NOT show the closed case

#### Scenario: Closed shows the closed case
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** a case whose status is final and an open case
- **WHEN** you choose the chip Closed
- **THEN** the list SHALL show the closed case and SHALL NOT show the open one

### Requirement: REQ-CM-32 Deadline before in the sidebar

You narrow the case list on a deadline. The `Cases` page sidebar MUST offer
a filter Deadline before, a date input that adds `deadline lt <date>` to the
active query.

**[blocked: the index sidebar has no manifest-declared filter and no
operator]** `CnIndexSidebar` builds its Filters section entirely from the
SCHEMA — `filtersFromSchema` walks the properties marked `facetable: true`
and renders each as a checkbox (booleans) or a values select (everything
else), whose `filter-change` event carries `{ key, values }`. There is no
manifest key for a sidebar filter, no date input among the widget types and
no operator anywhere in that path: a facet can only say `field = one of
these values`, never `field < this date`. Nothing in `@conduction/nextcloud-vue`
2.41 can express this requirement, so no configuration in this repo
satisfies it.

Interim, the state is reachable but not offerable: `resolveQueryFilters`
passes any non-underscore route query through to the fetch, so
`/cases?deadline[lt]=2026-10-01` narrows the list exactly as this
requirement describes, and that is the path the Overdue dashboard tiles
take. What is missing is a control a reader can operate. Unblocking it is a
nextcloud-vue change: a `sidebar.filters[]` array of
`{ field, operator, label, type }` on `CnIndexPage`, rendered beside the
schema facets and merged into the fetch the way the facets already are. It MUST combine with the active chip rather than replace it,
so Mine plus Deadline before shows your cases due before that date. The
Requester text filter on `initiatorDisplayName` sits in the same sidebar and
is specified by `requester-on-the-case` (`initiator-display` REQ-ID-2); this
requirement does not restate it.

#### Scenario: Deadline before narrows the list
@e2e exclude The control does not exist to drive: nextcloud-vue 2.41's index sidebar derives its filters from schema `facetable` properties as value lists, with no date input and no operator, so there is no Deadline before field for a spec to fill. The narrowing itself is covered where it IS reachable, by the Overdue tile's View all in tests/e2e/case-list-lenses.spec.ts, which sends `deadline[lt]` through the route query.

- **GIVEN** two open cases assigned to the signed-in user, one due in 3 days and one due in 30 days
- **WHEN** you set Deadline before to 10 days from today
- **THEN** the list SHALL show the case due in 3 days and SHALL NOT show the case due in 30 days
- **AND** the chip Mine SHALL still be active

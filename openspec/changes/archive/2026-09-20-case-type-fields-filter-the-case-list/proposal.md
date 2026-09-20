---
kind: code
depends_on: []
---

# Proposal: case-type-fields-filter-the-case-list

Gap scan pack c, parity ledger row 9.2 "Advanced search with per-case-type
fields". The consumer half of openregister `query-related-schema-rows`,
merged onto `parity/round2` as openregister#3909, #3916, #3921 and #3923.

## Why

A handler working permits does not search for a word. They search for a
permit whose declared construction cost is over a hundred thousand euro, or
for the objections whose hearing date falls this month. Those values are not
on the case. They are on the case type's own fields, and dossiq stores each
one as a `caseProperty` row pointing back at the case.

The Cases page cannot ask about them. Its search sidebar reads the `case`
schema's declared fields, which `case-search-declares-its-fields` shipped,
and its folder sidebar narrows by case type. Between the two there is no way
to say "of this case type, the ones whose field X is Y". A handler exports
the list and filters it in a spreadsheet, which is the answer every
competitor in the corpus stopped giving years ago.

## What is actually there

Read against `parity/round2`.

openregister ships the query. `lib/Service/Query/RelatedRowFilterParser.php`
reads a filter over a related schema's rows off the wire:

```
_related[caseProperty][case][propertyDefinition]=pd-7
_related[caseProperty][case][value][gte]=100
```

One block is one existence clause: one `caseProperty` row that is both the
named definition and over the named value. Two numbered blocks are two rows.
`RelatedRowQueryApplier` and `RelatedRowExistsClause` build the SQL, and the
related schema's own access predicate travels with it (#3923), so a filter
cannot reach a row the caller may not read.

A malformed block is refused with a sentence rather than dropped. That
matters here more than anywhere: a dropped filter answers the unfiltered
set, which is every case in the register presented as the answer to a narrow
question.

dossiq uses none of it. `git grep '_related\['` in this repo returns nothing.

## What this change does

- **The case type declares which of its fields are worth filtering on.**
  `propertyDefinition` gains `filterable`, and the Cases page asks the
  case type for its filterable definitions when the folder sidebar narrows
  to one.
  A case type with none narrows exactly as it does today.
- **The Cases page renders those fields as filters.** The input control
  comes from the field's own type, the same vocabulary
  `39-search-declarations.json` already uses: a number is a range, an
  enumeration is a select, a date is a date range, text is text.
- **The filters compile to one `_related` block per field.** Two filters on
  one case type are two blocks, because two conditions in one block ask for
  a single row that is two field definitions, and no row is.
- **A refused filter says so where it was typed.** The Cases page already
  renders `CaseSearchRefusal` over a refused `_search`; a refused `_related`
  reads the same way rather than emptying the list.
- **The filters survive the selection.** `readListFilters()` already carries
  `_search` into a whole-result bulk act; it carries `_related` too, or
  "select all 43 cases matching this filter" hands the job every case in the
  register.

## What this change does not do

It does not filter across two case types at once. The fields belong to one
case type, so the filter bar appears only when the folder sidebar has
narrowed to one, and clearing the case type clears the field filters with
it. Offering fields from every case type at once offers a list nobody can
read and a query that matches nothing.

It does not add a second query grammar. The operators are the six the object
query already accepts plus `in`, read off openregister's own parser.

## Impact

- `lib/Settings/register.d/` gains a fragment declaring `filterable` on
  `propertyDefinition`
- `src/manifest.json`, the `Cases` page only
- `src/components/search/` gains the per-case-type filter bar
- `src/utils/selectionScope.js`
- Affected specs: `case-search-via-or-unified-search`
- Size: M

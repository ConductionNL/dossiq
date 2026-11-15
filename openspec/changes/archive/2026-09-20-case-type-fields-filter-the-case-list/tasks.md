# Tasks: case-type-fields-filter-the-case-list

Tier: V1. Kind: code. Row 9.2. Consumer half of openregister
`query-related-schema-rows` (#3909, #3916, #3921, #3923, all merged onto
`parity/round2`).

## 1. The declaration

- [x] 1.1 Measure the contract on openregister `parity/round2` before
      building: the wire shape is `_related[<schema>][<fkProperty>][<op>]`,
      parsed by `lib/Service/Query/RelatedRowFilterParser.php`; two
      conditions in one block are one existence clause and two numbered
      blocks are two; the operators are the six
      `MariaDbSearchHandler::convertToSqlOperator()` accepts plus `in`; a
      malformed block throws rather than being dropped.
      - Measured, and written into the head of
        `src/utils/caseTypeFieldFilters.js` rather than a separate design
        note, because that is the file a later reader has open when they are
        about to get it wrong. The shape it compiles to is exactly the one the
        parser documents, and the vitest asserts the literal keys.
- [x] 1.2 `lib/Settings/register.d/`: a fragment declaring `filterable` on
      `propertyDefinition`, defaulting false.
      - `42-filterable-case-fields.json`. The default is asserted, because a
        definition that declares nothing must not be offered: that is what
        keeps a case type with forty attributes from offering forty filters.
      - vitest: a definition with no key is not filterable; the seeded
        definitions that carry it are the ones named in the fragment

## 2. The filter bar

- [x] 2.1 A per-case-type filter bar component, rendered on the `Cases`
      page only when the folder sidebar has narrowed to one case type.
      - `src/components/search/CaseTypeFieldFilters.vue`, in the
        `after-search` slot. NOT `below-header`: that slot already holds
        `CaseSearchRefusal` and a slot map takes one component, and
        `after-search` is the actions bar's own place for inline refinement
        controls, which is what a filter bar is.
      - vitest: no case type selected renders nothing; a case type with no
        filterable definition renders nothing; clearing the case type
        clears the field filters with it
- [x] 2.2 The control per definition follows `propertyType` and
      `enumValues`: number is a range, date is a date range, an enumeration
      is a select, everything else is text.
      - Both date spellings are read. `date` is a legacy alias for `string`
        with `format: date` and both are stored on live instances, so a
        definition in either spelling gets a date range rather than a text box.
      - vitest, mutation-checked
- [x] 2.3 Compile the bar's state to one `_related[caseProperty][case][…]`
      block per filled field, numbered when there is more than one.
      - vitest, mutation-checked: collapsing the block index so two fields
        share one block reddens the assertion that reads the numbered keys.
        That mutation is the real defect it guards: one `caseProperty` row
        cannot be two property definitions, so the merged block answers an
        empty list and nothing on screen says why.

## 3. Refusal and selection

- [x] 3.1 A refused `_related` renders through `CaseSearchRefusal` in the
      `after-search` slot, naming the field rather than the raw block.
      - Rendered by the FILTER BAR rather than by `CaseSearchRefusal`, and
        that is the correction. `CaseSearchRefusal` reads `_search` and its
        position-in-term shape, and it holds `below-header`; the bar is the
        component that knows which definition an id belongs to, which is what
        turns "pd-7 is not a valid filter" into a sentence a handler can act
        on. `readRelatedRefusal()` is asserted NOT to claim a refused `_search`
        as its own.
      - vitest: the hint renders over a refusal and not over an empty result
- [x] 3.2 `src/utils/selectionScope.js` carries `_related` into a
      whole-result bulk act alongside `_search`.
      - The list is a DENY list, so `_related` already survived. What was
        missing is the reason, which is now written beside `_search`'s, and
        the test: adding `_related` to `NOT_A_FILTER` now reddens an assertion
        quoting what it would cost, so it is a change somebody has to defend.
      - vitest, mutation-checked

## 4. Tests

- [x] 4.1 `tests/e2e/case-type-fields-filter-the-case-list.spec.ts`: pick a
      case type, filter on one of its fields, see the list narrow, and see
      a case that fails the filter absent.
      - The absent case is asserted in every filter test, and both cases are
        asserted PRESENT before the filter is applied. A filter openregister
        does not understand narrows nothing and answers the whole register,
        which looks exactly like a working page to anyone who only checks that
        the case they expected is listed.
      - The teardown names `propertyDefinition` explicitly: it is not in
        `FIXTURE_SCHEMAS`, and a definition left behind is offered as a filter
        on that case type to every handler on the instance, for ever.

## 5. What the first pass got wrong, found 2026-09-20

Both of these were found by asking the code that has to read the
declaration, not by reading the declaration. Neither would ever have
raised an error.

- [x] 5.1 The numbered block was spelled
      `_related[caseProperty][0][case][…]`. openregister's parser reads the
      schema, then the FOREIGN KEY, and only then counts numbered rows, so
      that names a foreign key called `0` with a condition called `case`,
      and `conditionsFor()` refuses the query with "uses operator
      'propertyDefinition'". Read off
      `lib/Service/Query/RelatedRowFilterParser.php` on openregister
      `development`, where the four PRs are now merged. The number moves
      after the foreign key.
      - Mutation checked: putting it back reddens
        `expect(query['_related[caseProperty][case][0][propertyDefinition]'])
        .toBe('pd-1')` with "expected undefined to be 'pd-1'".
- [x] 5.2 🔴 NOTHING EVER REACHED THE SERVER. The bar wrote its blocks onto
      the route query and stopped. `CnIndexPage` turns `$route.query` into
      fetch filters through `resolveQueryFilters()`, which skips every key
      beginning with an underscore, so all of them were dropped and the list
      answered the WHOLE REGISTER under a URL that said it was filtered.
      That is the failure this change's refusal notice exists to prevent,
      arriving by the one door where nothing is refused and so nothing is
      said. Verified against the installed `@conduction/nextcloud-vue`
      3.4.0, asserted in `tests/vitest/declarationsReachTheLibrary.spec.js`.
      - The bar now also hands each block to the list through
        `sidebarState.onFilterChange`, the channel the facet sidebar uses,
        whose keys `useListView.buildParams()` copies into the request
        verbatim. The route query stays: it is what a shared link carries
        and what `readListFilters()` hands a bulk act, and `mounted()`
        replays it so a deep link is filtered rather than merely addressed.
      - Mutation checked: dropping the `sendToList` call reddens "the route
        query alone is dropped by resolveQueryFilters, so a bar that only
        pushes the URL filters nothing".
      - FOLLOW-UP, not taken here: the forwarding rule belongs in
        nextcloud-vue. A `resolveQueryFilters` that let `_related[` through
        would make the second channel unnecessary, and
        `tests/vitest/declarationsReachTheLibrary.spec.js` reddens on the
        release that does it, which is how the next reader will find out.

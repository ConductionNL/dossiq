# Tasks: case-type-fields-filter-the-case-list

Tier: V1. Kind: code. Row 9.2. Consumer half of openregister
`query-related-schema-rows` (#3909, #3916, #3921, #3923, all merged onto
`parity/round2`).

## 1. The declaration

- [ ] 1.1 Measure the contract on openregister `parity/round2` before
      building: the wire shape is `_related[<schema>][<fkProperty>][<op>]`,
      parsed by `lib/Service/Query/RelatedRowFilterParser.php`; two
      conditions in one block are one existence clause and two numbered
      blocks are two; the operators are the six
      `MariaDbSearchHandler::convertToSqlOperator()` accepts plus `in`; a
      malformed block throws rather than being dropped.
      - Write the measured shape into the change's design notes, because a
        filter built against a guessed grammar returns the unfiltered set
        and reads as a working page.
- [ ] 1.2 `lib/Settings/register.d/`: a fragment declaring `filterable` on
      `propertyDefinition`, defaulting false.
      - vitest: a definition with no key is not filterable; the seeded
        definitions that carry it are the ones named in the fragment

## 2. The filter bar

- [ ] 2.1 A per-case-type filter bar component, rendered on the `Cases`
      page only when the folder sidebar has narrowed to one case type.
      - vitest: no case type selected renders nothing; a case type with no
        filterable definition renders nothing; clearing the case type
        clears the field filters with it
- [ ] 2.2 The control per definition follows `propertyType` and
      `enumValues`: number is a range, date is a date range, an enumeration
      is a select, everything else is text.
      - vitest, mutation-checked: swapping the number branch for text
        reddens one assertion and no setup line
- [ ] 2.3 Compile the bar's state to one `_related[caseProperty][case][…]`
      block per filled field, numbered when there is more than one.
      - vitest: two filled fields produce two numbered blocks, never one
        merged block

## 3. Refusal and selection

- [ ] 3.1 A refused `_related` renders through `CaseSearchRefusal` in the
      `after-search` slot, naming the field rather than the raw block.
      - vitest: the hint renders over a refusal and not over an empty result
- [ ] 3.2 `src/utils/selectionScope.js` carries `_related` into a
      whole-result bulk act alongside `_search`.
      - vitest, mutation-checked: dropping `_related` from the carried keys
        reddens the assertion that the job receives the narrowed set

## 4. Tests

- [ ] 4.1 `tests/e2e/case-type-fields-filter-the-case-list.spec.ts`: pick a
      case type, filter on one of its fields, see the list narrow, and see
      a case that fails the filter absent.

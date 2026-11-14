# Design: menu-case-type-counts

## D-1. One facet, not a count per type

A user may be offered thirty case types. Thirty `count()` calls cost thirty
round trips on every load of the settings page, and the
`KpiAggregationService` note records why `count()` is fragile here (it reads
the register and schema context of a shared `ObjectService`). So the service
asks `ObjectService::getFacetsForObjects()` once, with
`_facets: {caseType: {type: terms}}` and the open-work filters, and reads the
buckets: `key` (or `value`) is the case type uuid, `results` (or `count`) the
number. OpenRegister applies the caller's RBAC to the facet, so the number is
what the user could open in the list.

## D-2. Versions fold into the version in use

A case points at the case type VERSION it was opened under. The picker lists
one row per case type, the current version (`supersededBy` empty). The service
already reads every version to find the current ones; it follows each
version's `supersededBy` to the current one (bounded, so a cycle cannot hang
it) and adds that version's bucket to the current row.

## D-3. Open is the dashboard's open

`isFinalStatus`, `statusHiddenInLists` and `isDraft` all 0, the same three
conditions as `KpiAggregationService::OPEN_WORK`, written as 0 and not `false`
for the reason that constant gives.

## D-4. Unknown is not zero

When OpenRegister or the case schema is missing, or the answer has no
`caseType` facet, every `openCases` is `null` and the picker shows no number.
A facet query that throws is not caught in `lib/Service` (the
catch-and-return-null ratchet only goes down): `MenuCaseTypesController`
catches it, logs a warning and answers every `openCases` as `null`. A case type
with no open cases in a facet that did answer is 0, and shows "0 open cases".

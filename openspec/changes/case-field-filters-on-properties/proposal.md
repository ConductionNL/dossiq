# Proposal: case-field-filters-on-properties

## Why

The case list's filter bar on a case type's fields asked OpenRegister for `caseProperty` rows. A case keeps its answers
in its own `properties[]` (`{ propertyDefinition, name, value }`); nothing writes `caseProperty` rows. On :8080, 14 cases
carry answers and there are 0 `caseProperty` rows, so filtering on any case-type field matched nothing (DQ-REINT,
23 Oct).

## What changes

The Cases page's `relatedFieldFilters.related` reads `properties[]` (`element: "properties"`), which the library compiles
to OpenRegister's `_element` filter: one answer that is both the definition and passes the value test. Generic: no
case type or procedure is named. Needs nextcloud-vue feat/r25-related-filters-on-array-elements and openregister
feat/array-element-filters; before them the bar asks a key OpenRegister ignores.

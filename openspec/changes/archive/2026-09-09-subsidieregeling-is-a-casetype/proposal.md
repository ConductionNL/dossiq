# Een subsidieregeling is een zaaktype

## Why

`subsidieRegeling` is a second, parallel definition of the thing `caseType`
already is: the blueprint a category of cases is governed by. Four of its twelve
properties are `caseType` fields under another name, and the app now carries two
places to define "how long do we have to decide", two places to say when a
definition is valid, and two index pages that both administer blueprints.

| `subsidieRegeling` | `caseType` already has |
|---|---|
| `schemeName` | `title` |
| `termStart` / `termEnd` | `validFrom` / `validUntil` |
| `requestTermWeeks` | `processingDeadline` (ISO-8601 duration; AWB 4:13) |
| `legalBasis` | `purpose` (+ `referenceProcess`) |

The other eight are genuinely grant-specific — `plafond`, `targetGroup`,
`beoordelingscriteriaTemplate`, `interimReportFrequency`,
`interimReportTermWeeks`, `determinationTermWeeks`,
`auditorsStatementThreshold` — and belong on the case type as
`propertyDefinition` records, which is exactly what that schema is for
("Custom field definition for a case type", required `caseType`).

Keeping both is the drift hazard this codebase has been bitten by before: two
definitions of one concept, each with its own deadline field, and nothing
forcing them to agree.

## What changes

- **`subsidieRegeling` retires.** A grant scheme becomes a `caseType` plus its
  `propertyDefinition` records.
- **`subsidieAanvraag.subsidyScheme`** re-points from `subsidieRegeling` to
  `caseType`. It is already a UUID reference, so the shape does not change.
- **`propertyType` gains `enum` and `json`.** Without them
  `interimReportFrequency` (a four-value enum) and
  `beoordelingscriteriaTemplate` (a JSON schema) flatten to bare strings and
  lose their constraint — the migration would silently drop validation rather
  than move it.
- **`/subsidieregelingen` and its menu entry go.** Grant schemes are
  administered on the Case types index this change's predecessor restored.
- **Seed data** ships a worked example: one parent scheme, sub-schemes via
  `caseType.subCaseTypes`, and three flows showing the variation.

## Measured before writing this

On a live instance: **2** `subsidieRegeling` objects (`Innovatiefonds 2026`,
`Cultuursubsidie 2026`) and **0** `subsidieAanvraag` objects referencing them.
The data migration is therefore two upserts and a reference rewrite that
currently has nothing to rewrite — the risk in this change is the schema and the
UI, not the data.

That number is worth re-measuring before the migration runs on any instance that
is not this one. A repair step that assumes two rows and finds two thousand is a
different change.

## Out of scope

The five downstream schemas (`subsidieBeoordeling`, `subsidieBeschikking`,
`subsidieUitvoering`, `interimReport`, `subsidieVaststelling`,
`terugvordering`) keep their shape. None of them references the scheme
directly — only `subsidieAanvraag` does — so they are unaffected.

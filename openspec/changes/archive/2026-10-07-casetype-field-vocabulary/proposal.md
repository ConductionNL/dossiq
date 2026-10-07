---
kind: code
depends_on: []
---

# Proposal: casetype-field-vocabulary

Round 4 discovery, depth-study cluster CT-1 "The field vocabulary is eight
types wide and OpenRegister already has twenty"
(`procest/_round4/discovery/build-plan.md` and
`casetype-configurability.md` in ConductionNL/market-intelligence,
2026-09-14). Rows A1, A2, A3, A5, A9, A11, A12, A13, B7 and B10, plus the
`enumValues` defect on A4 and the computed-value exposure of B2. Owner
dossiq, size M, wave 1. No decision blocks it.

The study calls it "the cheapest row-per-hour in the whole study. Ten rows
move on one file."

## Why

An administrator building a case type gets eight field types: `string`,
`number`, `boolean`, `date`, `url`, `email`, `enum`, `json`
(`lib/Settings/dossiq_register.json:629-640`). One layer down, the engine
already validates twenty: `PropertyValidatorHandler.php:44-63` allows
`string, number, integer, boolean, array, object, null, file, geo, color,
recurrence, NcFile, NcMail, NcContact, NcNote, NcTodo, NcCalendarEvent,
NcTalk, NcDeck`, plus `pattern`, `minimum`, `maximum`, `format`, `$ref`
and `items.$ref`.

The wall between the two is one JSON object:
`schemas.case.properties.caseType.x-openregister-extends-form.map`
(`dossiq_register.json:1620-1629`), which forwards exactly eight keys:
`title`, `definition`, `description`, `type`, `maxLength`, `enum`,
`required` and `default`.

So an administrator cannot declare a multi-line text field, a currency
amount, a datetime, a set-valued answer, a document as a value, rich text,
a point on a map, a reference to another case, a pattern, a minimum, a
maximum, or a help text that is labelled as help. Every one of those is
already in the engine.

The depth study rates xxllnc 36 `yes` on 54 capabilities and dossiq 10,
and says where the gap is concentrated: "dossiq scores **zero `yes` on the
thirteen field types**. Group A is the half a demo is made of."

## The candidates and rows this closes

Ten rows of `casetype-configurability.md` group A and B, with the study's
own clause on each:

| row | capability | dossiq today | the clause |
|---|---|---|---|
| A1 | text, single line and multi line | partial | one string type, no single or multi line and no format key to carry one |
| A2 | number: integer, decimal, currency | partial | no integer, decimal or currency split and no minimum or maximum |
| A3 | date, datetime, duration | partial | date only, no datetime and no duration, while case scalars use `format date-time` freely |
| A5 | multi choice | no | `propertyType` has no `array`; a set-valued answer is faked as `json` or a comma string |
| A9 | a document as the value of a field | no | OpenRegister supports `file` and `NcFile`; `propertyDefinition` does not expose them |
| A11 | rich text | no | `propertyDefinition` carries no `format`; OpenRegister has `markdown` and `html` |
| A12 | geo: point, polygon, pick on a map | no | maps and layers are per case type; a geo field cannot be declared, though OpenRegister has type `geo` |
| A13 | reference to another case or catalogued object | no | no `$ref` in the property vocabulary, and `caseObject.objectType` is an unvalidated free string |
| B7 | validation by pattern, range or cross-field rule | partial | `maxLength` only; no pattern, no minimum, no maximum, though OpenRegister validates all of them |
| B10 | help text on the field | partial | the tab edits `definition` only, and neither field is labelled as help |

Two more ride the same file and are named here so nobody opens a second
change for one key:

- **A4, the `enumValues` defect.** The study: "the Properties tab has no
  input for `enumValues`, so choosing `enum` yields a list nobody can
  fill." Confirmed at `src/views/settings/tabs/PropertiesTab.vue`, which
  edits `propertyType` and never `enumValues`. That is a defect, not a
  feature, and it is fixed in the same pass.
- **B2, computed values, cluster CT-3.** OpenRegister ships two engines
  already, `computed.{expression,evaluateOn,dependsOn}` as sandboxed Twig
  and `x-openregister-calculations` as a JSON AST. Neither is reachable,
  because the map forwards neither. D3 picked the JSON AST, so this change
  forwards that one key and nothing else.

## The decision this rests on

CT-1 itself waits on no decision. Two answered decisions land on it:

- **D3**, for the computed half: the JSON AST, not Twig. Verbatim: "the
  JSON AST is auditable, diffable and safe by construction, and a
  functional administrator writes the expression while an auditor reads it
  a year later."
- **D2**, for the registry half: a registry-backed field is a small type
  plus a declared source. The source key is openregister's and the
  resolvers are integriq's (`registry-backed-field-source`, integriq
  wave 1). This change forwards the key so a case type can declare it; it
  does not resolve anything.

**D6 was answered relevance-led**, so every `must` candidate enters the
corpus whatever its passer count. CT-1 is rated from a depth study rather
than from passer counts, so nothing in this change changes on that count.
**D17 was answered for a broad market**: none of the twenty `not`
candidates is in CT-1.

## What changes

- `propertyDefinition.propertyType` widens from eight values to the
  vocabulary the engine already validates.
- `propertyDefinition` gains the keys that carry the rest: `format`,
  `pattern`, `minimum`, `maximum`, `itemsType`, `ref`, `helpText` and the
  computed expression.
- The `x-openregister-extends-form.map` forwards every one of them, in the
  same pass, because a key on the definition that the map does not forward
  is a key that does nothing.
- `PropertiesTab.vue` gains an input per key, including the `enumValues`
  input that was never there.
- A property whose type an older instance does not know keeps its stored
  value and is not silently rewritten.

## Ownership

dossiq owns the map and the enum, because both are dossiq's file. The
engine behind every new type is openregister's and is shipped: this change
exposes it and builds none of it. The registry source is
`x-openregister-property-source`, to be specified in openregister, wave 1,
and resolved by integriq `registry-backed-field-source`.

## Capabilities

- Modified: `property-definition-management`: what an administrator can
  declare a case-type field to be.

## Impact

`lib/Settings/dossiq_register.json` (the `propertyDefinition` schema and
the `x-openregister-extends-form.map`),
`src/views/settings/tabs/PropertiesTab.vue`, the case-type form renderer,
Dutch and English strings, and the migration note for a stored value whose
type is no longer in the enum.

## Out of scope

- The rules engine. CT-2, openregister, D3, and dossiq's half is the
  existing change `field-rules-declared`.
- Code lists from a concept scheme. CT-4, and dossiq's half is the
  existing change `code-lists-from-concepts`.
- Resolving a registry source. integriq `registry-backed-field-source`.
- Layout and field order per case type. CT-6, buildiq, D16.
- A table or repeating group, row A10. It is an objecttype reached through
  a reference, not a field type, and the study says xxllnc models it that
  way too.

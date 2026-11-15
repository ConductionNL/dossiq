# Design: case-type-conditional-rules

> Approved by Ruben (24 Oct); being built.

## Stored shape (caseType 1.x, register.d fragment `99-case-type-rules.json`)

```json
"rules": [
  {
    "id": "structural-review",
    "name": "Toets door constructeur bij grote bouwkosten",
    "enabled": true,
    "when": { "event": "saved" },
    "conditions": [ { "kind": "fieldCompare", "field": "bouwkosten", "op": "gt", "value": "100000" } ],
    "actions": [ { "action": "addTask", "taskType": "toets-constructeur" } ]
  },
  {
    "id": "welstand-variant",
    "when": { "event": "created" },
    "conditions": [ { "kind": "fieldEquals", "field": "welstandsgebied", "value": "ja" } ],
    "actions": [ { "action": "chooseVariant", "variant": "Met welstand" } ]
  },
  {
    "id": "refusal-letter",
    "when": { "event": "statusEntered", "status": "<statusType uuid>" },
    "conditions": [],
    "actions": [ { "action": "sendMessage", "emailTemplate": "<emailTemplate id>" } ]
  }
]
```

`conditions` reuses the item schema of `statusType.derivedWhen` (one vocabulary for "a condition on a case"), extended
with `fieldCompare` and `statusIs`. `label` stays: it is what the handler is told while a `requireDocument` is unmet.

## Where each action is evaluated

| action | engine | projected as |
|---|---|---|
| requireDocument | OpenRegister lifecycle | `x-openregister-lifecycle` condition on the states this rule's `when` covers, keyed by statusType uuid, like `CaseStateFieldRuleProjector` |
| setField (required/value) | OpenRegister schema rules | a rule with the condition (plus `caseType == <uuid>`) and a `setValue` action |
| sendMessage | OpenRegister notifications (ADR-031) | `x-openregister-notifications` entry with trigger, condition, template |
| addTask | OpenRegister flow (no create action in the rule vocabulary) | TriggerObjectNode → FilterNode (conditions + caseType) → ObjectWriteNode create, identifier = rule id + case id (+ trigger version where it re-fires), `onConflict: fail` |
| chooseVariant | case creation | read by the create path that picks the default variant today |

The projector is one service, `CaseTypeRuleProjector`, run where `CaseStateFieldRuleProjector` runs (publish), merging
per case type and dropping what this type no longer declares.

## UI

- The Rules tab: the type's rules as record-list rows, title "Als {condition}" and line "dan {action}" written from
  the rule (no free text needed), an On or Off pill, and a row menu (Edit, Turn off, Delete). Then the calculated fields
  from case-type-rules-as-rows.
- "Regel toevoegen": a form with When, Conditions (field from this type's own fields, operator by field type, value),
  Actions (by kind, each pointing at this type's own tasks, variants, documents or templates).
- Everything is manifest config over the library's record-list and sub-objects editor where it can be. The condition
  builder is the one new library piece (a generic "condition over an object's fields" editor), to build in nc-vue.

## Tests

- The projection: a rule set in, schema extension blocks out, with other case types' entries untouched (unit).
- Each action end to end against OpenRegister (integration):
  - a case saved with bouwkosten 150000 gets the task once;
  - a refusal sends the letter;
  - a case in a welstand area gets the variant.
- The Rules tab: rows read from the model, the form writes the model (vitest).

## OpenRegister change: a scoped rule inventory

- A rule descriptor gains an optional `scope: { property, value }`.
- `GET /api/schemas/{schema}/rules?scope[caseType]=<uuid>` returns the descriptors with that scope and those without
  one.
- Calculations and other schema-wide rules carry no scope and are always listed.
- The projector sets the scope on every flow trigger and condition it writes.

## Idempotence key

`<rule id>:<case id>:<trigger version>`:

- trigger version = the case version for `saved`, the statusType uuid for `statusEntered`, `created` for `created`;
- an `addTask` rule uses `<rule id>:<case id>`, so a task is added once per case;
- the key is the created object's identifier, and `onConflict: fail` refuses a second create.

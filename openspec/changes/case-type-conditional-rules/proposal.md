# Proposal: case-type-conditional-rules

## Why

Board DqZaaktypeRegels draws the rules of a case type as sentences a functional administrator writes and reads:

- "Als Bouwkosten groter is dan € 100.000, dan taak Toets door constructeur toevoegen"
- "Als Welstandsgebied ja is, dan variant Met welstand kiezen"
- "Als status Geweigerd wordt, dan bericht Weigering met bezwaarclausule versturen"

each with an On pill and a "Regel toevoegen" button, above the calculated fields (Uiterste datum, Dagen te laat).

Dossiq has no such model. What exists is scattered and partly technical:

- `statusType.derivedWhen` and `statusType.fieldRules` carry conditions in a small vocabulary (`fieldPresent`,
  `fieldEquals`, `documentPresent`), projected at publish onto the case schema's `x-openregister-lifecycle` by
  `CaseStateFieldRuleProjector`.
- `caseType.variants` says "a case picks the default variant, unless a rule picks another", and no rule can.
- Message templates get a moment and a channel (case-type-messages-moment-channel), but no condition.
- DMN decision tables are deprecated in this register (moving to OpenRegister flow-decision-tables).
- The Rules tab lists OpenRegister's case-schema inventory, which is the same for every case type.

Procedures are configuration (Ruben, decision 182): the "if bouwkosten > 100.000 then add the structural review" of a
permit is a line of case type configuration, not code and not a flow someone draws for one condition.

## What changes

- **A rule model on the case type.** `caseType.rules`: an ordered list of `{ id, name?, enabled, when, conditions,
  actions }`.
  - `when`: `created`, `saved` or `statusEntered` (with a `status`).
  - `conditions`: all of them must hold. They use the derivedWhen vocabulary, extended with one comparing kind:
    - `fieldPresent`, `fieldEquals`, `documentPresent`;
    - `fieldCompare { field, op: gt|gte|lt|lte|neq, value }`;
    - `statusIs { status }`.
  - `actions`:
    - `addTask { taskType }` (a plannedActionType or checklist task of this case type);
    - `setField { field, value }`;
    - `chooseVariant { variant }`;
    - `requireDocument { documentType }`;
    - `sendMessage { emailTemplate }`.
- **No evaluator in dossiq.** The publish step projects each rule onto the engine that already owns the action,
  scoped to the case type. This is the same publish-time projection `CaseStateFieldRuleProjector` does for field rules,
  merging per case type and never replacing another type's entries.
  - `requireDocument` and `setField` on a required field become lifecycle field rules or conditions
    (`x-openregister-lifecycle`), which OpenRegister already enforces.
  - `chooseVariant` is read when a case is created, where variants are chosen. A variant is a choice made once, not a
    save-time effect.
  - `addTask`, `setField` and `sendMessage` become OpenRegister schema rules: a rule with a condition and an action of
    the engine's vocabulary (the inventory already carries `condition` + `actions`). `sendMessage` uses the
    `x-openregister-notifications` dialect (ADR-031).
  - Where the engine lacks an action (add task), the action is a flow step the projection adds to the case type's
    flow, with the rule's conditions as its guard. That is the flow-guard route the Woo stages already use.
- **The Rules tab** shows the case type's rules first: "Als … / dan …" rows with an On or Off pill, and "Regel
  toevoegen" opening a form with a condition builder over this type's own fields. The calculated fields follow
  (case-type-rules-as-rows). The trial runs a rule against a case through the engine's existing evaluate endpoint.
- **Versioning.** Rules belong to the case type version: a published version's rules are frozen with it, like its
  statuses.

## Not in scope

- Nested AND/OR groups. The first version is "all conditions hold". An OR is two rules.
- Rules across case types, and rules on objects other than the case.
- DMN. It is deprecated here, and decision tables answer "which outcome", not "what happens next".

## Risks and open questions

1. Can OpenRegister's rule engine scope a schema rule to the objects of one case type (a `caseType` equality in the
   condition) without a rule per case type flooding the inventory? If not, the projection writes one rule per case
   type and the inventory needs a filter by case type. That filter is an OpenRegister change, to build there.
2. Add task: is there an engine action that creates a related object (a task) today, or is a flow step the only route?
   That decides whether `addTask` is a schema rule or a flow step.
3. Re-running: a `saved` rule whose condition stays true must not add the task twice. The projection needs an
   idempotence key (rule id + case) on what it creates.

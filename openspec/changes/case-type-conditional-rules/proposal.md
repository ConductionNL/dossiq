# Proposal: case-type-conditional-rules

> Approved by Ruben (24 Oct); being built. The open questions below are answered (coordinator, 23 Oct)
> from a read of OpenRegister development afdd4f2af3.

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

## Answers to the open questions (23 Oct)

1. **Scoping per case type.** OpenRegister's inventory (`GET /api/schemas/{schema}/rules`, `RulesController::index`)
   takes only `idleDays` and lists every rule of the schema: calculations, state field rules, lifecycle conditions,
   administered validations and flow triggers. It cannot filter by case type. The build therefore adds a generic
   `scope` to OpenRegister: a rule descriptor may carry `scope: { property, value }` (the projector writes
   `{ property: "caseType", value: <uuid> }`), and the inventory takes `?scope[<property>]=<value>`. That returns the
   rules with that scope plus the rules with none, which apply to every object. Built in OpenRegister, generic, with its
   own tests. Dossiq's Rules tab then asks for its own case type's rules.
2. **Add task.** OpenRegister's rule vocabulary has no create action. The actions are setValue, hideField,
   readOnlyField, requireField, refuseTransition, refuseWrite and runFlow (`RuleVocabulary`). Its flow engine does
   create objects: `ObjectWriteNode` with `operation: create`. So `addTask` is a **guarded flow**:
   `TriggerObjectNode` (event `created` / `updated` on the case register and schema) → `FilterNode` holding the rule's
   conditions plus `caseType == <uuid>` → `ObjectWriteNode` `create` of the task, with the deterministic identifier
   from answer 3 and `onConflict: fail`. The projector writes one such flow per rule that adds a task, owned by the
   case type and listed in the inventory as a `flow` rule with the rule's scope. `sendMessage` can take the same route
   (`SendEmailNode` / `SendNotificationNode`) where the notifications dialect cannot express the condition.
3. **Idempotence.** Every effect of a rule is keyed on **rule id + case id + the trigger's version**. The trigger's
   version is the version of the case the event fired on for `saved`, the status entered for `statusEntered`, and the
   case's creation for `created`. A task's identifier is that key, and `onConflict: fail` makes a second run for the
   same key a no-op. A rule that fires again on a later version of the case, with its condition still true, does not
   add the task again either, because a `saved` rule's effect is keyed on the rule and the case alone when its action
   creates something. Only `setField` re-applies per version, which is harmless because it writes the same value.

## Risks

- The `scope` filter is an OpenRegister change, and the dossiq build waits on it (build the dependency there).
- One flow per add-task rule grows the flow list. The flows are owned and named by the case type, so the projector
  replaces them on every publish and removes the ones a version no longer declares.

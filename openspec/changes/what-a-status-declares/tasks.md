# Tasks: what-a-status-declares

Tier: V1. Kind: code. Size M. Rows 2.43, 2.46, 8.25 and 10.18.

The file paths below say where each task LANDED, which is not always where it
was planned: this repo's PHP suite lives under `tests/Unit/`, not `tests/unit/`.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `statusType.derivedWhen`, the
  conditions under which a status is true (D-1). Schema version moved to
  1.3.0, without which the property is inert on every instance that already
  imported the register.
  - `@spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md`
  - Three condition kinds: `fieldPresent`, `fieldEquals`, `documentPresent`,
    written in OpenRegister's `lifecycleCondition` vocabulary so the move is a
    deletion once that kind lands rather than a rewrite.
- [x] 1.2 A status with `derivedWhen` leaves the hand-picked transition list,
  and the case moves into it as the conditions become true (D-1).
  - `lib/Service/Status/DerivedStatusService.php`,
    `lib/Listener/DerivedStatusListener.php`
  - `tests/Unit/Service/Status/DerivedStatusTest.php`
- [x] 1.3 Show the unmet conditions on the case for a derivation that has not
  fired (D-2).
  - `src/components/case/CaseStatusDeclarationPanel.vue`
  - `tests/vitest/caseDerivedStatusReasons.spec.js`
- [x] 2.1 `statusType.waitingOn` with the values `us`, `applicant` and
  `thirdParty`, beside the existing `role` (D-3). Editable on the status form.
  - `tests/Unit/Service/Status/StatusWaitingOnTest.php`
- [x] 2.2 Team and queue counts read `waitingOn`, so a queue reports what is
  ours to move. `case.waitingOn` is materialised by OpenRegister from the
  linked status, so the count narrows server-side.
  - `lib/Service/WorkQueueService.php::countByWaitingOn`
  - `tests/Unit/Service/Status/WorkQueueWaitingCountTest.php`
- [x] 3.1 `statusType.maximumDwell` in working days, armed as an engine timer
  on entering the status and cancelled on leaving it (D-4).
  - `tests/Unit/Service/Status/StatusDwellTimerTest.php`
- [x] 3.2 The breach is its own event with its own notification and filter,
  leaving the term untouched (D-5). The filter is the Stuck lens on the Cases
  index; the notification is the timer's own `slaBreached` rule, carrying
  `status-dwell-verlopen` and `legalEffect: none` so no beslistermijn ladder
  rung fires for it.
  - `tests/Unit/Service/Status/StatusDwellBreachTest.php`
- [x] 4.1 Hold the current dwell and the total per status on the case,
  written as the status changes, counted on the working calendar (D-6, D-7).
  - `tests/Unit/Service/Status/CaseDwellFieldsTest.php`
- [x] 4.2 Sort and filter the work list on the held numbers.
  - `src/components/cells/DwellDaysCell.vue`, Cases index column and Stuck lens
  - `tests/vitest/caseListDwellColumn.spec.js`
- [x] 4.3 `lib/Service/ProcessMining/DwellTimeAnalyzer.php` reads the held
  numbers rather than recomputing its own, so the page and the list cannot
  disagree (D-6).
  - `tests/Unit/Service/ProcessMining/DwellTimeAnalyzerTest.php`
- [x] 5.1 Dutch and English strings for the derivation reasons, the waiting
  values, the dwell breach and the list column. Both catalogues, so no Dutch
  reader falls back to the English key.
- [x] 5.2 `tests/e2e/what-a-status-declares.spec.ts`: a derived status that
  fires and one that explains itself, a queue count by who we wait on, a
  dwell breach inside a healthy term, a work list sorted by dwell.

- [x] 6.1 `lib/Settings/dossiq_register.json`: `statusType.fieldRules`, which
  case fields a status requires, hides and locks, each entry with optional
  groups, an optional condition and the sentence a refusal shows. Schema
  version moved to 1.5.0.
  - `@spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md`
  - The condition reuses the three `derivedWhen` kinds rather than a second
    vocabulary, which is what this change's own residue asked for.
- [x] 6.2 The declarations are published onto the case schema at publish time,
  in the shape OpenRegister's `field-rules-by-state` reads.
  - `lib/Service/Status/StatusFieldRuleDeclaration.php`,
    `lib/Service/Status/CaseStateFieldRuleProjector.php`, called from
    `CaseTypePublishService::publish()`
  - `tests/Unit/Service/Status/StatusFieldRuleDeclarationTest.php`,
    `tests/Unit/Service/Status/CaseStateFieldRuleProjectorTest.php`
  - The state key is the statusType UUID, because that is what `case.status`
    carries. The write merges per state, so publishing one case type cannot
    unpublish another's rules.
  - A condition is translated to JSONLogic, not re-evaluated. `documentPresent`
    is published without a condition rather than as a node reading a property
    nothing writes.
- [x] 6.3 `SchemaAnnotationReconciler` carries the projected `states` forward,
  through `CaseStateFieldRuleProjector::carryForwardStates()`.
  - Without it the next reconcile replaces the lifecycle with the declared one
    and every per-status field rule stops being enforced, in silence.
- [x] 6.4 A property's `requiredAtStatus` is published as a `required` entry of
  that status, so the control that has been in the Properties tab since case
  types shipped is enforced rather than given a rival.
- [x] 6.5 The status form declares the rules: which field, which of the three,
  which groups, an optional condition, and the sentence a refusal shows.
  - `src/utils/statusFieldRules.js`,
    `src/views/settings/components/StatusTypeForm.vue`,
    `src/views/settings/tabs/StatusesTab.vue`
  - `tests/vitest/statusFieldRules.spec.js`
  - `formToStatusType` and `statusTypeToForm` gained `fieldRules` AND
    `derivedWhen`: the second was a live bug, see the residue below.
- [x] 6.6 The case page renders what the platform decided, read off
  `@self.fieldRules` and never recomputed.
  - `src/components/case/CaseStatusDeclarationPanel.vue`
  - `tests/vitest/caseFieldRulesPanel.spec.js`
  - A refused save shows the refusal code's own sentence, through
    `stateFieldRefusal()` in `refusalMessage()`.
- [x] 6.7 A Rules tab per case type lists `GET /api/schemas/{schema}/rules` and
  tries a rule against one case with the trace.
  - `src/services/schemaRules.js`, `src/views/settings/tabs/RulesTab.vue`,
    registered in `CaseTypeDetail.vue`
  - `tests/vitest/caseTypeRules.spec.js`
  - Kinds are labelled from `GET /api/rules/vocabulary`, so a kind added after
    this release renders instead of blanking. Rules are addressed by id, never
    by position.
- [x] 6.8 `tests/e2e/case-types-declare-field-rules.spec.ts`: a required field
  refusing a move, the same move succeeding once filled, `@self.fieldRules` on
  the read, `requiredAtStatus` enforced, the inventory carrying the rule, and a
  trial that writes nothing.

## What was left out, and why

**The count and the breach are on two calendars.** The BREACH is the engine's:
the timer is armed in its own `businessDays` unit over the calendar the
organisation administers, and that is the authority. The COUNT held on the
case is dossiq's `WorkingDayCalculator`, because the engine exposes projection
(`SlaCalculator::add`) and no count between two dates. On an organisation
whose calendar differs from the Dutch national one the two can disagree by a
day. Closing that needs a count operation in openregister
`working-calendar-admin`, which is not specified there yet. Named in the
`StatusDwellService` docblock rather than left for somebody to find.

**A derived move writes no `statusRecord`.** The derivation runs in a
pre-persist listener, so a record written there would survive a save that then
failed and the history would carry a move that never happened. The move is
visible in OpenRegister's own audit trail of the case and in the
`statusDwellTotals` the same write settles, which is what the process mining
page now reads. Putting the record back needs a post-persist listener that
compares the two statuses; that is the shape to reach for the day the case
timeline is asked to show derivations.

**`derivedWhen` still has no authoring surface, and no longer loses its data.**
The conditions are still authored in register JSON. What changed is that they
survive: `formToStatusType` writes back a whitelist, `derivedWhen` was not on
it, and opening a status and pressing Save, or simply dragging a status to
reorder it, wrote the row back without its conditions and the derivation
quietly stopped. It is carried through untouched now and asserted in
`tests/vitest/statusFieldRules.spec.js`. The editor itself is still the right
shape and still unbuilt: a list of rows with a kind, a field and a label,
beside the field-rule editor task 6.5 added rather than as a second one.

**The condition on a field rule is one row, not a group.** `field-rules-by-state`
accepts `and` and `or` over clauses, and this editor writes a single condition.
A second clause is a second row today. Grouping is a UI for an expression
tree and it belongs with the `derivedWhen` editor above, which needs the same
one: building two of them is how the two vocabularies drift apart, which is
exactly what reusing the three kinds was meant to prevent.

**`documentPresent` is stored and not published.** A document hangs off the case
as a related object, and the condition document OpenRegister evaluates carries
only the object, the previous object, the user and the transition. Publishing a
node reading a property nothing writes would make the rule silently never
apply, so the rule is published unconditionally instead. Closing it needs
either a computed property on the case counting its documents, or a condition
operand that can reach a relation, and the second is openregister's.

**No `relatedOpen` condition kind.** "Waiting on advice while an advice
request is open" is a dependency on another object, and that is exactly what
`what-a-transition-declares` builds as a declared obligation. Adding a fourth
condition kind here would be a second mechanism for the same question.

**No Playwright run.** The e2e specs are written and tagged; this lane runs no
browser.

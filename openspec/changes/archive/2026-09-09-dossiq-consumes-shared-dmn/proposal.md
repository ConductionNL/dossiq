# dossiq consumes OpenRegister's shared decision-table evaluator

## Why

openregister#3186 moved dossiq's DMN engine into OpenRegister as the fleet's one
evaluator, taking openbuild's `PRIORITY` and a DMN-correct `ANY` with it. It
deliberately did not retire either app's copy: deleting a working evaluator on
the strength of a new one that had not yet run its data would have been the same
mistake in the other direction.

It has now run its data. This change retires dossiq's copy.

The two classes are the same class. Normalising namespace and class name, the
only differences between `OCA\Dossiq\Service\Dmn\DecisionEngine` and
`OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator` are docblocks and the two
extra hit policies. Both take `evaluate(decisionTable:, inputs:)` and both
return `{outputs, matchedRuleIds, hitPolicy}`. So for every table dossiq ships
today, this is a delete, not a rewrite.

## What it fixes

dossiq's DecisionTable schema offers five hit policies in a dropdown and its own
description admits that two of them are "rejected with
`hit_policy_not_implemented`". So the form has always offered a choice the
engine refuses.

Consuming the shared evaluator makes the enum honest: all five policies work.

One thing this is NOT evidence of. The seeded sample table `Voorbeeld Name 3`
does declare `PRIORITY`, and it is tempting to cite it as a broken seed this
change repairs. Run through the shared evaluator it still fails, with
`type_mismatch`: it is auto-generated placeholder data whose input entries are
the literal string `Voorbeeld Inputentries 3` against a `boolean` column. It
never evaluated under either engine, and it does not evaluate under this one.
The honest claim is narrower: PRIORITY is no longer refused for being PRIORITY.

## What changes

- `DecisionTableController` and `EvaluateDecisionHandler` inject
  `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`.
- `DecisionTableService` reads `UnaryTestEvaluator::VALID_TYPES`.
- dossiq's `DecisionEngine`, `ExpressionEvaluator` and
  `DecisionEvaluationException` are deleted.
- The 31 grammar tests moved to openregister first, in openregister#3191. They
  had to travel before the class did, or the coverage would have left the fleet
  with the file.

## What does not change

Everything dossiq actually owns: input mapping, output mapping onto the case,
the HTTP status map, and refusing to write a case when evaluation fails. Those
keep their tests, now driven against a mocked evaluator rather than a real one,
because the evaluation is no longer dossiq's contract to prove.

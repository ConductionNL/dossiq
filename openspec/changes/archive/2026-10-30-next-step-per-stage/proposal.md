# The What now? card per stage

## Why

DqBezwaar, DqAanbesteding and DqHandhaving open with a "Wat nu?" card. The
case page already draws it per stage (`nextStep`, keyed on the status role),
but the objection's advice stage and the tender's evaluation carried no role,
and the LHS advice page declared no card.

## What changes

- The objection's "Advies uitgebracht" and the tender's "Beoordeling" statuses
  carry the `review` role (bezwaar-beroep 1.4.0, aanbesteding 1.3.0, and the
  register-seeded Bezwaar statuses), so the case page's review card shows.
  No case type gives one role to two statuses.
- The LHS advice page declares `nextStep` keyed on `finalIntervention`: a
  pre-announcement with a two-week period for a view for a penalty order or
  administrative enforcement, the letter for a warning or remedial action.

- A case type can carry its own stage texts (`caseType.nextStep`); the case
  page reads them through nextcloud-vue's `nextStep.stagesFrom` and keeps its
  own for a case type without them.

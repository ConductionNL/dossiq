# A case has goals: what the requester wants to change

## Why
DqZaak and DqZaakMeer draw the Zaakplan card ending in a row "**Doelen** nog geen doelen: wat wil de verzoeker dat er
verandert?" with "+ Doel toevoegen" at its end. dossiq has goals only inside the social domain's family plan
(`casePlanGoal` under a `gezinsplan`, CasePlanGoals). A Woo request, a permit or a complaint cannot carry one, so the
row the board draws has nothing behind it (r34, Zaakplan structure, and every card under it 29px high).

Procedures are configuration and code is generic (decision 182): a goal is not a social-domain thing. The family plan's
goal is already the right shape: a title, what counts as met (`metWhen`), a state, and a close with an observation.

## What changes
- **Schema.** `casePlanGoal` 1.2.0 is the one goal schema. It gets an optional `case` (the case the goal is about);
  `plan` stays for the family plan. `required` drops `plan`: CasePlanGoals refuses a goal that names neither a plan nor
  a case. Title "Goal". The slug stays (stored data is frozen).
- **Service.** CasePlanGoals reads the goals of a case (`ofCase`) as well as of a plan, and saves a goal on either. The
  `metWhen` rule and the close against an observation apply to both.
- **API.** `GET /api/cases/{caseId}/goals` answers `{ results, prompt }`: the case's goals and the case type's
  `goalPrompt`. `POST /api/cases/{caseId}/goals` saves one. Both check that the reader may read the case (write: update
  it) before they touch the store. Closing uses the existing close, after the same case check.
- **Case type configuration.** `caseType.goalPrompt` (string, translatable): the question the empty row asks ("What does
  the requester want to change?"). A case type without it shows no goals row. The Woo types of the example set set it.
- **Page.** The simple case page's Zaakplan card ends with a `case-goals` record list drawn inside the card
  (nextcloud-vue `content.trailWidgetIds`), rows = the goals, and a footer with the bold lead "Goals", the case type's
  prompt read from the same answer (nextcloud-vue `footerAction.note: { path, lead }`) and "Add goal".

## Out of scope
- Goal templates per case type; linking a goal to plan items. Both can follow on the same schema.
- Migrating family-plan goals: they keep `plan` and need nothing.

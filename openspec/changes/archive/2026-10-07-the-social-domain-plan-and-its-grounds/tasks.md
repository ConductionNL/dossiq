# Tasks: the-social-domain-plan-and-its-grounds

Tier: V1. Kind: code. Size L. Rows 5.18 and 14.1.

🔴 NOTHING IN `lib/` READ ANY OF THESE SCHEMAS BEFORE THIS CHANGE. A grep of
`lib/` for `gezinsplan` or `sociaalDomeinAuditLog` returned nothing on
2026-09-18. That is the shape both rows describe: the register is ambitious
and the code behind it is absent, so a plan is a list of strings nobody reads
and an authorisation ground is a declared field nobody writes.

- [x] 1.1 `lib/Settings/register.d/50-sociaal-domein.json`: the
  `intervention` with its goal reference, provider, start and target dates,
  state and outcome (D-1, D-2).
  - `@spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md`

  Two new schemas, `casePlanGoal` and `intervention`, both added to the
  `dossiq` register's schema list. `gezinsplan` gains `reviewDate` and
  `reviews`; `sociaalDomeinAuditLog` gains `subjectBsn` and `returned`
  and an `existence-lookup` action.

  The edit is TEXT SURGERY rather than a re-dump of the file. Loading and
  re-serialising it produced 518 insertions and 68 deletions, all but
  ~90 of them pure reformatting of one-line property objects, which is a
  conflict with every parallel edit to a 1,400-line register file for no
  gain. The semantic diff was checked against `HEAD` both ways round:
  two schemas added, two schemas changed by exactly the named properties,
  `objects` byte-identical.

- [x] 1.2 The goal with the observable thing that counts as met (D-3).
  - `tests/Unit/Service/SociaalDomein/CasePlanGoalTest.php` (7)

  `metWhen` is required ON SAVE, not only in a form: a required field in a
  dialog is a field an API call does not have to send. Closing a goal
  requires an observation, and the refusal QUOTES the metWhen, because a
  required field with no hint of what to compare against is an obstacle
  rather than a guard.

  (The tasks named `tests/unit/...`; this repo's suite is `tests/Unit/`
  and PSR-4 is case sensitive.)

- [x] 1.3 The provider as a party in the platform's contact model, not a
  string (D-2).
  - `tests/Unit/Service/SociaalDomein/InterventionProviderTest.php` (8)

  `intervention.provider` holds a contact reference, spelled the way
  `PersonLinkReader` already reads one, and a value that is neither
  `user:<uid>` nor a uid-shaped string is refused on save. The check is a
  SHAPE test rather than "try the lookup and fall back": a fallback would
  turn every unreachable contact service into a silent pass for a typed
  name, which is the one thing the guard exists to stop.

  An unreachable contact service does NOT refuse the plan. It leaves the
  party unresolved and the stored display name beside it, because
  refusing the whole plan over the contact service would hide the goals
  too.

- [x] 1.4 Migrate `gezinsplan.goals` and `deploymentTrajectories` into goals
  and interventions, keeping every string and marking its origin (D-4).
  - `tests/Unit/Repair/CasePlanStringMigrationTest.php` (7)

  `lib/Repair/MigrateCasePlanStrings.php`, registered post-migration only.
  Two properties pull in opposite directions and both are tested: nothing
  is dropped, and nothing is invented.

  🔑 A MIGRATED GOAL'S `metWhen` IS ITS OWN TEXT. The schema requires one
  and the old shape cannot supply one; repeating the text neither drops
  the goal nor makes something up, and `migratedFrom` says why it reads
  that way. The first review is where somebody writes what would actually
  count.

  🔑 IT IS IDEMPOTENT PER PLAN, NOT PER STRING. Matching on text would
  re-import a goal a consulent has since reworded, because the reworded
  text no longer matches, and the household would end up with both. The
  test edits a migrated goal and runs again.

  🔑 IT WRITES THROUGH THE STORE AND NOT THROUGH `CasePlanInterventions`.
  That service refuses an empty provider, which is right for a person
  filling in a form and wrong for text that predates the field. Dropping
  the trajectory instead would lose the only record of what was agreed.

- [x] 1.5 The plan review: a review date, the reviewer and what changed
  (D-5).
  - `tests/Unit/Service/SociaalDomein/CasePlanReviewTest.php` (6)

  A review with no changes recorded is REFUSED: "reviewed on 3 March by
  A. Jansen" is a tick, and the changes are the only part a later reader,
  or the household, can check the plan against.

  A plan with NO review date is not due. Otherwise every plan written
  before this change is overdue on the day it ships, and a list that is
  wrong on its first day is one nobody reads on its second.

- [x] 1.6 The plan surface on the case: goals with their interventions,
  their providers and their dates, and what is overdue.
  - `tests/vitest/casePlanTab.spec.js` (6)

  `src/components/case/CasePlanSociaalDomeinPanel.vue`, widget
  `family-plan`, DECLARED AND PLACED. A widget with a `widgets[]` entry
  and no `layout[]` entry renders nowhere at all, with no warning: the
  whole front end of this change would be dark and every backend test
  would still be green. The vitest asserts the placement and the absence
  of a grid overlap, and the mutation that deletes the layout entry
  reddens it.

  The vitest asserts NO behaviour of the panel. Overdue, due-for-review
  and the provider party are computed on the server and are tested there.

- [x] 2.1 The existence projection: for a person, whether an open case exists
  in another domain, which domain and the contact. Nothing else (D-6).
  - `@spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md`
  - `tests/Unit/Service/SociaalDomein/CrossDomainExistenceTest.php` (8)

  The answer is built FIELD BY FIELD rather than by filtering a row down,
  which is the difference between a leak that needs a new `unset()` and
  one that cannot happen. The test asserts the keys EXACTLY against
  `CrossDomainExistence::ANSWER_FIELDS`, and the mutation that adds a
  `caseNumber` to the projection reddened that assertion alone.

  🔴 THE BSN KEY IS NOT THE SAME WORD IN ALL THREE DOMAINS. `wmoZaak` and
  `participatiewetZaak` carry `bsn`; `jeugdwetZaak` carries `jeugdigeBsn`,
  because the person a Jeugdwet case is about is the juvenile. Filtering
  all three on `bsn` reads as a clean "no Jeugdwet case exists", never as
  an error, and that is the one wrong answer this lookup must not give:
  it tells a consulent nobody else is working with a household that
  somebody is. The key is declared per domain beside the schema in
  `SociaalDomeinStore::DOMAINS`, and the fixture's Jeugdwet case exists to
  make the failure visible.

  A case with no readable status counts as OPEN, for the same reason.

- [x] 2.2 Require a ground to be chosen before the lookup runs; refuse it
  without one (D-7).
- [x] 2.3 Write the ground, the person, the requester, the moment and what
  was returned to `sociaalDomeinAuditLog`, in the same act as the answer
  (D-8).
  - `tests/Unit/Service/SociaalDomein/SociaalDomeinAuditLogTest.php` (8)

  One file for both, because they are one act. The answer and the log
  entry are written together rather than through an event: a listener
  that is not registered, or that throws, is a lookup nobody can account
  for afterwards, and it looks exactly like a lookup that never happened.

  The grounds are a CLOSED list. An open text field fills up with
  "onderzoek" and answers nobody's question a year later.

  🔴 A LOOKUP THAT FOUND NOTHING IS LOGGED TOO. A log written only on a
  hit hides every fishing expedition, and the person who was searched for
  and not found is the person least likely to ever hear about it.

- [x] 2.4 Answer "what was looked up about me" from that log.

  `CrossDomainExistence::lookupsAbout()`, filtered to
  `action: existence-lookup`: reading a case and asking whether one
  exists anywhere are different acts, and a subject access request has to
  tell them apart. There is a control test for the filter, because an
  implementation returning EVERY log row would pass the main one.

- [x] 2.5 Name the openregister existence-query slug once that lane opens it,
  and keep dossiq's own projection until then.

  See the open ask below. `cross-register-existence-query` does not exist
  in openregister's `openspec/changes` as of 2026-09-18, so dossiq answers
  over the registers it already reads, with the same projection and the
  same log, and the seam is one class.

- [x] 3.1 Dutch and English strings for the plan, the intervention states,
  the grounds and the refusal.

  21 keys in both catalogues, inserted through the repo's OWN comparator
  (`scripts/build-l10n-js.js#sortKeys`). Sorting with Python's default
  produced 843 reordered lines in `nl.json` alone, because that comparator
  is case-insensitive with a code-point tie-break and deliberately not
  `localeCompare`. `node tests/l10n/check-l10n.js` reports both catalogues
  in canonical order and no key missing a Dutch translation.

  The refusal sentences are NOT translated: they come from the server
  through `RefusedException`, which is ADR-050's rule that the sentence
  its author wrote at the throw site is what the reader sees.

- [x] 3.2 `tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts`: a plan
  with two goals and three interventions, an overdue intervention, a review,
  a lookup refused without a ground and one performed with one;
  `openspec validate the-social-domain-plan-and-its-grounds --type change --strict`.

  Seven scenarios. The seams only a live register can show are the two NEW
  SCHEMAS existing at all (an import that missed them gives a 404 no unit
  test sees), `jeugdigeBsn` being a value the store will filter on, and
  `sociaalDomeinAuditLog` accepting `subjectBsn` and `returned`:
  OpenRegister DROPS an undeclared property in silence, so a log row that
  looks written can come back without the two fields the subject-access
  answer depends on.

  `intervention`, `casePlanGoal`, `gezinsplan` and `jeugdwetZaak` were
  added to `FIXTURE_SCHEMAS`, child-first. `sociaalDomeinAuditLog` is
  deliberately NOT swept: the schema says it is append-only and never
  mutated, it is what a subject access request reads, and a suite that
  deletes from it teaches everyone that entries can be removed. The
  fixture uses a documented test BSN for exactly that reason.

## Open asks for openregister

1. **`cross-register-existence-query`** (named in the proposal's Ownership
   section) is not specified in openregister: no change by that slug exists
   under its `openspec/changes` as of 2026-09-18. Until it lands, dossiq
   asks each domain schema in turn through the object service it already
   uses. The whole of that lives in `CrossDomainExistence::lookUp()`, so
   adopting the platform query is one method, and the projection, the
   ground and the log stay where they are because they are dossiq facts.

2. **`row-field-level-security`** is consumed as it stands through
   `sensitive-fields-declared`. This change does not depend on it for the
   existence answer, because the answer never reads the other domain's
   content in the first place: the projection names three fields and the
   row is never carried.

## What this change deliberately does not do

- Row 5.4's 360 view. Purpose limitation between Wmo, Jeugdwet and
  Participatiewet does not allow it, and every field beyond the three is a
  field somebody has to justify. The dialog has no "show more" and never
  will.
- The consent that gates a hand-off to another organisation, which is
  `custody-and-handover-of-a-case` REQ-CST-01.
- The subject access request workflow itself, which is openregister's under
  ADR-047. This change writes the log such a request reads.

# Design: portal-citizen-writes-on-the-case

Read at dossiq `development` `c8ac7427e` and portaliq `development`
`8934e73`.

## Context

- dossiq `lib/Portal/PortalContributionProvider.php:544` `citizenActions()`:
  `createKlacht`, `createBezwaar`, `replyToMessage`, all `type: create`. No
  update action on `case` for any resident audience.
- dossiq schema `case` (`lib/Settings/dossiq_register.json`): `caseType`
  (uuid of `caseType`), `status` (uuid of the case type's `statusType`; "The
  write path validates again"), `description`. No `portalWrites`,
  `portalDocuments`. Schema `caseType`: no portal field.
- dossiq `lib/Listener/PortalClientWriteListener.php` and
  `lib/Portal/ApplicantPortalActs.php`: record `portal.write.client` acts and
  withdrawals (`ACT_WITHDRAWAL`, :73) on the timeline and notify the assignee.
- portaliq, what it reads:
  - `lib/Contribution/CitizenWriteConfigNormaliser.php`: `citizenWrite` only
    on a `type: update` action; required `typeField`, `typeRegister`,
    `typeSchema`; defaults `statusField: status`, `recordField: portalWrites`,
    `documentsField: portalDocuments`.
  - `lib/Contribution/CitizenWriteActionFinder.php:63` `forSubject()`: the
    first update action on the same register and schema with a
    `citizenWrite` block.
  - `lib/Service/CitizenWritableSetResolver.php:107` `resolve()`: the case
    type's `portalWritable` narrowed by the action's own `fields` whitelist
    and the session's audience; `withdrawal()` (:164) reads
    `portalWithdrawal`.
  - The shape of those case type properties is documented on portaliq's
    `portalCaseType` schema: `portalWritable[]` (`field`, `audiences`,
    `openStatuses`, `closedReason`), `portalAmendmentWindow` and
    `portalDocumentWindow` (`openStatuses`, `closedReason`),
    `portalWithdrawal` (`openStatuses`, `closedReason`, `targetStatus`,
    `confirmText`).

## D1. One update action, declared for the resident audiences

`citizenActions()` gains `amendCase`: `type: update`, register `dossiq`,
schema `case`, `scopeField: portalSubject`, `minTrust: low`, `fields`
`['description']`, and
`citizenWrite: { typeField: 'caseType', typeRegister: 'dossiq', typeSchema: 'caseType' }`
with the three defaults. It is served wherever `mijnZaken` is served
(`portal-case-list-declarations` D1: `client`, `citizen`, `supplier`).

`fields` is the ceiling: whatever a case type opens, portaliq narrows it to
this list. `description` is the resident's own account of what they asked;
nothing a handler decides (status, result, deadlines, assignee) is on it.

## D2. The record of the resident's writes lives on the case

`case` gains `portalWrites` (array, read only for staff forms) and
`portalDocuments` (array of file references) in
`lib/Settings/register.d/76-portal-citizen-writes.json`. portaliq writes both;
dossiq renders `portalDocuments` in the case's Files tab through the existing
files leaf, and the timeline already carries each write through
`ApplicantPortalActs`.

## D3. The case type decides what is open and until when

`caseType` gains, in the same fragment, `portalWritable`,
`portalAmendmentWindow`, `portalDocumentWindow` and `portalWithdrawal` in
exactly portaliq's documented shapes. Statuses are the case type's own
`statusType` uuids, which is what `case.status` holds. The case type editor
gets a "Portal" section: a checkbox per field of D1's ceiling with its open
statuses, the two windows, and the withdrawal (open statuses, the status it
lands on, the sentence when closed, the confirmation text).

## D4. A withdrawal lands on a status the workflow can reach

A resident's withdrawal is a status write portaliq makes; dossiq's case write
path validates status moves. A pre-save guard on `caseType` refuses a
`portalWithdrawal` whose `targetStatus` is not a status of that case type, or
is not reachable by a transition of its workflow from each of its
`openStatuses`, with the sentence naming the status. So a withdrawal the
portal offers can always be written.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `amendCase` and its `citizenWrite` | Declarative, the contribution manifest | Data portaliq reads. |
| The case type's portal properties | Declarative, a register fragment | Properties authored per case type. |
| The withdrawal target check | Imperative, a pre-save guard on `caseType` | Depends on the case type's workflow. |

## Seed data

Case type "Melding openbare ruimte": `description` writable for `client`
while the status is Ontvangen; documents accepted while Ontvangen or Aanvullen;
withdrawal while Ontvangen, onto Ingetrokken, with "Uw melding is al in
behandeling en kan niet meer worden ingetrokken." when closed.

## Risks

- **A case type opens too much.** The action's `fields` list is the ceiling,
  and it holds only `description`.
- **A second update action later.** portaliq takes the first update action
  with `citizenWrite` on `case`; a provider test asserts there is exactly one.

---
kind: code
depends_on: []
---

# Proposal: custody-and-handover-of-a-case

## The rows this closes

**2.37**, area Case core, rated `partial`: "Dated chain of custody of case
ownership between organisation units."

Source field, verbatim: `dossiq#2314, published as 2.31`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.37** | 2.31 | Dated chain of custody of case ownership between organisation units | partial | unread | discovery D-freescout-2 |
```

The ledger note, verbatim:

> CaseTransferService moves a case between organisations and writes an audit entry. There is no dated ownership history you can read back as a chain, so who held the case in March is not a query.

**2.38**, area Case core, rated `no`: "Request a case from its current
handler, who accepts or refuses with a reason."

Source field, verbatim: `dossiq#2314, published as 2.32`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.38** | 2.32 | Request a case from its current handler, who accepts or refuses with a reason | no | unread | corpus 2.4 |
```

The ledger note, verbatim:

> CaseTransferService only pushes a case away. Nothing lets a colleague pull one, and there is no accept or refuse with a reason on the holder's side.

**13.28**, area Access and privacy, rated `no`: "Hand-off refused until
consent is recorded, with the sharing scope chosen."

Source field, verbatim: `dossiq#2314, published as 13.21`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.28** | 13.21 | Hand-off refused until consent is recorded, with the sharing scope chosen | no | unread |  |
```

The ledger note, verbatim:

> toestemming is declared in the sociaal domein register and the only toestemming hits in lib/ are Dutch error strings. CaseTransferService::initiateTransfer has no consent branch and createPartnerShare writes a share with no scope, so nothing is ever blocked.

## What the competitor evidence is

None for any of the three. All three are among the 98 rows promoted under
decision D1, and the batch file says it in as many words: "Every competitor
column is `unread`, and none of them is `no`. ... `no` is a reading of a
product somebody opened, and filling these cells with it would fabricate
thirty readings per row."

Two carry cross-references, and a cross-reference is a neighbouring
question, not a reading. Row 2.37 points at round 4 discovery candidate
D-freescout-2, row 2.38 at corpus row 2.4, which dossiq opened as
`case-claim-action` for the unassigned case.

## Why

A case changes hands constantly and dossiq records almost none of it.

**Who held it, and when.** `CaseTransferService` moves a case and writes an
audit entry. The audit answers "what changed" for a reader who already knows
when to look. It does not answer "who held this case in March", which is the
question a complaint, a WOO request or an internal review actually asks. A
chain of custody is a list of dated holdings, and reading one out of a diff
log is not the same thing as having one.

**Pulling instead of pushing.** Everything moves only from the holder
outwards. A colleague who should take a case over, because the holder is ill
or because the case belongs to their area, cannot ask for it.
`case-claim-action` covers the unclaimed case, correctly, and stops there: a
case somebody holds needs that person's answer, and an answer includes a
reason when it is no.

**Consent before the file leaves.** `toestemming` is declared in the sociaal
domein register and nothing reads it. `CaseTransferService::initiateTransfer`
has no consent branch and `createPartnerShare` writes a share with no scope.
So a Wmo file can be handed to a partner organisation with no recorded
consent and no limit on what the partner sees. That is the one row of the
three that is a compliance defect rather than a missing convenience.

## What changes

- A `caseCustody` record per holding: the organisation unit, the handler
  where there is one, from when, until when, the reason for the move and who
  made it. Every transfer closes the open holding and opens the next, so the
  chain is complete by construction rather than by care.
- "Who held this case on a date" and "which cases did this unit hold last
  quarter" become queries over that record.
- A takeover request: a colleague asks the holder for the case, the holder
  accepts or refuses, and a refusal carries a reason. The request reaches the
  holder as an engine task, and the answer is recorded on the case whichever
  way it goes.
- An unanswered takeover request escalates to the unit that holds the case
  rather than expiring in silence.
- A hand-off that crosses an organisation boundary is refused until a
  `toestemming` record covers it. The consent names the scope: which
  categories of the file the receiving organisation may see, and for how
  long.
- The share the hand-off creates carries that scope, so the refusal and the
  share cannot disagree.

## Ownership

dossiq builds the custody record, the takeover request and the consent gate.
Who held a zaak, and whether a zaak may leave the organisation under the Wmo
or the Jeugdwet, is case administration.

Consumed:
- openregister organisation model and audit trail (shipped) for the unit
  identities and the attribution of each move, so dossiq writes no second
  history;
- openregister engine tasks (shipped) for the request that reaches the
  holder;
- openregister `row-field-level-security` (spec) for what the receiving
  organisation may read once the share exists, as dossiq's
  `sensitive-fields-declared` already consumes it;
- openregister `share-scope-on-an-object`, to be specified in openregister,
  for enforcing the recorded scope on the share itself. Until it lands,
  dossiq refuses the hand-off without consent and writes the scope onto the
  share it creates, which is the half that is dossiq's either way.

## ADRs

- Company ADR-022: the organisation, the share and the audit are the
  platform's. dossiq records the holding and decides the refusal.
- Company ADR-023: the takeover answer is an action, and who may accept or
  refuse on behalf of a unit is an action mapping rather than a hardcoded
  group check.
- Company ADR-031: the consent requirement per case type is declared on the
  schema, so an administrator can read which case types may not leave the
  organisation without one.
- Company ADR-047: the AVG request workflow is openregister's. This change
  stays off it and only refuses a hand-off, which is dossiq's own act.
- Company ADR-055: a hand-off accepts an actor other than the caller, so the
  create-time actor is validated rather than trusted.

## Size

M. Three records and one refusal, no new mechanism.

## The existing spec this extends

`case-management` for the custody and the takeover, and
`dossiq-sociaal-domein-avg-consent` for the consent gate, which already
carries the classification and the wijkteam guard this refusal sits beside.

## Out of scope

- Claiming an unassigned case, which is `case-claim-action` REQ-CM-33 and
  REQ-CM-34.
- Substitution and holiday cover, which is `handler-vervanging-waarneming`
  and `substituted-work-reaches-my-work`.
- The subject access request and the erasure workflow, which are
  openregister's under ADR-047.

# Design: simple-case-page

## D-1 The stage is `statusRole`

`case.status` is a uuid that points at a status type of the case's own case
type. No two case types share it, so it cannot key a page. `statusType.role`
says what a status means in any process: `intake`, `pending-info`,
`in-progress`, `review`, `closed` or `stranded`.

`statusRole` copies it onto the case:

```json
"statusRole": { "type": "string", "materialise": true, "expression": { "prop": "@ref.statusType.role" } }
```

It is not coalesced to a default. `waitingOn` falls back to `us`, because a
queue must count every case. A stage must not: a case type nobody annotated is
not "in handling", it is unknown, and the page says so by showing nothing extra.

## D-2 Stage to button

| role | button | what it is today |
| --- | --- | --- |
| `intake` | Claim | header action `case-claim` |
| `pending-info` | Remind | header action `case-remind` |
| `in-progress` | Next step | opens `CaseLifecycleMenuDialog` |
| `review` | Next step | opens `CaseLifecycleMenuDialog` |
| `closed`, `stranded`, none | no stage button | Lifecycle is first in More |

When Claim is hidden by its own condition (the case has a handler), the library
shows no stage button and Lifecycle stays in the menu.

## D-3 Checklists

| role | item | done when |
| --- | --- | --- |
| intake | Choose a handler | `assignee` is set |
| intake | Complete the case details | `isIncomplete` is not true |
| pending-info | The applicant has answered | `waitingOnApplicant` is not true |
| in-progress | the three items above | as above |
| review | Write the decision document | `besluitDocument` is set |
| review | Record the decision | `decisions` is not empty |
| closed | Record the result | `result` is set |
| closed | Archive the case | `@self.archived` is set |

## D-4 Where the 25 actions go

- Quick (3): `send-digital-post`, `generate-document`, `log-contact`.
- Top of More (1): `case-lifecycle-menu`.
- Case (15): claim, release, hand over, add party, link object, record receipt
  confirmed, plan follow-up, remind, start, split, merge, copy, change type or
  version, move to another version, known in another domain.
- Publication (3): publish, withdraw, view the publication.
- Dossier (1): export dossier.
- Admin only (2): inspect raw data, inspect flow runs.

The simple structure regroups actions. It does not change who may use one.
`adminOnly` is set only on the two inspect actions, which were already gated on
the admin probe. The design also lists change type or version, move to another
version and known in another domain as admin actions. Today a handler may use
them (the first through its own permission endpoint), so they stay in the Case
group with the gates they have.

## D-5 The overlay

The page is an overlay in `src/menu-layout.simple.json`. `config` sets the new
keys. `configPatch` adds `group` or `adminOnly` to an action by its id and
replaces the content of the `case-panels` widget. Nothing in `src/manifest.json`
changes, so the full structure cannot drift.

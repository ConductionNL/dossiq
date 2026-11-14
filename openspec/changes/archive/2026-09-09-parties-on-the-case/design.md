# Design: parties-on-the-case

## Context

`role` (`lib/Settings/dossiq_register.json`, slug `role`, version 1.1.0)
carries `name`, `roleType` (`$ref: roleType`), `case` (`$ref: case`,
`onDelete: CASCADE`), `participant` (a Nextcloud user id or a contact
reference), `description`, `delegate`, `delegateFrom` and `delegateUntil`.
`name`, `roleType`, `case` and `participant` are required. The schema sets
`logReads: true` with attribution `zaakafhandeling`, so every read of a role
row is a logged processing event. `roleType` carries `name`, `caseType`,
`genericRole` and `ncGroupId`. `organisatieRol`
(`lib/Settings/register.d/61-mandaat-matrix.json`, register `dossiq`) carries
`roleName` (required), `roleType` (enum bestuurlijk, ambtelijk, extern),
`parentRoleId`, `department`, `team` and `mandateLevel`.
`medewerkerRolToewijzing` maps a `userId` to a `roleId` over a validity
window.

`case.assignee` is `{"type": "string", "referenceType": "nextcloud-user",
"facetable": true}`. `caseTask.assignee` is a plain facetable string.

Pages touched, by manifest id: `CaseDetail` (widget `case-panels`, type
`tabs`, ten children; `headerActions` with `log-hours`), `Cases` (index over
`case`, `columns`, sidebar) and `Tasks` (index over `caseTask`). The
`object-list` widget type already renders `case-tasks` and `case-sub-cases`
with `filter: {"case": "@objectId"}`. nextcloud-vue 2.40.0 resolves `@me` and
date tokens in `quickFilters` (`{label, filter, default?}`) and nothing else.

ADR-032 kind: **config**. Every change is a schema property or a manifest
entry. No component, no controller.

## Goals / Non-goals

**Goals:**

- You see everyone on the case with their role, their delegate and how long
  the delegation runs.
- You add a person with a role from the case page, and the case is already
  filled in.
- You assign a case or a task to a team, and you find your team's work on the
  index.

**Non-goals:**

- The requester card (`requester-on-the-case`).
- Routing a transition to a group (`role-routing-via-or-rbac` does that
  through `roleType.ncGroupId`).
- Substitution resolution (`handler-vervanging-waarneming` owns the
  `delegate` window semantics; this change only shows the fields).
- A masked identifying number per party. `participant` is a user id or a
  contact reference, not a BSN.

## Decisions

### D1: the Parties tab is an `object-list` over `role`, not the admin `RolesTab`

`RolesTab.vue` is an admin surface over every role in the register. The case
page needs the rows of one case. A new widget `case-roles`, type
`object-list`, `register: dossiq`, `schema: role`, `filter: {"case":
"@objectId"}`, `sort: {field: roleType, dir: asc}`, `limit: 50`, columns
`roleType` (Role), `participant` (Participant), `delegate` (Delegate),
`delegateUntil` (Delegate until), `emptyText: "No parties on this case yet"`.
It joins `case-panels.content.tabs` as `{"widgetId": "case-roles", "label":
"Parties"}` before Contacts, and stays out of `layout` like its siblings. The
Contacts tab (`case-contacts`) goes: it is the inert integration leaf triage
#2 describes, and the Parties tab is what it was standing in for.

`roleType` is a `$ref`; the column shows the referenced row's `name` once
`CnIndexPage` renders label fields for `$ref` columns (triage #8, Tier D06).
Until then it shows the uuid, the same interim `requester-on-the-case` D5
accepts for the list. The e2e asserts the participant column, not the role
label.

### D2: Add party is a header action, prefilled through `props`

Two ways to open a create form with the case filled in:

1. `showAdd` on the `object-list`, which opens the schema's form with no
   initial data. The handler would type the case uuid by hand.
2. A header action of type `open-form` over `role` with `props: {"case":
   "@objectId"}`, the shape `log-hours` uses for `domainObjectRef`.

Option 2 ships. `add-party` sits in `CaseDetail.headerActions` with label Add
party, icon `AccountPlusOutline`, `successMessage: "Party added to the
case."`. The form renders `roleType` as a select over the role types of the
case's type, which `role.roleType` already gets from the `x-relation-filter`
REQ-ROLE-002 relies on. When `CnObjectListWidget` passes its filter as initial
data, the action moves onto the tab and the header entry goes; that is the
blocked task.

### D3: `assignedGroup` and `assigneeGroup` are `$ref: organisatieRol`

`organisatieRol` is the fleet's team noun: it carries `department` and `team`,
and `medewerkerRolToewijzing` says who is in it. A Nextcloud group id was
considered and rejected: `roleType.ncGroupId` already binds roles to groups
for authorization, and a second group field on the case would blur assignment
with permission. The properties:

| schema | property | shape |
|---|---|---|
| `case` | `assignedGroup` | `{"type": "string", "format": "uuid", "$ref": "organisatieRol", "title": "Team", "facetable": true, "order": 6}` |
| `caseTask` | `assigneeGroup` | `{"type": "string", "format": "uuid", "$ref": "organisatieRol", "title": "Team", "facetable": true}` |

Both optional. `assignee` keeps its meaning: the one person working the case.
A case with a group and no assignee is unclaimed team work. The names differ
on purpose: `case` already pairs `assignee` with the past participle pattern of
`assignedGroup`, and `caseTask` reads as `assignee` plus `assigneeGroup`; both
names come from `placement.md` row A19 and stay as written so the index
filters and the Pipelinq bridge can rely on them.

### D4: Mine ships, Team waits, the facet is the interim

`quickFilters` on `Cases` and `Tasks` gains `{"label": "Mine", "filter":
{"assignee": "@me"}}`. The Team chip needs the signed-in handler's
`organisatieRol` ids, which come from `medewerkerRolToewijzing` rows with
`userId = @me` inside their validity window. No fetch-time token expresses
that, and the chip vocabulary resolves only `@me` and dates. The chip is a
nextcloud-vue need and is listed as blocked. Until then both group properties
are `facetable`, so the sidebar facet lists the teams and a handler picks
theirs, and a saved view keeps the choice. Both indexes gain a Team column
after `assignee`.

### D5: no code for the delegate columns

`delegate` and `delegateUntil` render as plain columns. Whether the delegate
is active now is the resolver's business (`handler-vervanging-waarneming`);
the tab shows the window and lets the reader judge. A computed active marker
would be a `custom` widget and this change is config.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Listing the roles of a case | declarative, `object-list` with `@objectId` | The same shape as `case-tasks`. |
| Adding a role with the case prefilled | declarative, `open-form` with `props` | The platform writes the row; REQ-ROLE-006 validation runs in OpenRegister. |
| Restricting role types to the case type | declarative, `x-relation-filter` on `role.roleType` | Already in the schema (REQ-ROLE-002). |
| Assigning a team | declarative, a `$ref` property | Assignment is data, not a permission. |
| Mine and Team on the index | declarative, `quickFilters` and facets | A filter over a field. |

## Seed data

`lib/Settings/register.d/61-mandaat-matrix.json` gains two `organisatieRol`
rows (Team Vergunningen, department Ruimte; Team Handhaving, department
Ruimte) so the facet and the form select have rows on a fresh instance. Two
of the demo cases in `46-demo-cases-english.json` get `assignedGroup` set to
those rows. The e2e seeds its own case, its own role type and its own role
rows and does not rely on the demo data.

## Risks / Trade-offs

- `role.name` is required and duplicates what `participant` plus `roleType`
  already say. The form asks for it; a default from the picked participant is
  a form feature dossiq does not have. Accepted: the field is short, and
  dropping `required` is a schema change with its own blast radius.
- The `roleType` column shows a uuid until `$ref` columns render labels. The
  tab is still readable through `participant`, and the interim is shared with
  `requester-on-the-case`.
- `logReads: true` on `role` means opening the Parties tab writes one
  processing log row per role. That is the intent of the schema, and the tab
  loads only when opened.
- Two ways to assign: a person and a team. The reassignment bulk action
  (`reassignSelection`) keeps writing `assignee` only; a team reassignment is
  a later change.

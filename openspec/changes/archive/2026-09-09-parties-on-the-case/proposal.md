---
kind: config
depends_on: []
---

# Proposal: parties-on-the-case

Round 2 competitor analysis, rows A03 and A19 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 3 on the
placement ladder for A03 (a tab on an existing widget over an existing schema)
and rung 1 for A19 (a property on two existing schemas, bound to the form and
the index). One noun: everyone involved in the case.

## Why

You cannot see who is involved in a case, and you cannot add anyone. The
`role` schema (`case`, `participant`, `roleType`, `delegate`, `delegateFrom`,
`delegateUntil`) and the `roleType` schema exist, and `RolesTab.vue` reads
them, but only in admin settings. On `CaseDetail` the Contacts tab of
`case-panels` is an inert integration leaf (`dossiq-defect-triage.md` #2) and
no widget lists `role` rows (`_round2/dossiq-baseline/case-detail-anatomy.md`
tab 9). A case knows one person: `assignee`. A task knows one person:
`assignee`. Neither can belong to a team, although `organisatieRol` carries
`department` and `team`, `roleType.ncGroupId` binds a role to a Nextcloud
group, and `caseType.defaultAssignee` already accepts a group id (M1 2.5 and
3.7).

Every competitor lists the parties on the case, and two of three assign work
to a team:

- OpenCase: `opencase/round2/pages/CaseDetail-Participants.md` (Role, Name,
  masked CPR or CVR, Address, Phone, Email; Add citizen, Add company).
- GZAC: `valtimo/round2/pages/CaseDetail-Gegevens.md` (Aanvrager as four JSON
  fields, the weak third) and `valtimo/round2/code-census.md` (candidate-team
  on the case).
- Zaaksysteem: `xxllnc-zaken/round2/pages/Case-Relaties.md` (Betrokkenen:
  Type, Rol, Naam, Gemachtigd; Voeg toe) and
  `xxllnc-zaken/round2/case-detail-anatomy.md` (Afdeling and Rol in the info
  card; Toewijzing wijzigen).
- Dossiq baseline: `_round2/dossiq-baseline/case-detail-anatomy.md` (tabs 2
  and 9 dead) and `_round2/dossiq-baseline/journeys.md` J3.

## What changes

- Widget `case-panels` on `CaseDetail` gains a Parties tab: an `object-list`
  over `role` where `case = @objectId`, columns `roleType`, `participant`,
  `delegate`, `delegateUntil`, sorted by `roleType`.
- A header action Add party on `CaseDetail`: `open-form` on `role` with
  `case` prefilled from `@objectId`. The form offers the role types of the
  case's type, as REQ-ROLE-002 requires.
- Property `assignedGroup` on `case` and `assigneeGroup` on `caseTask`, both
  `$ref: organisatieRol`, optional, facetable. They sit beside `assignee` on
  the forms and as a Team column on `Cases` and `Tasks`.
- Quick-filter chips on `Cases` and `Tasks`: Mine (`assignee: @me`) and Team
  (the signed-in handler's `organisatieRol` rows). Mine ships as config; Team
  waits on a per-user token in nextcloud-vue and the sidebar facet on the
  group field is the interim.
- The inert Contacts tab leaves `case-panels`.
- **BREAKING** for nothing: `assignee` keeps its meaning and every existing
  reader of it. The new properties are optional and additive.

## Interim and durable route

Two pieces belong in nextcloud-vue (`placement.md` section 3). A create form
opened from an `object-list` that carries the list's filter as initial data
(`CnObjectListWidget`, triage #6, Tier D05): until then Add party is a header
action with `props.case = @objectId`, the shape `log-hours` already uses. A
`quickFilters` value that resolves to the signed-in user's `organisatieRol`
rows: until then the Team chip is blocked and the facet on `assignedGroup`
and `assigneeGroup` is where you pick your team. Nothing dossiq writes here is
thrown away when either lands: the tab, the properties and the columns stay.

## Capabilities

### New capabilities

None.

### Modified capabilities

- `roles-decisions`: the case page lists its roles in a Parties tab; you add a
  role from the case with the case prefilled.
- `role-routing-via-or-rbac`: a case and a task can be assigned to an
  `organisatieRol`; the indexes filter on it; the group fields are assignment,
  not permission, so the no-parallel-store rule names them.

## Impact

- `lib/Settings/dossiq_register.json`: `case.assignedGroup`,
  `caseTask.assigneeGroup`; `lib/Settings/dossiq_mock_register.json` follows.
- `src/manifest.json`: page `CaseDetail` (widget `case-roles`, the Parties tab
  in `case-panels`, header action `add-party`, the Contacts tab removed), page
  `Cases` and page `Tasks` (`columns`, `quickFilters`).
- `lib/Settings/register.d/61-mandaat-matrix.json`: no schema change; the demo
  seed gains two `organisatieRol` rows so the facet has something to show.
- E2E: `tests/e2e/case-parties.spec.ts` (new) and
  `tests/e2e/case-detail-kpis-and-tabs.spec.ts` (the tab strip changes).
- No PHP. No effect on `StatusTransitionService` or the reassignment bulk
  action.

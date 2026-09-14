---
kind: config
depends_on: []
---

# Proposal: hours-onto-humaniq-leaf

## Why

Hours belong to humaniq. ADR-107 decision 6 settles it: "Hours logged on a case
are hrmq time entries carrying the case reference." Ownership was settled.
Rendering was not.

The case detail page showed hours anyway, by aggregating humaniq's register from
dossiq's own manifest. The widget `case-kpis-hours` was a `stats-block` whose
single entry read `register: "humaniq"`, `schema: "TimeEntry"`, summing `hours`
over `domainObjectType` and `domainObjectRef`.

On an install without humaniq that endpoint 404s and the tile renders `0`.

`0` is what a real zero renders. A case with no hours booked and a case whose
hours cannot be read are the same pixels. No error, no empty state, nothing a
reader would notice. ADR-113 names that class of defect, and the case detail page
produced four instances of it in one week.

`requiredApp: "humaniq"` was added as the honest form of the tile. It kept the
tile out of installs that could not serve it, and left it reading another app's
register in the installs that could.

humaniq now supplies the surface itself. Its change `hours-leaf-for-any-object`
registers an OpenRegister integration leaf, `humaniq-hours`, that renders the
hours booked against any host object plus the two ways to add more. Its own tasks
name this move as the open half.

## What this change does

The case detail places the leaf and passes nothing.

`case-kpis-hours` becomes `{"type": "integration", "integrationId":
"humaniq-hours"}`, the same shape the `files`, `calendar` and `notes` widgets on
this page already use. The `content.entries` query is deleted with the widget
type, and `requiredApp` goes with it: a leaf whose app is absent is never
registered, so the failure mode stops existing rather than being handled. No
other integration widget in this manifest declares a `requiredApp`.

The widget id does not change, so its layout cell (`gridX: 8`, `gridY: 8`,
4 by 2, `showTitle: false`) still resolves and the page grid is untouched.

The host object context is derived rather than declared. The host forwards
`register`, `schema` and `objectId` to every leaf it mounts, and `CaseDetail`
carries `dossiq` and `case`, so the leaf builds `domainObjectType` as
`dossiq:case`, which is the literal humaniq documents for a host object.

## What this change does not do

It does not ship the leaf. That is humaniq's `hours-leaf-for-any-object`, and
until its bundle lands the placement renders nothing on this page.

It does not add a second way to book hours. The `log-hours` header action is
removed here rather than left beside the leaf. It was an `open-form` over
`humaniq`/`TimeEntry` that wrote a time entry and read none, so ADR-113 never
applied to it, but the leaf's Book hours dialog writes the same entry from the
same case and sits beside the total those hours land in. Two booking paths on one
page is one more than a reader needs, and the header one was the further from the
figure it changes.

## Capabilities

### New capabilities

- `case-hours-via-humaniq-leaf`: hours on a case are a placement of humaniq's
  `humaniq-hours` leaf, and dossiq's manifest holds no query against humaniq's
  register.

### Modified capabilities

_None._

## Impact

- **Frontend:** `src/manifest.json` only. One widget definition on `CaseDetail`.
  No `layout` change, no Vue component, no PHP, no schema, no register version.
- **Cross-repo:** humaniq `openspec/changes/hours-leaf-for-any-object` owns the
  leaf. Nothing in that change is duplicated or modified here.
- **Blocked on:** the `humaniq-hours` bundle shipping. The PR stays a draft until
  then, because the placement cannot be verified against a leaf that is not
  registered.
